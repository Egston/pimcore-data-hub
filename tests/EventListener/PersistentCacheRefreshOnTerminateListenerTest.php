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

use PHPUnit\Framework\TestCase;
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

final class PersistentCacheRefreshOnTerminateListenerTest extends TestCase
{
    private function makeFakeController(callable $callback): WebserviceController
    {
        // Build a lightweight subclass that calls the provided callback instead of full controller logic
        return new class($callback) extends WebserviceController {
            public function __construct(private $cb)
            {
            }

            public function webonyxAction(
                \Pimcore\Bundle\DataHubBundle\GraphQL\Service $service,
                \Pimcore\Localization\LocaleServiceInterface $localeService,
                \Pimcore\Model\Factory $modelFactory,
                \Symfony\Component\HttpFoundation\Request $request,
                \Pimcore\Helper\LongRunningHelper $longRunningHelper,
                \Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface $responseService
            ) {
                // Simulate a freshly computed response and call provided callback (e.g., asserting a save)
                $response = new \Symfony\Component\HttpFoundation\JsonResponse(['ok' => true]);
                ($this->cb)($request, $response);

                return $response;
            }
        };
    }

    private function makeListener(array $graphqlConfig, WebserviceController $controller = null, ?\Symfony\Component\Lock\LockFactory $lockFactory = null): PersistentCacheRefreshOnTerminateListener
    {
        $controller = $controller ?: $this->createMock(WebserviceController::class);
        $graphQlService = $this->createMock(GraphQLService::class);
        $localeService = $this->createMock(LocaleServiceInterface::class);
        $factory = $this->createMock(Factory::class);
        $longRunningHelper = $this->createMock(LongRunningHelper::class);
        $responseService = new class implements ResponseServiceInterface {
            public function removeCorsHeaders(\Symfony\Component\HttpFoundation\JsonResponse $response): void
            {
            }

            public function addCorsHeaders(\Symfony\Component\HttpFoundation\JsonResponse $response): void
            {
            }

            public function addHitMissHeaders(\Symfony\Component\HttpFoundation\JsonResponse $response, bool $isCacheHit): void
            {
            }
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
            $container,
            $lockFactory
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

        $lockFactory = $this->createMock(\Symfony\Component\Lock\LockFactory::class);
        $lockFactory->expects($this->never())->method('createLock');

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->once())
            ->method('webonyxAction');

        $listener = $this->makeListener($graphql, $controller, $lockFactory);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpA',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $event = $this->makeTerminateEvent($req);
        $listener->onKernelTerminate($event);
    }

    public function testOnTerminateLockReleasedWhenControllerThrows(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => false,
            'persistent_refresh_lock_enabled' => true,
            'persistent_refresh_lock_ttl' => 60,
            'in_progress_protection_enabled' => false,
        ];

        $lockStore = new \Symfony\Component\Lock\Store\InMemoryStore();
        $lockFactory = new \Symfony\Component\Lock\LockFactory($lockStore);

        $controller = $this->createMock(WebserviceController::class);
        $controller->method('webonyxAction')
            ->willThrowException(new \RuntimeException('Controller failure'));

        $listener = $this->makeListener($graphql, $controller, $lockFactory);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'Any',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        // Lock must be released even when the controller throws; a second invocation must be able to acquire it.
        $controller2 = $this->createMock(WebserviceController::class);
        $controller2->expects($this->once())->method('webonyxAction');
        $this->makeListener($graphql, $controller2, $lockFactory)
            ->onKernelTerminate($this->makeTerminateEvent($req));
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

    public function testOnTerminateInvokesSavePathViaController(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => false,
            'persistent_refresh_lock_enabled' => false,
            'in_progress_protection_enabled' => false,
        ];

        $called = false;
        $fakeController = $this->makeFakeController(function (Request $req, \Symfony\Component\HttpFoundation\JsonResponse $res) use (&$called) {
            // In a real controller, PersistentOutputCacheService::postHandle would be called here
            $called = true;
        });

        $listener = $this->makeListener($graphql, $fakeController);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'Any',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $event = $this->makeTerminateEvent($req);
        $listener->onKernelTerminate($event);

        $this->assertTrue($called, 'Controller did not invoke save path callback');
    }

    public function testOnTerminateUsesLockFactoryAndReleasesOnFinish(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => false,
            'persistent_refresh_lock_enabled' => true,
            'persistent_refresh_lock_ttl' => 60,
            'in_progress_protection_enabled' => false,
        ];

        $lockStore = new \Symfony\Component\Lock\Store\InMemoryStore();
        $lockFactory = new \Symfony\Component\Lock\LockFactory($lockStore);

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->once())->method('webonyxAction');

        $listener = $this->makeListener($graphql, $controller, $lockFactory);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'Any',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        // Lock must be released — a second listener invocation can re-acquire and run again.
        $controller2 = $this->createMock(WebserviceController::class);
        $controller2->expects($this->once())->method('webonyxAction');
        $this->makeListener($graphql, $controller2, $lockFactory)
            ->onKernelTerminate($this->makeTerminateEvent($req));
    }

    public function testOnTerminateBowsOutWhenLockContended(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => false,
            'persistent_refresh_lock_enabled' => true,
            'persistent_refresh_lock_ttl' => 60,
            'in_progress_protection_enabled' => false,
        ];

        $lockFactory = new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore());

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'Any',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        // Mirror PersistentCacheRefreshOnTerminateListener::buildRefreshMarkerKey for the no-attrs path.
        $bodyHash = hash('sha256', 'client:c1' . "\n" . (string)$req->getContent());
        $blocking = $lockFactory->createLock('datahub_persistent_refresh_lock_' . $bodyHash, 60, false);
        $this->assertTrue($blocking->acquire(false));

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->never())->method('webonyxAction');

        $this->makeListener($graphql, $controller, $lockFactory)
            ->onKernelTerminate($this->makeTerminateEvent($req));

        $blocking->release();
    }
}
