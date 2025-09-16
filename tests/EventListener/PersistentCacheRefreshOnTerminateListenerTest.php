<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use Codeception\Test\Unit;
use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQLService;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Factory;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PersistentCacheRefreshOnTerminateListenerTest extends Unit
{
    private function makeListener(array $graphqlConfig, WebserviceController $controller = null): PersistentCacheRefreshOnTerminateListener
    {
        $controller = $controller ?: $this->createMock(WebserviceController::class);
        $graphQlService = $this->createMock(GraphQLService::class);
        $localeService = $this->createMock(LocaleServiceInterface::class);
        $factory = $this->createMock(Factory::class);
        $longRunningHelper = $this->createMock(LongRunningHelper::class);
        $responseService = new class implements ResponseServiceInterface {
            public function removeCorsHeaders(\Symfony\Component\HttpFoundation\JsonResponse $response): void {}
            public function addCorsHeaders(\Symfony\Component\HttpFoundation\JsonResponse $response): void {}
            public function addHitMissHeaders(\Symfony\Component\HttpFoundation\JsonResponse $response, bool $isCacheHit): void {}
        };
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => $graphqlConfig]);

        return new PersistentCacheRefreshOnTerminateListener(
            $controller,
            $graphQlService,
            $localeService,
            $factory,
            $longRunningHelper,
            $responseService,
            $container
        );
    }

    private function makeTerminateEvent(Request $request): TerminateEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $response = new Response('ok');
        return new TerminateEvent($kernel, $request, $response);
    }

    public function testOnTerminateGuardedCallsController(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => false,
            'persistent_refresh_lock_enabled' => true,
            'in_progress_protection_enabled' => true,
            'in_progress_key_strategy' => 'operation',
            'in_progress_queries' => ['OpA'],
        ];

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->once())
            ->method('webonyxAction');

        $listener = $this->makeListener($graphql, $controller);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpA',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $event = $this->makeTerminateEvent($req);
        $listener->onKernelTerminate($event);
    }

    public function testOnTerminateSkipsWhenFlagNotSet(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => false,
            'persistent_refresh_lock_enabled' => false,
            'in_progress_protection_enabled' => false,
        ];

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->never())->method('webonyxAction');
        $listener = $this->makeListener($graphql, $controller);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpB',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        // flag not set -> listener must exit early

        $event = $this->makeTerminateEvent($req);
        $listener->onKernelTerminate($event);
    }

    public function testOnTerminateNonGuardedNoLockCallsController(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => false,
            'persistent_refresh_lock_enabled' => false, // avoid relying on Pimcore\Cache in test
            'in_progress_protection_enabled' => false,  // non-guarded path
        ];

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->once())->method('webonyxAction');
        $listener = $this->makeListener($graphql, $controller);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'Whatever',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $event = $this->makeTerminateEvent($req);
        $listener->onKernelTerminate($event);
    }
}

