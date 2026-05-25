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

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\OutputCachePreLoadEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\OutputCachePreSaveEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\OutputCacheEvents;
use Pimcore\Bundle\DataHubBundle\Lock\LockFactoryResolver;
use Pimcore\Bundle\DataHubBundle\Lock\LockSignalRefresher;
use Pimcore\Logger;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class OutputCacheService
{
    /**
     * @var bool
     */
    private $cacheEnabled = false;

    /**
     * The cached items lifetime in seconds
     *
     * @var int
     */
    private $lifetime = 30;

    /**
     * Enable/disable in-progress protection.
     */
    private $inProgressProtectionEnabled = false;

    /**
     * List of GraphQL operation names to protect.
     */
    private $inProgressQueries = [];

    /**
     * TTL (seconds) for the in-progress marker/lock. Bounds the leak window when
     * a request dies between acquire and release without an exception (SIGKILL,
     * OOM, FPM worker recycle). Periodic refresh keeps the lock alive during
     * legitimate long-running requests, so this can be set much smaller than the
     * worst-case execution time.
     */
    private $inProgressTtl = 60;

    /**
     * Seconds between background refresh ticks for the in-progress lock and
     * marker. Must be < $inProgressTtl. 0 = disabled (legacy behaviour: TTL
     * alone bounds both the leak window and the longest supported request).
     * Auto-defaulted to floor($inProgressTtl / 2) when left at 0.
     */
    private $inProgressRefreshInterval = 0;

    /**
     * Process-wide latch: SIGALRM handler is currently armed. Guards uninstall
     * from running pcntl calls when nothing was ever installed.
     */
    private $pcntlRefresherInstalled = false;

    private LockFactoryResolver $lockFactoryResolver;

    /**
     * HTTP status code used when rejecting duplicates.
     */
    private $inProgressHttpStatus = 503;

    /**
     * Optional Retry-After header value (seconds).
     */
    private $inProgressRetryAfter = null;

    /**
     * Strategy for guard key: 'request' (query+variables) or 'operation' (operationName only).
     *
     * @var string
     */
    private $inProgressKeyStrategy = 'request';

    /**
     * @var EventDispatcherInterface
     */
    public $eventDispatcher;

    public function __construct(
        ContainerBagInterface $container,
        EventDispatcherInterface $eventDispatcher,
        ?LockFactoryResolver $lockFactoryResolver = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->lockFactoryResolver = $lockFactoryResolver ?? new LockFactoryResolver();

        $dataHubConfig = $container->get('pimcore_data_hub');
        if (isset($dataHubConfig['graphql'])) {
            if (isset($dataHubConfig['graphql']['output_cache_enabled'])) {
                $this->cacheEnabled = filter_var($dataHubConfig['graphql']['output_cache_enabled'], FILTER_VALIDATE_BOOLEAN);
            }

            if (isset($dataHubConfig['graphql']['output_cache_lifetime'])) {
                $this->lifetime = intval($dataHubConfig['graphql']['output_cache_lifetime']);
            }

            // in-progress protection config
            if (isset($dataHubConfig['graphql']['in_progress_protection_enabled'])) {
                $this->inProgressProtectionEnabled = filter_var($dataHubConfig['graphql']['in_progress_protection_enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($dataHubConfig['graphql']['in_progress_queries']) && is_array($dataHubConfig['graphql']['in_progress_queries'])) {
                $this->inProgressQueries = array_values(array_filter($dataHubConfig['graphql']['in_progress_queries'], static function ($v) {
                    return is_string($v) && $v !== '';
                }));
            }
            if (isset($dataHubConfig['graphql']['in_progress_ttl'])) {
                $this->inProgressTtl = max(1, intval($dataHubConfig['graphql']['in_progress_ttl']));
            }
            if (isset($dataHubConfig['graphql']['in_progress_refresh_interval'])) {
                $this->inProgressRefreshInterval = max(0, intval($dataHubConfig['graphql']['in_progress_refresh_interval']));
            }
            if ($this->inProgressRefreshInterval === 0 && $this->inProgressTtl > 1) {
                $this->inProgressRefreshInterval = max(1, (int) floor($this->inProgressTtl / 2));
            }
            if ($this->inProgressRefreshInterval >= $this->inProgressTtl) {
                // Refresh must fire before the TTL elapses or the lock leaks for a full TTL window.
                $this->inProgressRefreshInterval = max(1, (int) floor($this->inProgressTtl / 2));
            }
            if (isset($dataHubConfig['graphql']['in_progress_http_status'])) {
                $this->inProgressHttpStatus = intval($dataHubConfig['graphql']['in_progress_http_status']);
            }
            if (array_key_exists('in_progress_retry_after', $dataHubConfig['graphql'])) {
                $v = $dataHubConfig['graphql']['in_progress_retry_after'];
                $this->inProgressRetryAfter = $v === null ? null : intval($v);
            }
            if (isset($dataHubConfig['graphql']['in_progress_key_strategy'])) {
                $strategy = (string) $dataHubConfig['graphql']['in_progress_key_strategy'];
                $strategy = in_array($strategy, ['request', 'operation'], true) ? $strategy : 'request';
                $this->inProgressKeyStrategy = $strategy;
            }
        }
    }

    /**
     * Probe the standard output cache status for this request without side effects.
     * Returns one of: 'HIT', 'MISS', or 'DISABLED'.
     */
    public function probeStatus(Request $request): string
    {
        // Reuse the same gating logic as normal load/save
        $event = new OutputCachePreLoadEvent($request, true);
        $this->eventDispatcher->dispatch($event, OutputCacheEvents::PRE_LOAD);
        $use = $this->cacheEnabled && $event->isUseCache();

        if (!$use) {
            return 'DISABLED';
        }

        $newKey = $this->computeKey($request);
        $item = $this->loadFromCache($newKey);
        if ($item !== false && $item !== null) {
            return 'HIT';
        }

        // try legacy key for completeness
        $legacyKey = $this->computeLegacyKey($request);
        $item = $this->loadFromCache($legacyKey);
        if ($item !== false && $item !== null) {
            return 'HIT';
        }

        return 'MISS';
    }

    /**
     *
     * @return mixed
     */
    public function load(Request $request)
    {
        if (!$this->useCache($request)) {
            return null;
        }

        // Try new canonical key first
        $newKey = $this->computeKey($request);
        $item = $this->loadFromCache($newKey);
        if ($item !== false && $item !== null) {
            return $item;
        }

        // Backward-compat: try legacy key (print_r-based) so rollout is seamless
        $legacyKey = $this->computeLegacyKey($request);
        $item = $this->loadFromCache($legacyKey);
        if ($item !== false && $item !== null) {
            return $item;
        }

        return null;
    }

    /**
     * @param array $extraTags
     *
     */
    public function save(Request $request, JsonResponse $response, $extraTags = []): void
    {
        // Release concurrency guards unconditionally — they protect against thundering herd
        // regardless of whether the response ends up in the cache.
        $this->deleteInProgressLock($request);
        $this->releaseAtomicLockIfAny($request);

        if ($this->useCache($request)) {
            $clientname = $request->attributes->getString('clientname');
            $extraTags = array_merge(['output', 'datahub', $clientname], $extraTags);

            $cacheKey = $this->computeKey($request);

            $event = new OutputCachePreSaveEvent($request, $response);
            $this->eventDispatcher->dispatch($event, OutputCacheEvents::PRE_SAVE);

            $this->saveToCache($cacheKey, $response, $extraTags);
        }
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    protected function loadFromCache($key)
    {
        return \Pimcore\Cache::load($key);
    }

    /**
     * @param string $key
     * @param mixed $item
     * @param array $tags
     *
     */
    protected function saveToCache($key, $item, $tags = []): void
    {
        // Increase priority to 1 to make it less likely this cache item is evicted from the
        // queue before actually being written, or better yet, write it immediately.
        \Pimcore\Cache::save($item, $key, $tags, $this->lifetime, 1, true);

        try {
            Logger::debug(sprintf('Output cache SAVED (key=%s, ttl=%d, tags=%s)', $key, (int) $this->lifetime, implode(',', $tags)));
        } catch (\Throwable $e) {
            // ignore logging failures
        }
    }

    /**
     * Canonicalize the incoming JSON body for cache/lock keys:
     * - Parse JSON
     * - Optionally AST-normalize the GraphQL 'query'
     * - Harmonize variables (treat missing vs null equivalently for declared vars)
     * - Recursively ksort all associative arrays
     * - Encode with stable json_encode flags
     *
     */
    private function canonicalizePayloadForCache(Request $request): string
    {
        $cached = $request->attributes->get('_datahub_canonical_payload');
        if (is_string($cached)) {
            return $cached;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        if (!empty($payload['query']) && is_string($payload['query'])) {
            $payload['query'] = $this->normalizeQueryAst($payload['query']);
        }

        $payload = $this->ksortRecursive($payload);

        $canonical = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        // Guard against rare encode failures returning false
        if (!is_string($canonical)) {
            $canonical = '{}';
        }

        $request->attributes->set('_datahub_canonical_payload', $canonical);

        return $canonical;
    }

    /**
     * Parse and re-print the GraphQL query to a canonical form.
     */
    private function normalizeQueryAst(string $query): string
    {
        try {
            /** @var DocumentNode $ast */
            $ast = Parser::parse($query);

            // Printer preserves a canonical formatting; not sorting selections (keeps semantic order)
            return Printer::doPrint($ast);
        } catch (\Throwable $e) {
            // The query is already invalid; we cannot parse it. However, we still need some
            // reproducible canonical form for caching/locking.
            return trim($query);
        }
    }

    /** Recursively ksort associative arrays; leave list arrays as-is (order significant). */
    private function ksortRecursive(array $value): array
    {
        $isAssoc = static function (array $a): bool {
            $i = 0;
            foreach ($a as $k => $_) {
                if ($k !== $i++) {
                    return true;
                }
            }

            return false;
        };

        if ($isAssoc($value)) {
            ksort($value);
        }

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->ksortRecursive($v);
            }
        }

        return $value;
    }

    /** Compute a cache key for the given request. */
    private function computeKey(Request $request): string
    {
        $clientname = (string) $request->attributes->get('clientname', '');
        $payload    = $this->canonicalizePayloadForCache($request);

        return 'output_' . hash('sha256', 'client:' . $clientname . "\n" . $payload);
    }

    /** Legacy key computation for seamless rollout without invalidating existing cache. */
    private function computeLegacyKey(Request $request): string
    {
        $clientname = $request->attributes->getString('clientname');
        $input = json_decode($request->getContent(), true);
        $input = print_r($input, true);

        return md5('output_' . $clientname . $input);
    }

    /**
     * Reject duplicates or acquire an in-progress marker for protected queries.
     * Returns a JsonResponse when another identical request is running; otherwise null.
     */
    public function maybeRejectOrAcquire(Request $request): ?JsonResponse
    {
        // allow background refresh to bypass herd guard
        if ($request->attributes->get('_datahub_bypass_in_progress_guard')) {
            return null;
        }
        if (!$this->shouldGuardRequest($request)) {
            return null;
        }

        // First try an atomic lock using Symfony Lock (preferably backed by Redis)
        $lock = $this->acquireAtomicLock($request);
        if ($lock === false) {
            // another process holds the lock
            return $this->buildInProgressResponse();
        }

        // Fallback or additionally set a lightweight marker in cache for quick checks
        $guardKey = $this->computeGuardKey($request);
        if ($this->inProgressLockExists($guardKey)) {
            // Someone already set marker; if we do not own a lock, reject
            if (!$lock) {
                return $this->buildInProgressResponse();
            }
        } else {
            $this->saveInProgressLock($guardKey, $request);
            // Store so the safety-net listener can delete the marker if save() never runs.
            $request->attributes->set('datahub_inprogress_guard_key', $guardKey);
        }

        return null;
    }

    /** Determine if the current request should be protected. */
    private function shouldGuardRequest(Request $request): bool
    {
        if (!$this->inProgressProtectionEnabled) {
            return false;
        }

        // Tier attribute set by the controller from the `operations:` tree, OR
        // the legacy `in_progress_queries:` membership check below — either
        // gate engages the guard.
        if ($request->attributes->get('_datahub_tier') === Tier::HERD_GUARDED->value) {
            return true;
        }

        if (empty($this->inProgressQueries)) {
            return false;
        }

        $input = json_decode($request->getContent(), true) ?: [];
        $operationName = $input['operationName'] ?? null;
        if (!$operationName) {
            return false;
        }

        return in_array($operationName, $this->inProgressQueries, true);
    }

    /** Build cache key for the in-progress marker. */
    private function lockKeyFor(string $guardKey): string
    {
        return 'datahub_inprogress_' . $guardKey;
    }

    /** Check if in-progress marker is present. */
    private function inProgressLockExists(string $guardKey): bool
    {
        return (bool) \Pimcore\Cache::load($this->lockKeyFor($guardKey));
    }

    /** Save in-progress marker with TTL. */
    private function saveInProgressLock(string $guardKey, Request $request): void
    {
        $clientname = $request->attributes->getString('clientname');
        $tags = ['datahub_inprogress', $clientname];
        $key = $this->lockKeyFor($guardKey);

        // value is irrelevant; we only care about existence
        \Pimcore\Cache::save(1, $key, $tags, $this->inProgressTtl, 1, true);
    }

    /** Remove the in-progress marker and clear the request attribute so the safety-net listener is a no-op. */
    private function deleteInProgressLock(Request $request): void
    {
        $this->uninstallLockRefresher();

        $guardKey = $this->computeGuardKey($request);
        $key = $this->lockKeyFor($guardKey);

        try {
            \Pimcore\Cache::remove($key);
        } catch (\Throwable $e) {
        }

        $request->attributes->remove('datahub_inprogress_guard_key');
    }

    /** Compute a client-agnostic guard key according to configured strategy. */
    private function computeGuardKey(Request $request): string
    {
        $input = json_decode($request->getContent(), true) ?: [];

        if ($this->inProgressKeyStrategy === 'operation') {
            $operationName = $input['operationName'] ?? '';
            // If operationName is missing, fall back to full request body
            if ($operationName !== '') {
                return md5('op_' . $operationName);
            }
        }

        $canonical = $this->canonicalizePayloadForCache($request);

        return hash('sha256', 'req:' . $canonical);
    }

    /**
     * Try to acquire a non-blocking lock for this request.
     *
     * @return object|false|null Returns lock object on success, false if held by others, null if locking unavailable
     */
    private function acquireAtomicLock(Request $request)
    {
        $factory = $this->lockFactoryResolver->resolve();
        if (!$factory) {
            return null;
        }

        $resource = 'datahub_inprogress:' . $this->computeGuardKey($request);

        try {
            // autoRelease=true so the Lock destructor releases on graceful PHP
            // shutdown even if save() and the safety-net listener both fail to run.
            $lock = $factory->createLock($resource, $this->inProgressTtl, true);
            if ($lock->acquire(false)) {
                // keep reference on request for releasing in save()
                $request->attributes->set('datahub_inprogress_lock', $lock);
                LockSignalRefresher::arm(
                    $lock,
                    $this->inProgressTtl,
                    $this->inProgressRefreshInterval,
                    $this->lockKeyFor($this->computeGuardKey($request)),
                    ['datahub_inprogress', $request->attributes->getString('clientname')]
                );
                $this->pcntlRefresherInstalled = true;

                return $lock;
            }
        } catch (\Throwable $e) {
            // if anything goes wrong, just fallback
            Logger::warning('DataHub in-progress: failed to acquire atomic lock: ' . $e->getMessage());
        }

        return false; // indicates someone else holds it
    }

    /**
     * Release the previously acquired lock if present on request.
     */
    private function releaseAtomicLockIfAny(Request $request): void
    {
        $this->uninstallLockRefresher();

        $lock = $request->attributes->get('datahub_inprogress_lock');
        if ($lock) {
            try {
                if (method_exists($lock, 'release')) {
                    $lock->release();
                }
            } catch (\Throwable $e) {
                // ignore
            }
            $request->attributes->remove('datahub_inprogress_lock');
        }
    }

    /**
     * Cancel any pending SIGALRM and detach the handler. Idempotent: safe to
     * call from every release path (save, listener, deleteInProgressLock).
     */
    private function uninstallLockRefresher(): void
    {
        if (!$this->pcntlRefresherInstalled) {
            return;
        }

        LockSignalRefresher::disarm();
        $this->pcntlRefresherInstalled = false;
    }

    private function buildInProgressResponse(): JsonResponse
    {
        $payload = [
            'errors' => [
                [
                    'message' => 'Query is currently being processed. Please retry shortly.',
                ],
            ],
        ];

        $response = new JsonResponse($payload, $this->inProgressHttpStatus);
        if ($this->inProgressRetryAfter !== null) {
            $response->headers->set('Retry-After', (string) max(0, $this->inProgressRetryAfter));
        }

        return $response;
    }

    private function useCache(Request $request): bool
    {
        if (!$this->cacheEnabled) {
            Logger::debug('Output cache is disabled');

            return false;
        }

        if (\Pimcore::inDebugMode()) {
            $disableCacheForSingleRequest = filter_var($request->query->get('pimcore_nocache', 'false'), FILTER_VALIDATE_BOOLEAN)
            || filter_var($request->query->get('pimcore_outputfilters_disabled', 'false'), FILTER_VALIDATE_BOOLEAN);

            if ($disableCacheForSingleRequest) {
                Logger::debug('Output cache is disabled for this request');

                return false;
            }
        }

        // So far, cache will be used, unless the listener denies it
        $event = new OutputCachePreLoadEvent($request, true);
        $this->eventDispatcher->dispatch($event, OutputCacheEvents::PRE_LOAD);

        return $event->isUseCache();
    }
}
