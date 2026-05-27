<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\DataHubBundle\Service;

use Pimcore\Bundle\DataHubBundle\Lock\LockFactoryResolver;
use Pimcore\Bundle\DataHubBundle\Lock\LockSignalRefresher;
use Pimcore\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class PersistentOutputCacheService
{
    // Tag/key names use '_' instead of ':' — PSR-6 reserves '{}()/\@:' and
    // CacheItem validation throws on any of them in either a key or a tag.
    public const TAG_COMMON = 'datahub_graphql_persistent';

    /** Request attribute carrying the acquired cold-miss lock (or absent when no lock held). */
    public const REQUEST_ATTR_COLD_MISS_LOCK = '_datahub_swr_cold_miss_lock';

    /**
     * Dedicated tag for the singleton invalidation watermark; kept separate from
     * TAG_COMMON so a blanket clear of cached entries by TAG_COMMON does not
     * collaterally reset the watermark (which would make every cached entry
     * look FRESH until the next mutation event).
     */
    public const TAG_WATERMARK = 'datahub_graphql_persistent_watermark';

    public const TAG_OP_PREFIX = 'datahub_graphql_op_';

    public const TAG_CLIENT_PREFIX = 'datahub_graphql_client_';

    /** Per-object cache tag (one per `<class>:<id>` recorded during the GraphQL request). */
    public const TAG_OBJECT_PREFIX = 'datahub_graphql_obj_';

    /** Per-class cache tag (one per distinct element class recorded). */
    public const TAG_CLASS_PREFIX = 'datahub_graphql_class_';

    /**
     * Reverse-index prefix mapping a per-object or per-class tag back to the
     * `<payloadKey, metaKey>` pairs that depend on it. The invalidation
     * listener iterates this index instead of INDEX_ALL when per-object
     * tagging is engaged.
     */
    public const REVERSE_INDEX_PREFIX = 'taginx_';

    public const KEY_FALLBACK_WATERMARK_TS = 'datahub_graphql_fallback_watermark_ts';

    public const PAYLOAD_KEY_PREFIX = 'persistent_output_payload_';

    public const META_KEY_PREFIX = 'persistent_output_meta_';

    public const ENQUEUE_DEDUPE_PREFIX = 'datahub_enqueue_req_';

    /**
     * Sibling to ENQUEUE_DEDUPE_PREFIX, keyed by entryHash(). Set by the
     * listener when an invalidation coalesces against an in-flight dispatch;
     * cleared by the worker, which fires a trailing refresh when the flag was
     * set during processing.
     */
    public const PENDING_REFRESH_PREFIX = 'datahub_pending_refresh_';

    /**
     * Per-entry trailing-edge cooldown sentinel for invalidation throttling,
     * keyed by entryHash(). Armed by the invalidation listener when it
     * schedules a dated refresh for a cooldown-configured operation; cleared
     * by the worker after that refresh lands. While present, further
     * invalidations of the same entry are suppressed (a dated refresh is
     * already queued for the window).
     */
    public const KEY_OP_COOLDOWN_PREFIX = 'datahub_graphql_op_cooldown_';

    public const INDEX_ALL = 'datahub_graphql_persistent_index_all';

    public const INDEX_OP_PREFIX = 'datahub_graphql_persistent_index_op_';

    public const INDEX_CLIENT_PREFIX = 'datahub_graphql_persistent_index_client_';

    /** Soft cap on per-index entry count; prune dead entries before growing past this. */
    private const MAX_INDEX_SIZE = 5000;

    private bool $enabled = false;

    private int $ttl; // seconds

    private int $payloadTtl = 86400;

    private ?OperationClassifier $operationClassifier;

    private LockFactoryResolver $lockFactoryResolver;

    private ?DependencyCollector $dependencyCollector;

    private readonly ?LoggerInterface $psrLogger;

    public function __construct(
        ContainerBagInterface $container,
        ?OperationClassifier $operationClassifier = null,
        ?LockFactoryResolver $lockFactoryResolver = null,
        ?DependencyCollector $dependencyCollector = null,
        #[Autowire(service: 'monolog.logger.pimcore')]
        ?LoggerInterface $psrLogger = null,
    ) {
        $cfg = $container->get('pimcore_data_hub');

        $graphql = $cfg['graphql'] ?? [];
        $this->enabled = (bool)($graphql['persistent_output_cache_enabled'] ?? false);
        $ttl = $graphql['persistent_output_cache_lifetime'] ?? null;
        if ($ttl === null) {
            $ttl = $graphql['output_cache_lifetime'] ?? 30;
        }
        $this->ttl = max(1, (int)$ttl);
        $this->payloadTtl = max(1, (int)($graphql['persistent_output_cache_payload_ttl'] ?? 86400));
        $this->operationClassifier = $operationClassifier;
        $this->lockFactoryResolver = $lockFactoryResolver ?? new LockFactoryResolver();
        $this->dependencyCollector = $dependencyCollector;
        $this->psrLogger = $psrLogger;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Pre-request hook.
     *
     * - HIT/FRESH:  returns the cached response (controller short-circuits).
     * - HIT/STALE:  returns the stale cached response AND marks request attributes
     *               so PersistentCacheRefreshOnTerminateListener kicks off a
     *               background refresh after the response is flushed.
     * - MISS:       returns null (controller proceeds to execute GraphQL).
     */
    public function preHandle(Request $request, ResponseServiceInterface $responseService): ?JsonResponse
    {
        if (!$this->shouldUseForRequest($request)) {
            return null;
        }
        // Mark that persistent cache applies for this request (used to optionally skip standard output cache)
        $request->attributes->set('_datahub_persistent_applies', true);
        // Skip interception when running a background refresh after response
        if ($request->attributes->get('_datahub_persistent_refresh')) {
            return null;
        }

        // Load meta + payload using sidecar keys
        [$client, $canonical] = $this->clientAndCanonical($request);
        $metaKey = $this->keyMeta($client, $canonical);
        $payloadKey = $this->keyPayload($client, $canonical);

        $meta = $this->cacheLoad($metaKey);
        $payload = $this->cacheLoad($payloadKey);

        if (($meta === false || $meta === null) || ($payload === false || $payload === null)) {
            return null; // MISS
        }

        $now = time();
        $fallbackWatermark = (int) ($this->cacheLoad(self::KEY_FALLBACK_WATERMARK_TS) ?: 0);
        $refreshedAt = (int)($meta['refreshedAt'] ?? 0);
        $isStale = $this->isEntryStale($meta, $fallbackWatermark);
        $response = new JsonResponse($payload);
        $responseService->addCorsHeaders($response);

        if ($isStale) {
            // Serve stale immediately and schedule a background refresh on kernel.terminate
            $response->headers->set('X-Pimcore-DataHub-Persistent-Cache', 'STALE');
            $response->headers->set('Warning', '110 - "Response is Stale"');
            // mark request for background refresh
            $request->attributes->set('_datahub_persistent_refresh', true);
            $request->attributes->set('_datahub_persistent_meta_key', $metaKey);
            $request->attributes->set('_datahub_persistent_payload_key', $payloadKey);
            $request->attributes->set('_datahub_persistent_refreshed_at', $refreshedAt);

            return $response;
        }

        // FRESH HIT: extend TTL and return response immediately
        $response->headers->set('X-Pimcore-DataHub-Persistent-Cache', 'HIT');
        $meta['refreshedAt'] = $now;
        $metaTags = $this->buildTags($request, $meta['operation'] ?? null);

        // Meta TTL rolls forward on every HIT but payload TTL runs from the
        // original write, so a hot query eventually loses its payload while
        // its meta is still alive. Repaint at half-life amortizes the cost.
        $rawSavedAt = $meta['payloadSavedAt'] ?? null;
        $rawTtl = $meta['payloadTtl'] ?? null;
        $payloadSavedAt = is_int($rawSavedAt) ? $rawSavedAt : 0;
        $storedPayloadTtl = is_int($rawTtl) ? $rawTtl : 0;
        if (($rawSavedAt !== null && $payloadSavedAt === 0)
            || ($rawTtl !== null && $storedPayloadTtl === 0)
        ) {
            Logger::warning(sprintf(
                'datahub.swr.hit_repaint_meta_malformed: op=%s payloadSavedAt=%s payloadTtl=%s',
                $meta['operation'] ?? '?',
                var_export($rawSavedAt, true),
                var_export($rawTtl, true)
            ));
        }

        // Mirror savePersistent's full tag set onto both saves when stored
        // tags are present, so meta and payload remain tag-symmetric. Today
        // per-object invalidation goes via the reverse-index -> dispatch by
        // key (not via cache-item tags), so this is consistency hygiene; it
        // would matter if anything ever called Cache::clearTag('obj_*')
        // directly.
        $storedTags = $meta['tags'] ?? null;
        $storedTagsUsable = is_array($storedTags) && $storedTags !== [];
        if (!$storedTagsUsable && $storedTags !== null) {
            Logger::warning(sprintf(
                'datahub.swr.hit_repaint_tags_malformed: op=%s tags=%s',
                $meta['operation'] ?? '?',
                var_export($storedTags, true)
            ));
        }
        $effectiveTags = $storedTagsUsable ? $storedTags : $metaTags;

        if ($payloadSavedAt > 0 && $storedPayloadTtl >= 2
            && ($now - $payloadSavedAt) >= intdiv($storedPayloadTtl, 2)
        ) {
            try {
                $this->cacheSave($payloadKey, $payload, $effectiveTags, $storedPayloadTtl);
                $meta['payloadSavedAt'] = $now;
            } catch (\Throwable $e) {
                Logger::warning(sprintf(
                    'datahub.swr.hit_repaint_failed: op=%s client=%s err=%s',
                    $meta['operation'] ?? '?',
                    $meta['client'] ?? '?',
                    $e->getMessage()
                ));
            }
        }

        try {
            $this->cacheSave($metaKey, $meta, $effectiveTags, $this->ttl);
        } catch (\Throwable $e) {
            Logger::warning(sprintf(
                'datahub.swr.hit_meta_refresh_failed: op=%s err=%s',
                $meta['operation'] ?? '?',
                $e->getMessage()
            ));
        }

        return $response;
    }

    /**
     * Post-request hook. Save fresh response into the persistent (SWR) layer
     * when applicable. Side-effect only — never mutates the outgoing response.
     */
    public function postHandle(Request $request, JsonResponse $freshResponse): void
    {
        if (!$this->enabled || strtoupper($request->getMethod()) !== 'POST') {
            return;
        }
        // Trust the preHandle-set applicability flag to avoid re-parsing the body.
        $applies = (bool) $request->attributes->get('_datahub_persistent_applies');
        if (!$applies && !$this->shouldUseForRequest($request)) {
            return;
        }

        try {
            $this->savePersistent($request, $freshResponse);
        } catch (\Throwable $e) {
            Logger::warning('DataHub persistent cache save failed: ' . $e->getMessage());
        }
    }

    /**
     * Probe the persistent cache status without side effects.
     * Returns array with keys:
     * - applies: bool
     * - status: 'HIT'|'STALE'|'MISS'
     */
    public function probeStatus(Request $request): array
    {
        $applies = $this->shouldUseForRequest($request);
        if (!$applies) {
            return ['applies' => false, 'status' => 'MISS'];
        }

        [$client, $canonical] = $this->clientAndCanonical($request);
        $metaKey = $this->keyMeta($client, $canonical);
        $payloadKey = $this->keyPayload($client, $canonical);

        $meta = $this->cacheLoad($metaKey);
        $payload = $this->cacheLoad($payloadKey);
        if ($meta === false || $meta === null || $payload === false || $payload === null) {
            return ['applies' => true, 'status' => 'MISS'];
        }

        $fallbackWatermark = (int) ($this->cacheLoad(self::KEY_FALLBACK_WATERMARK_TS) ?: 0);
        $isStale = $this->isEntryStale($meta, $fallbackWatermark);

        return ['applies' => true, 'status' => $isStale ? 'STALE' : 'HIT'];
    }

    private function isEntryStale(array $meta, int $fallbackWatermark): bool
    {
        $refreshedAt   = (int)($meta['refreshedAt'] ?? 0);
        $invalidatedAt = (int)($meta['invalidatedAt'] ?? 0);
        $watermarkStale = $fallbackWatermark > 0 && $refreshedAt > 0 && $refreshedAt < $fallbackWatermark;
        $entryStale     = $invalidatedAt > 0 && $invalidatedAt > $refreshedAt;

        return $watermarkStale || $entryStale;
    }

    /**
     * Swallows all failures with a warning — a missed stamp falls back to watermark-only
     * staleness, never a crash.
     *
     * @param array<string, mixed> $meta
     */
    public function stampInvalidatedAt(string $metaKey, array $meta, int $ts): void
    {
        try {
            $meta['invalidatedAt'] = $ts;
            $storedTags = $meta['tags'] ?? null;
            $storedTagsUsable = is_array($storedTags) && $storedTags !== [];
            $tags = $storedTagsUsable ? $storedTags : [self::TAG_COMMON];
            $this->cacheSave($metaKey, $meta, $tags, $this->ttl);
        } catch (\Throwable $e) {
            Logger::warning(sprintf(
                'datahub.swr.stamp_invalidated_at_failed: key=%s op=%s client=%s err=%s',
                $metaKey,
                $meta['operation'] ?? '?',
                $meta['client'] ?? '?',
                $e->getMessage()
            ));
        }
    }

    /** Manually set the last invalidation timestamp to now. */
    public function bumpFallbackWatermark(?int $ts = null): void
    {
        // A caller passing 0 or a negative value almost certainly meant "now",
        // not "the epoch"; an epoch watermark would let `$refreshedAt > 0 &&
        // $refreshedAt < $fallbackWatermark` evaluate to false forever and freeze
        // every cached entry as FRESH.
        if ($ts === null || $ts <= 0) {
            $ts = time();
        }
        $this->cacheSave(self::KEY_FALLBACK_WATERMARK_TS, $ts, [self::TAG_WATERMARK], null);
    }

    /**
     * Drop every persistent-cache entry for this bundle (payloads, meta,
     * indices, per-op/per-client tag indices), bypassing the SWR flow.
     *
     * The `TAG_WATERMARK`-tagged `KEY_FALLBACK_WATERMARK_TS` entry is preserved —
     * clearing it would make every freshly-written entry look FRESH again
     * until the next external invalidation, which defeats the whole point.
     *
     * Use this when `bumpFallbackWatermark()` is not enough — e.g. when the
     * SWR refresh path itself is broken or when errors-only payloads must be
     * evicted immediately.
     */
    public function clearAll(): bool
    {
        return $this->cacheClearTag(self::TAG_COMMON);
    }

    /**
     * Arm the per-entry invalidation cooldown sentinel for $hash with a TTL of
     * the operation's cooldown window. Tagged TAG_WATERMARK (not TAG_COMMON) so
     * an SWR-layer clearAll() preserves it — otherwise a clear would orphan the
     * already-queued dated refresh and let the next edit double-dispatch.
     *
     * @param string $hash return value of entryHash() or entryHashFromBody()
     * @param int    $ttl  cooldown window in seconds (the dated message's deliverAt offset)
     */
    public function armOperationCooldown(string $hash, int $ttl): void
    {
        $this->cacheSave(self::KEY_OP_COOLDOWN_PREFIX . $hash, 1, [self::TAG_WATERMARK], max(1, $ttl));
    }

    public function hasOperationCooldown(string $hash): bool
    {
        $existing = $this->cacheLoad(self::KEY_OP_COOLDOWN_PREFIX . $hash);

        return $existing !== false && $existing !== null;
    }

    public function clearOperationCooldown(string $hash): void
    {
        $this->cacheRemove(self::KEY_OP_COOLDOWN_PREFIX . $hash);
    }

    /**
     * SWR_ONLY cold-miss lock space. Distinct from the herd-guard atomic-lock
     * key (`datahub_inprogress:*`) and from the refresh lock
     * (`datahub_persistent_refresh_lock_*`) — three separate Symfony Lock
     * resources guarding three independent contention surfaces.
     *
     * Returns a lock object on win, null on lose-or-unavailable. Callers MUST
     * treat null as "no lock held"; the cold-miss path falls back to either a
     * bounded-wait poll on the winner's cache write or an inline resolver run
     * after the wait deadline. Either way, the never-503-for-browsers
     * invariant is preserved: this method never raises.
     */
    public function acquireColdMissLock(Request $request, int $lockTtlSeconds): ?object
    {
        $factory = $this->lockFactoryResolver->resolve();
        if (!$factory) {
            Logger::warning(
                'DataHub SWR cold-miss: lock factory unavailable, falling back to inline resolver'
            );

            return null;
        }

        [$client, $canonical] = $this->clientAndCanonical($request);
        $metaKey = $this->keyMeta($client, $canonical);
        $payloadKey = $this->keyPayload($client, $canonical);
        $resource = 'datahub_swr_cold_miss_' . md5($metaKey . '|' . $payloadKey);

        try {
            // autoRelease=true so the Lock destructor releases on graceful PHP
            // shutdown even if the controller's release path doesn't fire.
            $lock = $factory->createLock($resource, $lockTtlSeconds, true);
            if ($lock->acquire(false)) {
                $refreshInterval = max(1, (int) floor($lockTtlSeconds / 2));
                LockSignalRefresher::arm($lock, $lockTtlSeconds, $refreshInterval);
                $this->psrLogger?->info('swr.cold_miss.lock.acquired', [
                    'resource' => $resource,
                    'lock_ttl_seconds' => $lockTtlSeconds,
                ]);

                return $lock;
            }
        } catch (\Throwable $e) {
            Logger::warning(
                'DataHub SWR cold-miss: lock acquire failed: ' . $e->getMessage()
            );
        }

        return null;
    }

    /**
     * Release a previously acquired cold-miss lock. Swallows release exceptions
     * with a WARNING log — a stuck renewer holding a lock past its intended
     * scope is the specific risk this catch-and-log shape guards against.
     */
    public function releaseColdMissLock(?object $lock): void
    {
        if ($lock === null) {
            return;
        }

        try {
            if (method_exists($lock, 'release')) {
                $lock->release();
            }
        } catch (\Throwable $e) {
            Logger::warning(
                'DataHub SWR cold-miss: lock release failed: ' . $e->getMessage()
            );
        } finally {
            LockSignalRefresher::disarm();
        }
    }

    /** Persist a fresh response for the given request. */
    public function savePersistent(Request $request, JsonResponse $response): void
    {
        if (!$this->shouldUseForRequest($request)) {
            return;
        }

        // Refuse to persist transient infrastructure failures (non-2xx, empty
        // body) — caching those for payloadTtl seconds turns a momentary DB or
        // herd-guard hiccup into a persistent outage. GraphQL-level errors
        // co-existing with non-empty `data` (partial success) are still cached:
        // the data is useful and the errors are deterministic against the
        // input. Errors-only responses (no `data`, null `data`, or empty `data`)
        // are NOT cached — they typically come from a transient broken-schema
        // state (orphan class references during deploy, a misconfigured
        // workspace, etc.) and persisting them outlasts the broken window so
        // clients see stale errors even after the upstream is fixed.
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            Logger::warning(sprintf(
                'DataHub persistent cache: refusing to save non-2xx response (status=%d, client=%s)',
                $status,
                (string)$request->attributes->get('clientname')
            ));

            return;
        }
        $payload = json_decode($response->getContent() ?: 'null', true);
        if (!is_array($payload) || $payload === []) {
            Logger::warning('DataHub persistent cache: refusing to save empty or non-array payload');

            return;
        }
        $hasErrors = !empty($payload['errors']);
        $data = $payload['data'] ?? null;
        $hasNonEmptyDataArray = \is_array($data) && $data !== [];
        if ($hasErrors && !$hasNonEmptyDataArray) {
            $messages = array_column((array)$payload['errors'], 'message');
            Logger::warning(sprintf(
                'DataHub persistent cache: refusing to save errors-only response (client=%s, errors=%s)',
                (string)$request->attributes->get('clientname'),
                json_encode($messages)
            ));

            return;
        }
        if ($hasErrors) {
            $messages = array_column((array)$payload['errors'], 'message');
            Logger::warning(sprintf(
                'DataHub persistent cache: caching response with non-empty data array and errors (client=%s, errors=%s)',
                (string)$request->attributes->get('clientname'),
                json_encode($messages)
            ));
        }

        [$client, $canonical] = $this->clientAndCanonical($request);
        $metaKey = $this->keyMeta($client, $canonical);
        $payloadKey = $this->keyPayload($client, $canonical);

        $input = json_decode($request->getContent(), true) ?: [];
        $operationName = (string)($input['operationName'] ?? '');

        $baseTags = $this->buildTags($request, $operationName);
        $collectorTags = $this->collectorTagsForOperation($operationName, $client);
        $tags = array_values(array_unique([...$baseTags, ...$collectorTags]));

        $payloadTtl = $this->payloadTtl;
        if ($this->operationClassifier !== null && $operationName !== '') {
            // Classifier-miss is reachable for legacy in_progress_queries operations folded into operations: at config-merge time.
            $payloadTtl = $this->operationClassifier->getTtl($operationName) ?? $this->payloadTtl;
        }

        $now = time();
        $meta = [
            'refreshedAt' => $now,
            'client' => $client,
            'operation' => $operationName,
            // store canonical request body to allow later refresh scheduling
            'canonical' => $canonical,
            'payloadSavedAt' => $now,
            'payloadTtl' => $payloadTtl,
            'tags' => $tags,
        ];

        Logger::info(sprintf(
            'datahub.swr.cache_save: op=%s client=%s granularity=%s collector_tags=%s',
            $operationName !== '' ? $operationName : '?',
            $client,
            $this->granularityLabel($operationName),
            self::summariseCollectorTags($collectorTags)
        ));

        $this->cacheSave($payloadKey, $payload, $tags, $payloadTtl);
        $this->cacheSave($metaKey, $meta, $tags, $this->ttl);
        $this->updateIndices($payloadKey, $client, $operationName);
        $this->updateReverseIndices($collectorTags, $payloadKey, $metaKey);
    }

    /**
     * Returns `none` for unregistered ops — diagnostically interesting
     * because such ops produce zero collector tags.
     */
    private function granularityLabel(string $operationName): string
    {
        if ($this->operationClassifier === null || $operationName === '') {
            return 'none';
        }
        $granularity = $this->operationClassifier->getGranularity($operationName);

        return $granularity?->value ?? 'none';
    }

    /**
     * Strips tag prefixes for readability and folds per-object tags into
     * `Class×N` counts so listing-shaped queries don't dwarf the log line.
     *
     * @param list<string> $collectorTags
     */
    private static function summariseCollectorTags(array $collectorTags): string
    {
        if ($collectorTags === []) {
            return '[]';
        }
        $classOnly = [];
        $perClassCounts = [];
        foreach ($collectorTags as $tag) {
            if (str_starts_with($tag, DependencyCollector::TAG_CLASS_PREFIX)) {
                $classOnly[] = self::stripModelPrefix(substr($tag, strlen(DependencyCollector::TAG_CLASS_PREFIX)));

                continue;
            }
            if (str_starts_with($tag, DependencyCollector::TAG_OBJECT_PREFIX)) {
                $suffix = substr($tag, strlen(DependencyCollector::TAG_OBJECT_PREFIX));
                $lastUnderscore = strrpos($suffix, '_');
                $class = $lastUnderscore === false ? $suffix : substr($suffix, 0, $lastUnderscore);
                $cleanClass = self::stripModelPrefix($class);
                $perClassCounts[$cleanClass] = ($perClassCounts[$cleanClass] ?? 0) + 1;
            }
        }
        sort($classOnly);
        ksort($perClassCounts);
        $parts = $classOnly;
        foreach ($perClassCounts as $class => $count) {
            $parts[] = $class . '×' . $count;
        }

        return '[' . implode(', ', $parts) . ']';
    }

    private static function stripModelPrefix(string $sanitizedClass): string
    {
        foreach ([
            'Pimcore_Model_DataObject_',
            'Pimcore_Model_Asset_',
            'Pimcore_Model_Document_',
        ] as $prefix) {
            if (str_starts_with($sanitizedClass, $prefix)) {
                return substr($sanitizedClass, strlen($prefix));
            }
        }

        return $sanitizedClass;
    }

    /**
     * Resolve the per-object / per-class tag set for the current request from
     * the DependencyCollector, gated on the operation's granularity. SINGLE
     * with an empty collector raises an in-flight warning — the canonical
     * detector for missing POST_LOAD coverage (raw-SQL hydrators bypass the
     * subscriber and the collector stays empty).
     *
     * @return list<string>
     */
    private function collectorTagsForOperation(string $operationName, string $client): array
    {
        if ($this->dependencyCollector === null || $this->operationClassifier === null || $operationName === '') {
            return [];
        }
        $granularity = $this->operationClassifier->getGranularity($operationName);
        if ($granularity === null) {
            return [];
        }
        if ($granularity === Granularity::SINGLE && !$this->dependencyCollector->hasRecordedAny()) {
            $this->logCollectorEmptyOnSave($operationName, $client);
        }

        return $this->dependencyCollector->tagsForGranularity($granularity);
    }

    /**
     * Loud in-flight detector for the missing-POST_LOAD-coverage failure
     * mode: SINGLE-granularity operations should always record at least one
     * element before writing to the persistent cache. Raw-SQL hydrators
     * bypass the POST_LOAD subscriber and the collector stays empty,
     * producing a write with no per-object tags — invalidation would then
     * miss it entirely.
     *
     * Separated from the call site so tests can observe the trigger
     * condition without booting the Pimcore kernel (the Logger facade
     * no-ops when there is no container).
     */
    protected function logCollectorEmptyOnSave(string $operationName, string $client): void
    {
        Logger::warning(sprintf(
            'datahub.swr.collector_empty_on_save operation=%s client=%s',
            $operationName,
            $client
        ));
    }

    private function buildTags(Request $request, ?string $operationName): array
    {
        $client = (string)$request->attributes->get('clientname', '');
        $tags = [self::TAG_COMMON];
        if ($client !== '') {
            $tags[] = self::TAG_CLIENT_PREFIX . $client;
        }
        if ($operationName) {
            $tags[] = self::TAG_OP_PREFIX . $operationName;
        }

        return $tags;
    }

    private function updateIndices(string $key, string $client, string $operation): void
    {
        $this->addToIndex(self::INDEX_ALL, $key);
        if ($client !== '') {
            $this->addToIndex(self::INDEX_CLIENT_PREFIX . $client, $key);
        }
        if ($operation !== '') {
            $this->addToIndex(self::INDEX_OP_PREFIX . $operation, $key);
        }
    }

    private function addToIndex(string $indexKey, string $memberKey): void
    {
        $list = $this->cacheLoad($indexKey);
        if (!is_array($list)) {
            $list = [];
        }
        if (in_array($memberKey, $list, true)) {
            return;
        }
        $list[] = $memberKey;
        // Prune dead entries when we cross the soft cap; FIFO-evict if still over.
        // Without bounding, the index grows by one entry per unique canonical
        // request body, never shrinks even when payloads have expired.
        if (count($list) > self::MAX_INDEX_SIZE) {
            $alive = [];
            foreach ($list as $k) {
                $v = $this->cacheLoad($k);
                if ($v !== false && $v !== null) {
                    $alive[] = $k;
                }
            }
            $list = $alive;
            if (count($list) > self::MAX_INDEX_SIZE) {
                $list = array_slice($list, -self::MAX_INDEX_SIZE);
            }
        }
        $this->cacheSave($indexKey, $list, [self::TAG_COMMON], null);
    }

    /**
     * @param list<string> $tags
     */
    private function updateReverseIndices(array $tags, string $payloadKey, string $metaKey): void
    {
        foreach ($tags as $tag) {
            $this->addToReverseIndex($tag, $payloadKey, $metaKey);
        }
    }

    private function addToReverseIndex(string $tag, string $payloadKey, string $metaKey): void
    {
        $indexKey = self::REVERSE_INDEX_PREFIX . $tag;
        $list = $this->cacheLoad($indexKey);
        if (!is_array($list)) {
            $list = [];
        }
        $pair = [$payloadKey, $metaKey];
        foreach ($list as $existing) {
            if (is_array($existing) && ($existing[0] ?? null) === $payloadKey) {
                return;
            }
        }
        $list[] = $pair;
        if (count($list) > self::MAX_INDEX_SIZE) {
            $alive = [];
            foreach ($list as $entry) {
                if (!is_array($entry) || count($entry) < 2) {
                    continue;
                }
                $v = $this->cacheLoad((string)$entry[0]);
                if ($v !== false && $v !== null) {
                    $alive[] = $entry;
                }
            }
            $list = $alive;
            if (count($list) > self::MAX_INDEX_SIZE) {
                $list = array_slice($list, -self::MAX_INDEX_SIZE);
                Logger::warning('persistent_cache: reverse-index truncated at max size', ['tag' => $tag, 'size' => self::MAX_INDEX_SIZE]);
            }
        }
        // Reverse-index entries carry only TAG_COMMON — clearAll() drops them
        // on cache flush. Per-object tag membership belongs in the index
        // body, not in the index entry's tag set.
        $this->cacheSave($indexKey, $list, [self::TAG_COMMON], null);
    }

    private function shouldUseForRequest(Request $request): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Only apply to POST GraphQL endpoints handled here
        if (strtoupper($request->getMethod()) !== 'POST') {
            return false;
        }

        $input = json_decode($request->getContent(), true) ?: [];
        $operationName = $input['operationName'] ?? null;
        if (!$operationName || !is_string($operationName)) {
            return false;
        }

        if ($this->operationClassifier !== null
            && $this->operationClassifier->hasOperation($operationName)) {
            return true;
        }

        Logger::debug(sprintf(
            'DataHub persistent cache: gate skipped — operation not in operations tree (operationName=%s)',
            $operationName
        ));

        return false;
    }

    /**
     * Read helper – separated for testability.
     *
     * @return mixed
     */
    protected function cacheLoad(string $key)
    {
        return \Pimcore\Cache::load($key);
    }

    /**
     * Write helper – separated for testability.
     *
     * @param mixed    $value
     * @param int|null $ttl  null = no expiry (use for sentinel/index entries);
     *                       int  = seconds. Do NOT pass 0 — Symfony Cache
     *                       interprets that as "expires immediately".
     */
    protected function cacheSave(string $key, $value, array $tags, ?int $ttl): void
    {
        \Pimcore\Cache::save($value, $key, $tags, $ttl, 1, true);
    }

    protected function cacheClearTag(string $tag): bool
    {
        return \Pimcore\Cache::clearTag($tag);
    }

    protected function cacheRemove(string $key): void
    {
        \Pimcore\Cache::remove($key);
    }

    private function keyPayload(string $clientname, string $canonical): string
    {
        return self::keyPayloadFor($clientname, $canonical);
    }

    private function keyMeta(string $clientname, string $canonical): string
    {
        return self::keyMetaFor($clientname, $canonical);
    }

    public static function keyPayloadFor(string $clientname, string $canonical): string
    {
        return self::PAYLOAD_KEY_PREFIX . hash('sha256', 'client:' . $clientname . "\n" . $canonical);
    }

    public static function keyMetaFor(string $clientname, string $canonical): string
    {
        return self::META_KEY_PREFIX . hash('sha256', 'client:' . $clientname . "\n" . $canonical);
    }

    /**
     * Canonical per-entry identity: bare sha256 digest (no prefix).
     *
     * @param string $client    client name from the request
     * @param string $canonical already-canonical request body
     */
    public static function entryHash(string $client, string $canonical): string
    {
        return hash('sha256', 'client:' . $client . "\n" . $canonical);
    }

    /**
     * Canonicalization-tolerant variant of entryHash(). Use this when the
     * body may not yet be canonical (e.g. raw handler payload before AST
     * normalisation); the result is always identical to calling entryHash()
     * on the already-canonical form.
     *
     * @param string $client   client name from the request
     * @param string $bodyJson raw or canonical request body
     */
    public static function entryHashFromBody(string $client, string $bodyJson): string
    {
        return self::entryHash($client, self::canonicalizePayloadString($bodyJson));
    }

    /**
     * SWR-refresh-lock resource shape for the per-query-hash space, computed
     * from the raw request inputs the message handler observes. Byte-equal to
     * the listener's legacy `buildRefreshMarkerKey` shape when meta+payload
     * sidecar attributes are present — that is the contract.
     */
    public static function computeSwrRefreshLockKey(string $client, string $bodyJson): string
    {
        $canonical = self::canonicalizePayloadString($bodyJson);
        $metaKey = self::keyMetaFor($client, $canonical);
        $payloadKey = self::keyPayloadFor($client, $canonical);

        return 'datahub_persistent_refresh_lock_' . md5($metaKey . '|' . $payloadKey);
    }

    private function clientAndCanonical(Request $request): array
    {
        $clientname = (string)$request->attributes->get('clientname', '');
        $canonical = $this->canonicalizePayload($request);

        return [$clientname, $canonical];
    }

    private function canonicalizePayload(Request $request): string
    {
        $cached = $request->attributes->get('_datahub_persistent_canonical');
        if (is_string($cached)) {
            return $cached;
        }

        $canonical = self::canonicalizePayloadString((string)$request->getContent());

        $request->attributes->set('_datahub_persistent_canonical', $canonical);

        return $canonical;
    }

    /**
     * Thin pass-through to {@see GraphQLRequestCanonicalizer::canonicalize}.
     * Kept as a public static so external callers (functional-suite fixtures,
     * the SWR-refresh-lock-key helper) keep working without re-grep.
     */
    public static function canonicalizePayloadString(string $body): string
    {
        return GraphQLRequestCanonicalizer::canonicalize($body);
    }

    /**
     * Enqueue-dedupe sentinel resource shape for the per-canonical-request
     * space, computed from raw inputs. Canonicalizes before hashing so both
     * the invalidation-listener path (canonical body) and the terminate-path
     * (raw body) resolve to the same key.
     */
    public static function computeEnqueueDedupeKey(string $client, string $bodyJson): string
    {
        return self::ENQUEUE_DEDUPE_PREFIX . self::entryHashFromBody($client, $bodyJson);
    }
}
