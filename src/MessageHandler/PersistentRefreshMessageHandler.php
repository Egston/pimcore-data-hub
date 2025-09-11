<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\MessageHandler;

use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQLService;
use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Factory;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Lock\LockFactory;

#[AsMessageHandler]
final class PersistentRefreshMessageHandler
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

    public function __invoke(PersistentRefreshMessage $message): void
    {
        $cfg = $this->container->get('pimcore_data_hub');
        $graphql = $cfg['graphql'] ?? [];

        $useOpLock = false;
        $opLockTtl = max(1, (int)($graphql['persistent_refresh_operation_lock_ttl'] ?? 120));
        $herdEnabled = (bool)($graphql['in_progress_protection_enabled'] ?? false);
        $keyStrategy = (string)($graphql['in_progress_key_strategy'] ?? 'request');

        $operation = $message->operationName;
        if ($herdEnabled && $keyStrategy === 'operation' && $operation) {
            $list = (array)($graphql['in_progress_queries'] ?? []);
            $list = array_values(array_filter($list, static fn($v) => is_string($v) && $v !== ''));
            $useOpLock = in_array($operation, $list, true);
        }

        $opLock = null;
        $reqLock = null;
        if ($useOpLock && $this->lockFactory) {
            try {
                $opLock = $this->lockFactory->createLock('datahub_refresh_op:' . $operation, $opLockTtl, false);
                if (!$opLock->acquire(false)) {
                    throw new RecoverableMessageHandlingException('Operation refresh is in progress, retry later');
                }
            } catch (\Throwable $e) {
                // If locking fails unexpectedly, proceed without op lock to avoid losing refresh; no exception
                $opLock = null;
            }
        }

        try {
            $request = Request::create('/datahub/graphql', 'POST', [], [], [], [], $message->bodyJson);
            $request->attributes->set('clientname', $message->client);
            $request->attributes->set('_datahub_persistent_refresh', true);
            $request->attributes->set('_datahub_bypass_in_progress_guard', true);

            // request-scoped dedupe lock to avoid parallel identical refreshes
            if ($this->lockFactory) {
                $reqResource = 'datahub_refresh_req:' . hash('sha256', $message->client . "\n" . $message->bodyJson);
                try {
                    $reqLock = $this->lockFactory->createLock($reqResource, $opLockTtl, false);
                    if (!$reqLock->acquire(false)) {
                        // Another worker is refreshing this exact request; drop message
                        return;
                    }
                } catch (\Throwable $e) {
                    $reqLock = null;
                }
            }

            $this->controller->webonyxAction(
                $this->graphQlService,
                $this->localeService,
                $this->modelFactory,
                $request,
                $this->longRunningHelper,
                $this->responseService
            );
        } finally {
            if ($opLock) {
                try { $opLock->release(); } catch (\Throwable $e) {}
            }
            if ($reqLock) {
                try { $reqLock->release(); } catch (\Throwable $e) {}
            }
        }
    }
}
