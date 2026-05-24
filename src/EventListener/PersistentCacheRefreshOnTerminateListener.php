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

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQLService;
use Pimcore\Bundle\DataHubBundle\Lock\LockSignalRefresher;
use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Cache as PimcoreCache;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Logger;
use Pimcore\Model\Factory;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dual-mode refresh dispatcher fired on kernel.terminate after a STALE-hit
 * response has been flushed to the client.
 *
 * When `persistent_refresh_queue_enabled` is true, this listener builds a
 * {@see PersistentRefreshMessage} from the request body and pushes it onto the
 * Messenger bus, gated by a short-TTL enqueue dedupe sentinel. The handler
 * classifies tier and acquires the appropriate lock before re-running the
 * resolver.
 *
 * When the queue flag is false, the legacy inline path runs verbatim —
 * `isGuardedByHerd` short-circuit, then the per-marker / per-lock branches —
 * so an operator who disables the queue via
 * `persistent_refresh_queue_enabled: false` gets the inline-refresh path. The
 * two branches are independent; the queue-enabled branch never falls through to
 * the inline path.
 */
class PersistentCacheRefreshOnTerminateListener implements EventSubscriberInterface
{
    private bool $emittedEmptyClassifierWarning = false;

    public function __construct(
        private WebserviceController $controller,
        private GraphQLService $graphQlService,
        private LocaleServiceInterface $localeService,
        private Factory $modelFactory,
        private LongRunningHelper $longRunningHelper,
        private ResponseServiceInterface $responseService,
        private ContainerBagInterface $container,
        private OperationClassifier $classifier,
        private ?LockFactory $lockFactory = null,
        private ?MessageBusInterface $bus = null
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 0 pinned: must run before InProgressLockReleaseListener
        // (-100) so the refresh sub-request fires while the parent worker
        // still owns the in-progress markers.
        return [
            KernelEvents::TERMINATE => ['onKernelTerminate', 0],
        ];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->get('_datahub_persistent_refresh')) {
            return;
        }

        $cfg = $this->container->get('pimcore_data_hub');
        $graphql = is_array($cfg) ? ($cfg['graphql'] ?? []) : [];
        $useQueue = (bool)($graphql['persistent_refresh_queue_enabled'] ?? false);

        if ($useQueue) {
            $this->dispatchToBus($request, $graphql);

            return;
        }

        $this->runInline($request, $graphql);
    }

    /**
     * @param array<string, mixed> $graphql
     */
    private function dispatchToBus(Request $request, array $graphql): void
    {
        if ($this->bus === null) {
            Logger::warning('datahub.refresh_dispatch: queue enabled but no MessageBus available');

            return;
        }

        try {
            $payload = (string)$request->getContent();
            $client = (string)$request->attributes->get('clientname', '');
            $op = null;
            $in = json_decode($payload, true) ?: [];
            if (isset($in['operationName']) && is_string($in['operationName'])) {
                $op = $in['operationName'];
            }
            $enqueueTtl = max(1, (int)($graphql['persistent_enqueue_dedupe_ttl'] ?? 60));
            $dedupeKey = $this->buildEnqueueDedupeKey($request);
            $existingEnqueue = $this->cacheLoad($dedupeKey);
            if ($existingEnqueue !== false && $existingEnqueue !== null) {
                return;
            }
            $this->cacheSave(1, $dedupeKey, ['datahub_graphql_persistent'], $enqueueTtl);
            $strategy = (string)($graphql['persistent_refresh_priority_strategy'] ?? 'oldest_refreshed_at_first');
            if ($strategy === 'oldest_refreshed_at_first_with_weight_bands'
                && !$this->emittedEmptyClassifierWarning
                && !$this->classifier->hasAnyOperations()
            ) {
                $this->emittedEmptyClassifierWarning = true;
                Logger::warning('datahub.refresh_dispatch: band-offset strategy active but OperationClassifier has zero loaded operations — verify bundle config');
            }
            $refreshedAt = null;
            $priorityWeight = $op !== null ? $this->classifier->getPriorityWeight($op) : null;
            if ($strategy === 'oldest_refreshed_at_first' || $strategy === 'oldest_refreshed_at_first_with_weight_bands') {
                $attr = $request->attributes->get('_datahub_persistent_refreshed_at');
                $refreshedAt = is_int($attr) && $attr > 0 ? $attr : time();
            }
            $this->bus->dispatch(new PersistentRefreshMessage($client, $payload, $op, $refreshedAt, $priorityWeight));
        } catch (\Throwable $e) {
            Logger::error('datahub.refresh_dispatch: queue dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * Read helper – separated for testability so the dedupe path can be
     * exercised without booting the Pimcore kernel.
     *
     * @return mixed
     */
    protected function cacheLoad(string $key)
    {
        return PimcoreCache::load($key);
    }

    /**
     * @param mixed $value
     */
    protected function cacheSave($value, string $key, array $tags, int $ttl): void
    {
        PimcoreCache::save($value, $key, $tags, $ttl, 1, true);
    }

    /**
     * @param array<string, mixed> $graphql
     */
    private function runInline(Request $request, array $graphql): void
    {
        if ($this->isGuardedByHerd($request, $graphql)) {
            $this->runRefresh($request);

            return;
        }

        $lockEnabled = (bool)($graphql['persistent_refresh_lock_enabled'] ?? true);
        $lockTtl = max(1, (int)($graphql['persistent_refresh_lock_ttl'] ?? 120));

        $lock = null;
        $markerKey = null;
        if ($lockEnabled) {
            if ($this->lockFactory !== null) {
                $lockKey = $this->buildRefreshMarkerKey($request);

                try {
                    $lock = $this->lockFactory->createLock($lockKey, $lockTtl, false);
                    if (!$lock->acquire(false)) {
                        return;
                    }
                    LockSignalRefresher::arm($lock, $lockTtl, max(1, (int) floor($lockTtl / 2)));
                } catch (\Throwable $e) {
                    Logger::warning('DataHub persistent refresh: lock acquire failed: ' . $e->getMessage());
                    $lock = null;
                }
            }
            if ($lock === null) {
                $markerKey = $this->buildRefreshMarkerKey($request);
                $existing = PimcoreCache::load($markerKey);
                if ($existing !== false && $existing !== null) {
                    return;
                }
                PimcoreCache::save(1, $markerKey, ['datahub_graphql_persistent'], $lockTtl, 1, true);
            }
        }

        try {
            $this->runRefresh($request);
        } finally {
            if ($lock !== null) {
                LockSignalRefresher::disarm();

                try {
                    $lock->release();
                } catch (\Throwable $e) {
                    Logger::warning('DataHub persistent refresh: lock release failed: ' . $e->getMessage());
                }
            }
            if ($markerKey) {
                try {
                    PimcoreCache::remove($markerKey);
                } catch (\Throwable $e) {
                    Logger::warning('DataHub persistent refresh: marker cleanup failed: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Run the SWR refresh against a freshly-built Request. Reusing the
     * original Request would leak its STALE-path attributes into the inner
     * call and would let the herd guard 503 the refresh sub-request; the
     * bypass attribute on the fresh Request prevents that.
     */
    private function runRefresh(Request $originalRequest): void
    {
        $body = (string)$originalRequest->getContent();
        $client = (string)$originalRequest->attributes->get('clientname', '');

        $fresh = Request::create('/datahub/graphql', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $fresh->attributes->set('clientname', $client);
        $fresh->attributes->set('_datahub_persistent_refresh', true);
        $fresh->attributes->set('_datahub_bypass_in_progress_guard', true);

        try {
            $this->controller->webonyxAction(
                $this->graphQlService,
                $this->localeService,
                $this->modelFactory,
                $fresh,
                $this->longRunningHelper,
                $this->responseService
            );
        } catch (\Throwable $e) {
            Logger::error('DataHub persistent refresh: controller invocation failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $graphql
     */
    private function isGuardedByHerd(Request $request, array $graphql): bool
    {
        $enabled = (bool)($graphql['in_progress_protection_enabled'] ?? false);
        if (!$enabled) {
            return false;
        }
        $list = (array)($graphql['in_progress_queries'] ?? []);
        $list = array_values(array_filter($list, static fn ($v) => is_string($v) && $v !== ''));
        if (!$list) {
            return false;
        }
        $input = json_decode($request->getContent(), true) ?: [];
        $op = $input['operationName'] ?? null;
        if (!$op) {
            return false;
        }

        return in_array($op, $list, true);
    }

    private function buildRefreshMarkerKey(Request $request): string
    {
        $client = (string)$request->attributes->get('clientname', '');
        $body = (string)$request->getContent();

        return PersistentOutputCacheService::computeSwrRefreshLockKey($client, $body);
    }

    private function buildEnqueueDedupeKey(Request $request): string
    {
        $client = (string)$request->attributes->get('clientname', '');
        $body = (string)$request->getContent();

        return PersistentOutputCacheService::computeEnqueueDedupeKey($client, $body);
    }
}
