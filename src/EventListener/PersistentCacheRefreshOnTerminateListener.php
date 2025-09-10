<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQLService;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Factory;
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
        private ResponseServiceInterface $responseService
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
        }
    }
}
