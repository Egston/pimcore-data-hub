<?php

declare(strict_types=1);

/**
 * Additional, persistent GraphQL output cache layer (SWR) for Data Hub.
 *
 * - Stores responses independently from Pimcore's 'output' tag cache.
 * - Survives 'output' tag invalidations and serves stale results with a header.
 * - TTL is refreshed on each hit.
 * - Applies to the same guarded queries by default, but is configurable.
 * - Provides tagging for console-based cache clearing, incl. per-operation tags.
 */

namespace Pimcore\Bundle\DataHubBundle\Service;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use Pimcore\Logger;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class PersistentOutputCacheService
{
    private const TAG_COMMON = 'datahub_graphql_persistent';
    private const TAG_OP_PREFIX = 'datahub_graphql_op:'; // datahub_graphql_op:<operation>
    private const TAG_CLIENT_PREFIX = 'datahub_graphql_client:'; // datahub_graphql_client:<client>
    private const KEY_LAST_INVALIDATION = 'datahub_graphql_output_last_invalidation_ts';

    private const INDEX_ALL = 'datahub_graphql_persistent_index_all';
    private const INDEX_OP_PREFIX = 'datahub_graphql_persistent_index_op:'; // + <operation>
    private const INDEX_CLIENT_PREFIX = 'datahub_graphql_persistent_index_client:'; // + <client>

    private bool $enabled = false;
    private int $ttl; // seconds
    private bool $guardOnly = true;
    private array $guardOperations = [];
    private int $payloadTtl = 86400;

    public function __construct(private ContainerBagInterface $container)
    {
        $cfg = $container->get('pimcore_data_hub');

        $graphql = $cfg['graphql'] ?? [];
        $this->enabled = (bool)($graphql['persistent_output_cache_enabled'] ?? false);
        $ttl = $graphql['persistent_output_cache_lifetime'] ?? null;
        if ($ttl === null) {
            $ttl = $graphql['output_cache_lifetime'] ?? 30;
        }
        $this->ttl = max(1, (int)$ttl);
        $this->guardOnly = (bool)($graphql['persistent_output_cache_guard_only'] ?? true);
        $this->guardOperations = array_values(array_filter((array)($graphql['in_progress_queries'] ?? []), static function ($v) {
            return is_string($v) && $v !== '';
        }));
        $this->payloadTtl = max(1, (int)($graphql['persistent_output_cache_payload_ttl'] ?? 86400));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Pre-request hook.
     *
     * - If persistent cache HIT and FRESH: returns the response to short-circuit.
     * - If HIT but STALE: marks request attributes to return stale later and revalidate now, returns null.
     * - Otherwise: returns null.
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
        $lastInvalidation = (int) ($this->cacheLoad(self::KEY_LAST_INVALIDATION) ?: 0);
        $refreshedAt = (int)($meta['refreshedAt'] ?? 0);
        $isStale = $lastInvalidation > 0 && $refreshedAt > 0 && $refreshedAt < $lastInvalidation;
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
            return $response;
        }

        // FRESH HIT: extend TTL and return response immediately
        $response->headers->set('X-Pimcore-DataHub-Persistent-Cache', 'HIT');
        $meta['refreshedAt'] = $now;
        $tags = $this->buildTags($request, $meta['operation'] ?? null);
        $this->cacheSave($metaKey, $meta, $tags, $this->ttl);

        return $response;
    }

    /**
     * Post-request hook. Save/refresh persistent cache and decide which response to return.
     *
     * Returns a response when we must override the default response (stale-while-revalidate). Otherwise null.
     */
    public function postHandle(Request $request, JsonResponse $freshResponse): ?JsonResponse
    {
        if (!$this->shouldUseForRequest($request)) {
            return null;
        }

        $stale = $request->attributes->get('_datahub_persistent_stale_response');

        // Save fresh into persistent layer
        try {
            $this->savePersistent($request, $freshResponse);
        } catch (\Throwable $e) {
            // ignore, do not block response
            Logger::warning('DataHub persistent cache save failed: ' . $e->getMessage());
        }

        if ($stale instanceof JsonResponse) {
            // Serve stale (SWR) and let fresh be cached
            return $stale;
        }

        return null; // don't override
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

        $lastInvalidation = (int) ($this->cacheLoad(self::KEY_LAST_INVALIDATION) ?: 0);
        $refreshedAt = (int)($meta['refreshedAt'] ?? 0);
        $isStale = $lastInvalidation > 0 && $refreshedAt > 0 && $refreshedAt < $lastInvalidation;
        return ['applies' => true, 'status' => $isStale ? 'STALE' : 'HIT'];
    }

    /** Manually set the last invalidation timestamp to now. */
    public function markOutputInvalidated(?int $ts = null): void
    {
        $ts = $ts ?? time();
        $this->cacheSave(self::KEY_LAST_INVALIDATION, $ts, [self::TAG_COMMON], 0);
    }

    /** Persist a fresh response for the given request. */
    public function savePersistent(Request $request, JsonResponse $response): void
    {
        if (!$this->shouldUseForRequest($request)) {
            return;
        }

        [$client, $canonical] = $this->clientAndCanonical($request);
        $metaKey = $this->keyMeta($client, $canonical);
        $payloadKey = $this->keyPayload($client, $canonical);

        $input = json_decode($request->getContent(), true) ?: [];
        $operationName = (string)($input['operationName'] ?? '');

        $payload = json_decode($response->getContent() ?: 'null', true);
        if ($payload === null) {
            $payload = [];
        }

        $meta = [
            'refreshedAt' => time(),
            'client' => $client,
            'operation' => $operationName,
            // store canonical request body to allow later refresh scheduling
            'canonical' => $canonical,
        ];

        $tags = $this->buildTags($request, $operationName);
        $this->cacheSave($payloadKey, $payload, $tags, $this->payloadTtl);
        $this->cacheSave($metaKey, $meta, $tags, $this->ttl);
        $this->updateIndices($payloadKey, $client, $operationName);
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
        if (!in_array($memberKey, $list, true)) {
            $list[] = $memberKey;
            $this->cacheSave($indexKey, $list, [self::TAG_COMMON], 0);
        }
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

        if ($this->guardOnly) {
            $input = json_decode($request->getContent(), true) ?: [];
            $operationName = $input['operationName'] ?? null;
            if (!$operationName) {
                return false;
            }
            if (!in_array($operationName, $this->guardOperations, true)) {
                return false;
            }
        }

        return true;
    }

    private function computePersistentKey(Request $request): string
    {
        $clientname = (string)$request->attributes->get('clientname', '');
        $canonical = $this->canonicalizePayload($request);
        return $this->computePersistentKeyFromCanonical($clientname, $canonical);
    }

    /**
     * Read helper – separated for testability.
     *
     * @param string $key
     * @return mixed
     */
    protected function cacheLoad(string $key)
    {
        return \Pimcore\Cache::load($key);
    }

    /**
     * Write helper – separated for testability.
     *
     * @param string $key
     * @param mixed  $value
     * @param array  $tags
     * @param int    $ttl
     */
    protected function cacheSave(string $key, $value, array $tags, int $ttl): void
    {
        // priority=1, force write immediately
        \Pimcore\Cache::save($value, $key, $tags, $ttl, 1, true);
    }

    private function computePersistentKeyFromCanonical(string $clientname, string $canonical): string
    {
        return 'persistent_output_' . hash('sha256', 'client:' . $clientname . "\n" . $canonical);
    }

    private function keyPayload(string $clientname, string $canonical): string
    {
        return 'persistent_output_payload_' . hash('sha256', 'client:' . $clientname . "\n" . $canonical);
    }

    private function keyMeta(string $clientname, string $canonical): string
    {
        return 'persistent_output_meta_' . hash('sha256', 'client:' . $clientname . "\n" . $canonical);
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

        if (!is_string($canonical)) {
            $canonical = '{}';
        }

        $request->attributes->set('_datahub_persistent_canonical', $canonical);
        return $canonical;
    }

    private function normalizeQueryAst(string $query): string
    {
        try {
            /** @var DocumentNode $ast */
            $ast = Parser::parse($query);
            return Printer::doPrint($ast);
        } catch (\Throwable $e) {
            return trim($query);
        }
    }

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
}
