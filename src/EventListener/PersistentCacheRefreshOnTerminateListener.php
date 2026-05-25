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

/**
 * After the response has been sent, refresh stale persistent cache in background.
 */
class PersistentCacheRefreshOnTerminateListener implements EventSubscriberInterface
{
    public function __construct(
        private WebserviceController $controller,
        private GraphQLService $graphQlService,
        private LocaleServiceInterface $localeService,
        private Factory $modelFactory,
        private LongRunningHelper $longRunningHelper,
        private ResponseServiceInterface $responseService,
        private ContainerBagInterface $container,
        private ?LockFactory $lockFactory = null
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
        $graphql = $cfg['graphql'] ?? [];
        $useQueue = (bool)($graphql['persistent_refresh_queue_enabled'] ?? false);

        if ($useQueue) {
            // Dispatch to Messenger; the handler will perform appropriate locking.
            // No local fallback — a misconfigured queue is louder this way.
            try {
                $bus = \Pimcore::getContainer()->get('messenger.default_bus');
                $payload = (string)$request->getContent();
                $client = (string)$request->attributes->get('clientname', '');
                $op = null;
                $in = json_decode($payload, true) ?: [];
                if (isset($in['operationName']) && is_string($in['operationName'])) {
                    $op = $in['operationName'];
                }
                $enqueueTtl = max(1, (int)($graphql['persistent_enqueue_dedupe_ttl'] ?? 60));
                $dedupeKey = $this->buildEnqueueDedupeKey($request);
                $existingEnqueue = PimcoreCache::load($dedupeKey);
                if ($existingEnqueue !== false && $existingEnqueue !== null) {
                    return; // already enqueued recently
                }
                PimcoreCache::save(1, $dedupeKey, ['datahub_graphql_persistent'], $enqueueTtl, 1, true);
                $msg = new \Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage($client, $payload, $op);
                $bus->dispatch($msg);
            } catch (\Throwable $e) {
                Logger::error('DataHub persistent refresh: queue dispatch failed: ' . $e->getMessage());
            }

            return;
        }

        // When herd protection is active for this operation, the in-progress guard already
        // serialises concurrent refreshes on the caller path; a second refresh lock here
        // would add no safety and would block the sub-request's own marker cleanup.
        if ($this->isGuardedByHerd($request)) {
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
                    // Keep the lock alive while the (possibly long) refresh query runs.
                    LockSignalRefresher::arm($lock, $lockTtl, max(1, (int) floor($lockTtl / 2)));
                } catch (\Throwable $e) {
                    Logger::warning('DataHub persistent refresh: lock acquire failed: ' . $e->getMessage());
                    $lock = null;
                }
            }
            if ($lock === null) {
                // Fallback: cache marker is not atomic and not renewable.
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
    private function runRefresh(\Symfony\Component\HttpFoundation\Request $originalRequest): void
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

    private function isGuardedByHerd(\Symfony\Component\HttpFoundation\Request $request): bool
    {
        $cfg = $this->container->get('pimcore_data_hub');
        $graphql = $cfg['graphql'] ?? [];
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

    private function buildRefreshMarkerKey(\Symfony\Component\HttpFoundation\Request $request): string
    {
        $metaKey = (string)$request->attributes->get('_datahub_persistent_meta_key');
        $payloadKey = (string)$request->attributes->get('_datahub_persistent_payload_key');
        if ($metaKey !== '' && $payloadKey !== '') {
            return 'datahub_persistent_refresh_lock_' . md5($metaKey . '|' . $payloadKey);
        }
        $client = (string)$request->attributes->get('clientname', '');
        $body = (string)$request->getContent();

        return 'datahub_persistent_refresh_lock_' . hash('sha256', 'client:' . $client . "\n" . $body);
    }

    private function buildEnqueueDedupeKey(\Symfony\Component\HttpFoundation\Request $request): string
    {
        $metaKey = (string)$request->attributes->get('_datahub_persistent_meta_key');
        $payloadKey = (string)$request->attributes->get('_datahub_persistent_payload_key');
        if ($metaKey !== '' && $payloadKey !== '') {
            return 'datahub_enqueue_req_' . md5($metaKey . '|' . $payloadKey);
        }
        $client = (string)$request->attributes->get('clientname', '');
        $body = (string)$request->getContent();

        return 'datahub_enqueue_req_' . hash('sha256', 'client:' . $client . "\n" . $body);
    }
}
