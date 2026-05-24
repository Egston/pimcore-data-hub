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
     * Enable/disable herd guard protection.
     */
    private $herdGuardEnabled = false;

    /**
     * TTL (seconds) for the herd-guard marker/lock. Bounds the leak window when
     * a request dies between acquire and release without an exception (SIGKILL,
     * OOM, FPM worker recycle). Periodic refresh keeps the lock alive during
     * legitimate long-running requests, so this can be set much smaller than the
     * worst-case execution time.
     */
    private $herdGuardTtl = 60;

    /**
     * Seconds between background refresh ticks for the herd-guard lock and
     * marker. Must be < $herdGuardTtl. 0 = disabled (legacy behaviour: TTL
     * alone bounds both the leak window and the longest supported request).
     * Auto-defaulted to floor($herdGuardTtl / 2) when left at 0.
     */
    private $herdGuardRefreshInterval = 0;

    /**
     * Process-wide latch: SIGALRM handler is currently armed. Guards uninstall
     * from running pcntl calls when nothing was ever installed.
     */
    private $pcntlRefresherInstalled = false;

    private LockFactoryResolver $lockFactoryResolver;

    /**
     * HTTP status code used when rejecting duplicates.
     */
    private $herdGuardHttpStatus = 503;

    /**
     * Optional Retry-After header value (seconds).
     */
    private $herdGuardRetryAfter = null;

    /**
     * Strategy for guard key: 'request' (query+variables) or 'operation' (operationName only).
     *
     * @var string
     */
    private $herdGuardKeyStrategy = 'request';

    private ?OperationClassifier $classifier = null;

    /**
     * @var EventDispatcherInterface
     */
    public $eventDispatcher;

    public function __construct(
        ContainerBagInterface $container,
        EventDispatcherInterface $eventDispatcher,
        ?LockFactoryResolver $lockFactoryResolver = null,
        ?OperationClassifier $classifier = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->lockFactoryResolver = $lockFactoryResolver ?? new LockFactoryResolver();
        $this->classifier = $classifier;

        $dataHubConfig = $container->get('pimcore_data_hub');
        if (isset($dataHubConfig['graphql'])) {
            if (isset($dataHubConfig['graphql']['output_cache_enabled'])) {
                $this->cacheEnabled = filter_var($dataHubConfig['graphql']['output_cache_enabled'], FILTER_VALIDATE_BOOLEAN);
            }

            if (isset($dataHubConfig['graphql']['output_cache_lifetime'])) {
                $this->lifetime = intval($dataHubConfig['graphql']['output_cache_lifetime']);
            }

            // Configuration validator folds in_progress_* aliases into herd_guard_* at config-tree level.
            // The raw-array path used in tests bypasses the validator, so also read the alias keys here.
            $g = $dataHubConfig['graphql'];
            $enabledVal = $g['herd_guard_enabled'] ?? $g['in_progress_protection_enabled'] ?? null;
            if ($enabledVal !== null) {
                $this->herdGuardEnabled = filter_var($enabledVal, FILTER_VALIDATE_BOOLEAN);
            }
            $ttlVal = $g['herd_guard_ttl'] ?? $g['in_progress_ttl'] ?? null;
            if ($ttlVal !== null) {
                $this->herdGuardTtl = max(1, intval($ttlVal));
            }
            $refreshVal = $g['herd_guard_refresh_interval'] ?? $g['in_progress_refresh_interval'] ?? null;
            if ($refreshVal !== null) {
                $this->herdGuardRefreshInterval = max(0, intval($refreshVal));
            }
            if ($this->herdGuardRefreshInterval === 0 && $this->herdGuardTtl > 1) {
                $this->herdGuardRefreshInterval = max(1, (int) floor($this->herdGuardTtl / 2));
            }
            if ($this->herdGuardRefreshInterval >= $this->herdGuardTtl) {
                $this->herdGuardRefreshInterval = max(1, (int) floor($this->herdGuardTtl / 2));
            }
            $httpStatusVal = $g['herd_guard_http_status'] ?? $g['in_progress_http_status'] ?? null;
            if ($httpStatusVal !== null) {
                $this->herdGuardHttpStatus = intval($httpStatusVal);
            }
            if (array_key_exists('herd_guard_retry_after', $g) && $g['herd_guard_retry_after'] !== null) {
                $this->herdGuardRetryAfter = intval($g['herd_guard_retry_after']);
            } elseif (array_key_exists('in_progress_retry_after', $g) && $g['in_progress_retry_after'] !== null) {
                $this->herdGuardRetryAfter = intval($g['in_progress_retry_after']);
            }
            $keyStrategyVal = $g['herd_guard_key_strategy'] ?? $g['in_progress_key_strategy'] ?? null;
            if ($keyStrategyVal !== null) {
                $strategy = (string) $keyStrategyVal;
                $this->herdGuardKeyStrategy = in_array($strategy, ['request', 'operation'], true) ? $strategy : 'request';
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
     * Canonicalise the request body once per request. The AST parse + reprint
     * in {@see GraphQLRequestCanonicalizer::canonicalize} is the expensive step;
     * the standard-cache path touches it from three call sites (key, guard key,
     * atomic-lock resource) so the result is memoised on the request attribute
     * bag. Mirrors {@see PersistentOutputCacheService::canonicalizePayload}.
     */
    private function canonicalizePayload(Request $request): string
    {
        $cached = $request->attributes->get('_datahub_canonical_payload');
        if (is_string($cached)) {
            return $cached;
        }

        $canonical = GraphQLRequestCanonicalizer::canonicalize((string) $request->getContent());

        $request->attributes->set('_datahub_canonical_payload', $canonical);

        return $canonical;
    }

    /** Compute a cache key for the given request. */
    private function computeKey(Request $request): string
    {
        $clientname = (string) $request->attributes->get('clientname', '');
        $payload    = $this->canonicalizePayload($request);

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
        if ($this->herdGuardLockExists($guardKey)) {
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
        if (!$this->herdGuardEnabled) {
            return false;
        }

        // Attribute path: controller pre-classified this operation; fallback reads classifier directly.
        $tierRaw = $request->attributes->get('_datahub_tier');
        $tier = $tierRaw instanceof Tier ? $tierRaw : (is_string($tierRaw) ? Tier::tryFrom($tierRaw) : null);
        if ($tier !== null && $tier->engagesHerdGuard()) {
            return true;
        }

        $input = json_decode($request->getContent(), true) ?: [];
        $operationName = $input['operationName'] ?? null;
        if (!$operationName) {
            return false;
        }

        return $this->classifier !== null
            && $this->classifier->getTier($operationName)->engagesHerdGuard();
    }

    /** Build cache key for the in-progress marker. */
    private function lockKeyFor(string $guardKey): string
    {
        return 'datahub_inprogress_' . $guardKey;
    }

    /**
     * Lock resource string for the operationName-keyed herd-guard atomic lock
     * space (Symfony Lock, not Pimcore Cache).
     *
     * The refresh queue handler ({@see \Pimcore\Bundle\DataHubBundle\MessageHandler\PersistentRefreshMessageHandler})
     * MUST acquire on this exact resource string to serialise per-op refreshes
     * against the controller's {@see acquireAtomicLock()} call. Note the `:`
     * separator — the `_` separator belongs to the cache-marker space used by
     * {@see lockKeyFor()}, which is a separate subsystem (Pimcore Cache markers,
     * not Symfony Lock resources).
     */
    public static function computeOperationLockKey(string $operationName): string
    {
        return 'datahub_inprogress:' . md5('op_' . $operationName);
    }

    /** Check if herd-guard marker is present. */
    private function herdGuardLockExists(string $guardKey): bool
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
        \Pimcore\Cache::save(1, $key, $tags, $this->herdGuardTtl, 1, true);
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

        if ($this->herdGuardKeyStrategy === 'operation') {
            $operationName = $input['operationName'] ?? '';
            // If operationName is missing, fall back to full request body
            if ($operationName !== '') {
                return md5('op_' . $operationName);
            }
        }

        $canonical = $this->canonicalizePayload($request);

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

        $input = json_decode($request->getContent(), true) ?: [];
        $operationName = ($this->herdGuardKeyStrategy === 'operation' && !empty($input['operationName']))
            ? $input['operationName']
            : '';

        if ($operationName !== '') {
            $resource = self::computeOperationLockKey($operationName);
        } else {
            $canonical = $this->canonicalizePayload($request);
            $resource = 'datahub_inprogress:' . hash('sha256', 'req:' . $canonical);
        }

        try {
            // autoRelease=true so the Lock destructor releases on graceful PHP
            // shutdown even if save() and the safety-net listener both fail to run.
            $lock = $factory->createLock($resource, $this->herdGuardTtl, true);
            if ($lock->acquire(false)) {
                // keep reference on request for releasing in save()
                $request->attributes->set('datahub_inprogress_lock', $lock);
                LockSignalRefresher::arm(
                    $lock,
                    $this->herdGuardTtl,
                    $this->herdGuardRefreshInterval,
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

        $response = new JsonResponse($payload, $this->herdGuardHttpStatus);
        if ($this->herdGuardRetryAfter !== null) {
            $response->headers->set('Retry-After', (string) max(0, $this->herdGuardRetryAfter));
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
