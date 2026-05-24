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

namespace Pimcore\Bundle\DataHubBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\EventListener\PersistentCacheRefreshOnTerminateListener;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQLService;
use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Factory;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class PersistentCacheRefreshOnTerminateListenerTest extends TestCase
{
    private function makeFakeController(callable $callback): WebserviceController
    {
        // Lightweight subclass that calls the provided callback instead of full controller logic.
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
                $response = new \Symfony\Component\HttpFoundation\JsonResponse(['ok' => true]);
                ($this->cb)($request, $response);

                return $response;
            }
        };
    }

    /**
     * @param array<string, mixed> $graphqlConfig
     */
    private function makeListener(
        array $graphqlConfig,
        ?WebserviceController $controller = null,
        ?\Symfony\Component\Lock\LockFactory $lockFactory = null,
        ?MessageBusInterface $bus = null
    ): PersistentCacheRefreshOnTerminateListener {
        $controller = $controller ?: $this->createMock(WebserviceController::class);
        $graphQlService = $this->createMock(GraphQLService::class);
        $localeService = $this->createMock(LocaleServiceInterface::class);
        // Pimcore\Model\Factory + LongRunningHelper are `final`; PHPUnit's mock
        // engine cannot double them. They're passed straight to the mocked
        // controller and never observed, so a no-arg constructorless instance
        // is sufficient for the unit-test surface.
        $factory = (new \ReflectionClass(Factory::class))->newInstanceWithoutConstructor();
        $longRunningHelper = (new \ReflectionClass(LongRunningHelper::class))->newInstanceWithoutConstructor();
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
        $classifier = new OperationClassifier($container);

        return new PersistentCacheRefreshOnTerminateListener(
            $controller,
            $graphQlService,
            $localeService,
            $factory,
            $longRunningHelper,
            $responseService,
            $container,
            $classifier,
            $lockFactory,
            $bus
        );
    }

    private function makeTerminateEvent(Request $request): TerminateEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $response = new Response('ok');

        return new TerminateEvent($kernel, $request, $response);
    }

    public function testGetSubscribedEventsReturnsPriorityZeroForTerminate(): void
    {
        $events = PersistentCacheRefreshOnTerminateListener::getSubscribedEvents();
        $this->assertArrayHasKey(KernelEvents::TERMINATE, $events);
        // Refresh must run before InProgressLockReleaseListener (-100) so the
        // parent worker still owns its in-progress markers when the refresh
        // sub-request fires.
        $this->assertSame(['onKernelTerminate', 0], $events[KernelEvents::TERMINATE]);
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
            'persistent_refresh_lock_enabled' => false,
            'in_progress_protection_enabled' => false,
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

        $lockKey = \Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService::computeSwrRefreshLockKey('c1', (string)$req->getContent());
        $blocking = $lockFactory->createLock($lockKey, 60, false);
        $this->assertTrue($blocking->acquire(false));

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->never())->method('webonyxAction');

        $this->makeListener($graphql, $controller, $lockFactory)
            ->onKernelTerminate($this->makeTerminateEvent($req));

        $blocking->release();
    }

    public function testOnTerminateDispatchesToBusWhenQueueEnabled(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_enqueue_dedupe_ttl' => 60,
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (object $msg) use (&$dispatched) {
                $dispatched[] = $msg;

                return new Envelope($msg);
            });

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->never())->method('webonyxAction');

        // The dedupe sentinel goes through \Pimcore\Cache, which raises without
        // a booted kernel; the listener swallows that and bows out silently.
        // Use the test-seam subclass so cacheLoad returns null and the dispatch
        // path runs to completion.
        $listener = $this->makeDedupeListener($graphql, $controller, $bus);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpQueue',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(PersistentRefreshMessage::class, $dispatched[0]);
        self::assertSame('OpQueue', $dispatched[0]->operationName);
        self::assertSame('c1', $dispatched[0]->client);
    }

    public function testOnTerminateDispatchEnqueueDedupesWithinTtl(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_enqueue_dedupe_ttl' => 60,
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->never())->method('webonyxAction');

        // In-memory cache seam: overrides the protected cacheLoad/cacheSave so
        // the dedupe sentinel persists between invocations under unit-test
        // bootstrap (no Pimcore container, so the real static facade is a no-op).
        $listener = $this->makeDedupeListener($graphql, $controller, $bus);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpDedupe',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));
        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(PersistentRefreshMessage::class, $dispatched[0]);
        self::assertSame('OpDedupe', $dispatched[0]->operationName);
    }

    public function testOnTerminateBusThrowDoesNotFallThroughToInline(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_enqueue_dedupe_ttl' => 60,
        ];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('transport down'));

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->never())->method('webonyxAction');

        $listener = $this->makeDedupeListener($graphql, $controller, $bus);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpBusThrow',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));
    }

    public function testOnTerminateQueueEnabledWithoutBusDoesNotFallThroughToInline(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_enqueue_dedupe_ttl' => 60,
        ];

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->never())->method('webonyxAction');

        // $bus = null: queue enabled but no Messenger bus wired
        $listener = $this->makeListener($graphql, $controller, null, null);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpNoBus',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));
    }

    public function testEnqueueDedupePassesPositiveTtlToCache(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_enqueue_dedupe_ttl' => 45,
        ];

        $savedTtls = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) {
            return new Envelope($msg);
        });

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects($this->never())->method('webonyxAction');

        $graphQlService = $this->createMock(GraphQLService::class);
        $localeService = $this->createMock(LocaleServiceInterface::class);
        $factory = (new \ReflectionClass(Factory::class))->newInstanceWithoutConstructor();
        $longRunningHelper = (new \ReflectionClass(LongRunningHelper::class))->newInstanceWithoutConstructor();
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
        $container->method('get')->willReturn(['graphql' => $graphql]);
        $classifier = new OperationClassifier($container);

        $listener = new class($controller, $graphQlService, $localeService, $factory, $longRunningHelper, $responseService, $container, $classifier, null, $bus) extends PersistentCacheRefreshOnTerminateListener {
            /** @var array<string, mixed> */
            private array $store = [];

            /** @var int[] */
            public array $recordedTtls = [];

            protected function cacheLoad(string $key)
            {
                return $this->store[$key] ?? null;
            }

            protected function cacheSave($value, string $key, array $tags, int $ttl): void
            {
                $this->recordedTtls[] = $ttl;
                $this->store[$key] = $value;
            }
        };

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpTtlCheck',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        self::assertCount(1, $listener->recordedTtls, 'cacheSave must be called once for the dedupe sentinel');
        self::assertGreaterThan(0, $listener->recordedTtls[0], 'dedupe sentinel TTL must be > 0');
        self::assertSame(45, $listener->recordedTtls[0], 'dedupe sentinel TTL must equal persistent_enqueue_dedupe_ttl');
    }

    /**
     * @param array<string, mixed> $graphqlConfig
     */
    private function makeDedupeListener(
        array $graphqlConfig,
        WebserviceController $controller,
        MessageBusInterface $bus
    ): PersistentCacheRefreshOnTerminateListener {
        $graphQlService = $this->createMock(GraphQLService::class);
        $localeService = $this->createMock(LocaleServiceInterface::class);
        // Pimcore\Model\Factory + LongRunningHelper are `final`; PHPUnit's mock
        // engine cannot double them. They're passed straight to the mocked
        // controller and never observed, so a no-arg constructorless instance
        // is sufficient for the unit-test surface.
        $factory = (new \ReflectionClass(Factory::class))->newInstanceWithoutConstructor();
        $longRunningHelper = (new \ReflectionClass(LongRunningHelper::class))->newInstanceWithoutConstructor();
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
        $classifier = new OperationClassifier($container);

        return new class($controller, $graphQlService, $localeService, $factory, $longRunningHelper, $responseService, $container, $classifier, null, $bus) extends PersistentCacheRefreshOnTerminateListener {
            /** @var array<string, mixed> */
            private array $store = [];

            protected function cacheLoad(string $key)
            {
                return $this->store[$key] ?? null;
            }

            protected function cacheSave($value, string $key, array $tags, int $ttl): void
            {
                $this->store[$key] = $value;
            }
        };
    }
}
