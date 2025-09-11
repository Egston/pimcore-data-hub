<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQLService;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Factory;
use Pimcore\Cache as PimcoreCache;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

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
        private ContainerBagInterface $container
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onKernelTerminate',
        ];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->get('_datahub_persistent_refresh')) {
            return;
        }

        // If an operation is already guarded by herd protection, we don't need an extra refresh lock.
        if ($this->isGuardedByHerd($request)) {
            try {
                $this->controller->webonyxAction(
                    $this->graphQlService,
                    $this->localeService,
                    $this->modelFactory,
                    $request,
                    $this->longRunningHelper,
                    $this->responseService
                );
            } catch (\Throwable $e) {
                // best-effort
            }
            return;
        }

        $cfg = $this->container->get('pimcore_data_hub');
        $graphql = $cfg['graphql'] ?? [];
        $lockEnabled = (bool)($graphql['persistent_refresh_lock_enabled'] ?? true);
        $lockTtl = max(1, (int)($graphql['persistent_refresh_lock_ttl'] ?? 120));

        $markerKey = null;
        if ($lockEnabled) {
            $markerKey = $this->buildRefreshMarkerKey($request);
            $existing = PimcoreCache::load($markerKey);
            if ($existing !== false && $existing !== null) {
                return; // another refresh is in progress
            }
            PimcoreCache::save(1, $markerKey, ['datahub_graphql_persistent'], $lockTtl, 1, true);
        }

        try {
            $this->controller->webonyxAction(
                $this->graphQlService,
                $this->localeService,
                $this->modelFactory,
                $request,
                $this->longRunningHelper,
                $this->responseService
            );
        } catch (\Throwable $e) {
            // Do not break terminate; background refresh best-effort
        } finally {
            if ($markerKey) {
                try {
                    if (method_exists(PimcoreCache::class, 'remove')) {
                        PimcoreCache::remove($markerKey);
                    } else {
                        PimcoreCache::save(null, $markerKey, [], 0, 1, true);
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
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
        $list = array_values(array_filter($list, static fn($v) => is_string($v) && $v !== ''));
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
}
