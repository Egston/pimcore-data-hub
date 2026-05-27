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

namespace Pimcore\Bundle\DataHubBundle\Tests\MessageHandler;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQLService;
use Pimcore\Bundle\DataHubBundle\Lock\LockFactoryResolver;
use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\MessageHandler\PersistentRefreshMessageHandler;
use Pimcore\Bundle\DataHubBundle\Service\DependencyCollector;
use Pimcore\Bundle\DataHubBundle\Service\Granularity;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\OutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Bundle\DataHubBundle\Service\Tier;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Factory;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

final class PersistentRefreshMessageHandlerTest extends TestCase
{
    /**
     * @param array<string, Tier>        $tiers
     * @param array<string, Granularity> $granularities
     */
    private function makeClassifier(array $tiers, array $granularities = []): OperationClassifier
    {
        $operations = [];
        foreach ($tiers as $name => $tier) {
            $granularity = $granularities[$name] ?? Granularity::LIST;
            $operations[$name] = [
                'tier' => $tier->value,
                'granularity' => $granularity->value,
            ];
        }
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => ['operations' => $operations],
        ]);

        return new OperationClassifier($container);
    }

    /**
     * @param array<string, mixed> $graphqlConfig
     */
    private function makeHandler(
        OperationClassifier $classifier,
        LockFactory $lockFactory,
        WebserviceController $controller,
        array $graphqlConfig = []
    ): PersistentRefreshMessageHandler {
        $resolver = new class($lockFactory) extends LockFactoryResolver {
            public function __construct(private LockFactory $factory)
            {
            }

            public function resolve(): ?object
            {
                return $this->factory;
            }
        };

        $graphQlService = $this->createMock(GraphQLService::class);
        $localeService = $this->createMock(LocaleServiceInterface::class);
        // Pimcore\Model\Factory + LongRunningHelper are `final`; PHPUnit's mock
        // engine cannot double them. They're passed straight to the mocked
        // controller and never observed, so a no-arg constructorless instance
        // is sufficient for the unit-test surface.
        $modelFactory = (new \ReflectionClass(Factory::class))->newInstanceWithoutConstructor();
        $longRunningHelper = (new \ReflectionClass(LongRunningHelper::class))->newInstanceWithoutConstructor();
        $responseService = $this->createMock(ResponseServiceInterface::class);
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => $graphqlConfig]);

        return new PersistentRefreshMessageHandler(
            $classifier,
            $resolver,
            $controller,
            $graphQlService,
            $localeService,
            $modelFactory,
            $longRunningHelper,
            $responseService,
            $container
        );
    }

    public function testInvokeOnHerdGuardedTierAcquiresOpNameLockAndCallsController(): void
    {
        $classifier = $this->makeClassifier(['OpHerd' => Tier::HERD_GUARDED]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::once())->method('webonyxAction');

        $handler = $this->makeHandler($classifier, $lockFactory, $controller, ['persistent_refresh_lock_ttl' => 60]);

        $body = json_encode(['operationName' => 'OpHerd', 'query' => '{ __typename }']);
        $msg = new PersistentRefreshMessage('c1', (string)$body, 'OpHerd');

        $handler($msg);

        // After the handler returns, the lock must have been released — verify by acquiring it.
        $opLockKey = OutputCacheService::computeOperationLockKey('OpHerd');
        $followup = $lockFactory->createLock($opLockKey, 60, false);
        self::assertTrue($followup->acquire(false), 'op-name lock was not released');
        $followup->release();
    }

    public function testInvokeOnSwrOnlyTierAcquiresQueryHashLockAndCallsController(): void
    {
        $classifier = $this->makeClassifier(['OpSwr' => Tier::SWR_ONLY]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::once())->method('webonyxAction');

        $handler = $this->makeHandler($classifier, $lockFactory, $controller, ['persistent_refresh_lock_ttl' => 60]);

        $body = json_encode(['operationName' => 'OpSwr', 'query' => '{ a }']);
        $msg = new PersistentRefreshMessage('c1', (string)$body, 'OpSwr');

        $handler($msg);

        $swrKey = PersistentOutputCacheService::computeSwrRefreshLockKey('c1', (string)$body);
        $followup = $lockFactory->createLock($swrKey, 60, false);
        self::assertTrue($followup->acquire(false), 'swr-only query-hash lock was not released');
        $followup->release();

        // The op-name lock space must be untouched by an SWR_ONLY refresh.
        $opLockKey = OutputCacheService::computeOperationLockKey('OpSwr');
        $opLock = $lockFactory->createLock($opLockKey, 60, false);
        self::assertTrue($opLock->acquire(false), 'op-name lock unexpectedly held by SWR_ONLY refresh');
        $opLock->release();
    }

    public function testInvokeOnNeitherTierLogsAndReturns(): void
    {
        $classifier = $this->makeClassifier([]); // empty: every op is Tier::NEITHER
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::never())->method('webonyxAction');

        $handler = $this->makeHandler($classifier, $lockFactory, $controller);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpUnknown"}', 'OpUnknown');

        $handler($msg);
    }

    public function testInvokeThrowsRecoverableWhenLockContended(): void
    {
        $classifier = $this->makeClassifier(['OpBusy' => Tier::HERD_GUARDED]);
        $lockFactory = new LockFactory(new InMemoryStore());

        // Pre-acquire using the literal controller lock resource shape — byte-equal
        // to OutputCacheService::acquireAtomicLock()'s 'datahub_inprogress:' prefix.
        // Using the literal here (not the helper) pins the contract: a regression
        // that silently changed the helper's separator would still fail this test.
        $blocking = $lockFactory->createLock('datahub_inprogress:' . md5('op_OpBusy'), 60, false);
        self::assertTrue($blocking->acquire(false));

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::never())->method('webonyxAction');

        $handler = $this->makeHandler($classifier, $lockFactory, $controller, ['persistent_refresh_lock_ttl' => 60]);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpBusy"}', 'OpBusy');

        $this->expectException(RecoverableMessageHandlingException::class);

        try {
            $handler($msg);
        } finally {
            $blocking->release();
        }
    }

    public function testInvokeReleasesLockOnControllerThrow(): void
    {
        $classifier = $this->makeClassifier(['OpFail' => Tier::HERD_GUARDED]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->method('webonyxAction')
            ->willThrowException(new \RuntimeException('resolver blew up'));

        $handler = $this->makeHandler($classifier, $lockFactory, $controller, ['persistent_refresh_lock_ttl' => 60]);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpFail"}', 'OpFail');

        // The handler swallows non-Recoverable throws and releases the lock in `finally`.
        $handler($msg);

        $opLockKey = OutputCacheService::computeOperationLockKey('OpFail');
        $followup = $lockFactory->createLock($opLockKey, 60, false);
        self::assertTrue($followup->acquire(false), 'lock was not released after controller throw');
        $followup->release();
    }

    public function testInvokeLogsButDoesNotPersistOnErrorsOnlyResponse(): void
    {
        $classifier = $this->makeClassifier(['OpErr' => Tier::HERD_GUARDED]);
        $lockFactory = new LockFactory(new InMemoryStore());

        // Fake controller that returns an errors-only JSON response. The
        // handler must not throw — postHandle (invoked inside the controller
        // in production) is the layer that refuses errors-only payloads; the
        // handler's contract is just to invoke the controller and release.
        $fakeController = new class extends WebserviceController {
            public bool $invoked = false;

            public function __construct()
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
                $this->invoked = true;

                return new \Symfony\Component\HttpFoundation\JsonResponse([
                    'errors' => [['message' => 'something blew up']],
                ]);
            }
        };

        $handler = $this->makeHandler($classifier, $lockFactory, $fakeController, ['persistent_refresh_lock_ttl' => 60]);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpErr"}', 'OpErr');

        $handler($msg);

        self::assertTrue($fakeController->invoked, 'controller must still be invoked on errors-only response');

        // Lock must be released after a clean (non-throwing) controller return.
        $opLockKey = OutputCacheService::computeOperationLockKey('OpErr');
        $followup = $lockFactory->createLock($opLockKey, 60, false);
        self::assertTrue($followup->acquire(false), 'lock was not released after errors-only response');
        $followup->release();
    }

    public function testInvokeRethrowsRecoverableFromController(): void
    {
        $classifier = $this->makeClassifier(['OpRecover' => Tier::HERD_GUARDED]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->method('webonyxAction')
            ->willThrowException(new RecoverableMessageHandlingException('transient'));

        $handler = $this->makeHandler($classifier, $lockFactory, $controller, ['persistent_refresh_lock_ttl' => 60]);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpRecover"}', 'OpRecover');

        $this->expectException(RecoverableMessageHandlingException::class);
        $handler($msg);
    }

    public function testInvokeResetsDependencyCollectorOnNeitherTier(): void
    {
        $classifier = $this->makeClassifier([]); // every op is NEITHER — handler returns early after reset
        $lockFactory = new LockFactory(new InMemoryStore());
        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::never())->method('webonyxAction');

        $resolver = new class($lockFactory) extends LockFactoryResolver {
            public function __construct(private LockFactory $factory)
            {
            }

            public function resolve(): ?object
            {
                return $this->factory;
            }
        };

        $resetCalls = 0;
        $collector = new class($resetCalls) extends DependencyCollector {
            public function __construct(private int &$callCounter)
            {
            }

            public function reset(): void
            {
                ++$this->callCounter;
                parent::reset();
            }
        };

        $graphQlService = $this->createMock(GraphQLService::class);
        $localeService = $this->createMock(LocaleServiceInterface::class);
        $modelFactory = (new \ReflectionClass(Factory::class))->newInstanceWithoutConstructor();
        $longRunningHelper = (new \ReflectionClass(LongRunningHelper::class))->newInstanceWithoutConstructor();
        $responseService = $this->createMock(ResponseServiceInterface::class);
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => []]);

        $handler = new PersistentRefreshMessageHandler(
            $classifier,
            $resolver,
            $controller,
            $graphQlService,
            $localeService,
            $modelFactory,
            $longRunningHelper,
            $responseService,
            $container,
            $collector
        );

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpNeither"}', 'OpNeither');
        $handler($msg);

        self::assertSame(1, $resetCalls, 'DependencyCollector::reset() must run even on NEITHER-tier early exit');
    }

    public function testInvokeResetsDependencyCollectorOnContention(): void
    {
        $classifier = $this->makeClassifier(['OpContend' => Tier::HERD_GUARDED]);
        $lockFactory = new LockFactory(new InMemoryStore());

        // Pre-hold the lock so the handler throws RecoverableMessageHandlingException early.
        $blocking = $lockFactory->createLock('datahub_inprogress:' . md5('op_OpContend'), 60, false);
        self::assertTrue($blocking->acquire(false));

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::never())->method('webonyxAction');

        $resolver = new class($lockFactory) extends LockFactoryResolver {
            public function __construct(private LockFactory $factory)
            {
            }

            public function resolve(): ?object
            {
                return $this->factory;
            }
        };

        $resetCalls = 0;
        $collector = new class($resetCalls) extends DependencyCollector {
            public function __construct(private int &$callCounter)
            {
            }

            public function reset(): void
            {
                ++$this->callCounter;
                parent::reset();
            }
        };

        $graphQlService = $this->createMock(GraphQLService::class);
        $localeService = $this->createMock(LocaleServiceInterface::class);
        $modelFactory = (new \ReflectionClass(Factory::class))->newInstanceWithoutConstructor();
        $longRunningHelper = (new \ReflectionClass(LongRunningHelper::class))->newInstanceWithoutConstructor();
        $responseService = $this->createMock(ResponseServiceInterface::class);
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => ['persistent_refresh_lock_ttl' => 60]]);

        $handler = new PersistentRefreshMessageHandler(
            $classifier,
            $resolver,
            $controller,
            $graphQlService,
            $localeService,
            $modelFactory,
            $longRunningHelper,
            $responseService,
            $container,
            $collector
        );

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpContend"}', 'OpContend');

        try {
            $handler($msg);
            self::fail('Expected RecoverableMessageHandlingException');
        } catch (RecoverableMessageHandlingException) {
            // expected
        } finally {
            $blocking->release();
        }

        self::assertSame(1, $resetCalls, 'DependencyCollector::reset() must run even on lock-contention early exit');
    }

    public function testInvokeResetsDependencyCollectorOnEntry(): void
    {
        $classifier = $this->makeClassifier(['OpReset' => Tier::SWR_ONLY]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::once())->method('webonyxAction');

        $resolver = new class($lockFactory) extends LockFactoryResolver {
            public function __construct(private LockFactory $factory)
            {
            }

            public function resolve(): ?object
            {
                return $this->factory;
            }
        };

        $resetCalls = 0;
        $collector = new class($resetCalls) extends DependencyCollector {
            public function __construct(private int &$callCounter)
            {
            }

            public function reset(): void
            {
                ++$this->callCounter;
                parent::reset();
            }
        };

        $graphQlService = $this->createMock(GraphQLService::class);
        $localeService = $this->createMock(LocaleServiceInterface::class);
        // Pimcore\Model\Factory + LongRunningHelper are `final`; PHPUnit's mock
        // engine cannot double them. They're passed straight to the mocked
        // controller and never observed, so a no-arg constructorless instance
        // is sufficient for the unit-test surface.
        $modelFactory = (new \ReflectionClass(Factory::class))->newInstanceWithoutConstructor();
        $longRunningHelper = (new \ReflectionClass(LongRunningHelper::class))->newInstanceWithoutConstructor();
        $responseService = $this->createMock(ResponseServiceInterface::class);
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => []]);

        $handler = new PersistentRefreshMessageHandler(
            $classifier,
            $resolver,
            $controller,
            $graphQlService,
            $localeService,
            $modelFactory,
            $longRunningHelper,
            $responseService,
            $container,
            $collector
        );

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpReset"}', 'OpReset');

        $handler($msg);

        self::assertSame(1, $resetCalls, 'DependencyCollector::reset() must run at handler entry');
    }

    private function makeCooldownHandler(
        OperationClassifier $classifier,
        LockFactory $lockFactory,
        WebserviceController $controller,
        PersistentOutputCacheService $persistentCache,
        ?\Symfony\Component\Messenger\MessageBusInterface $bus = null
    ): PersistentRefreshMessageHandler {
        $resolver = new class($lockFactory) extends LockFactoryResolver {
            public function __construct(private LockFactory $factory)
            {
            }

            public function resolve(): ?object
            {
                return $this->factory;
            }
        };

        $graphQlService = $this->createMock(GraphQLService::class);
        $localeService = $this->createMock(LocaleServiceInterface::class);
        $modelFactory = (new \ReflectionClass(Factory::class))->newInstanceWithoutConstructor();
        $longRunningHelper = (new \ReflectionClass(LongRunningHelper::class))->newInstanceWithoutConstructor();
        $responseService = $this->createMock(ResponseServiceInterface::class);
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => ['persistent_refresh_lock_ttl' => 60]]);

        return new PersistentRefreshMessageHandler(
            $classifier,
            $resolver,
            $controller,
            $graphQlService,
            $localeService,
            $modelFactory,
            $longRunningHelper,
            $responseService,
            $container,
            null,
            $bus,
            $persistentCache
        );
    }

    public function testSuccessfulRefreshOfDeliverAtMessageClearsCooldownSentinel(): void
    {
        $classifier = $this->makeClassifier(['OpCooldown' => Tier::SWR_ONLY]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::once())->method('webonyxAction');

        // Key-order differs from canonical form so entryHashFromBody's
        // canonicalisation step is load-bearing in this assertion.
        $body = '{"variables":{"z":3,"a":1},"operationName":"OpCooldown"}';
        $expectedHash = PersistentOutputCacheService::entryHashFromBody('c1', $body);

        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->expects(self::once())->method('clearOperationCooldown')->with($expectedHash);

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache);

        $msg = new PersistentRefreshMessage('c1', $body, 'OpCooldown', time(), null, time() + 21600);
        $handler($msg);
    }

    public function testSuccessfulRefreshOfNullDeliverAtMessageDoesNotClearCooldownSentinel(): void
    {
        $classifier = $this->makeClassifier(['OpPlain' => Tier::SWR_ONLY]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::once())->method('webonyxAction');

        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->expects(self::never())->method('clearOperationCooldown');

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpPlain"}', 'OpPlain', time(), null, null);
        $handler($msg);
    }

    public function testFailedRefreshOfDeliverAtMessageDoesNotClearCooldownSentinel(): void
    {
        $classifier = $this->makeClassifier(['OpFail' => Tier::SWR_ONLY]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->method('webonyxAction')
            ->willThrowException(new \RuntimeException('controller failure'));

        // Stale + past cooldown so the trailing pop fires; controller then fails.
        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->method('loadEntryMeta')->willReturn(['refreshedAt' => 1, 'invalidatedAt' => 100]);
        $persistentCache->method('isEntryStaleWithWatermark')->willReturn(true);
        $persistentCache->method('isPastCooldown')->willReturn(true);
        $persistentCache->expects(self::never())->method('clearOperationCooldown');

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpFail"}', 'OpFail', time(), null, time() + 21600);
        $handler($msg);
    }

    public function testTrailingPopCancelsWhenEntryNoLongerStale(): void
    {
        $classifier = $this->makeCooldownClassifier('OpCancel', 21600);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::never())->method('webonyxAction');

        $body = '{"operationName":"OpCancel"}';
        $hash = PersistentOutputCacheService::entryHashFromBody('c1', $body);

        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->method('loadEntryMeta')->willReturn(['refreshedAt' => 200, 'invalidatedAt' => 100]);
        $persistentCache->method('isEntryStaleWithWatermark')->willReturn(false);
        $persistentCache->expects(self::once())->method('clearOperationCooldown')->with($hash);

        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache, $bus);

        $msg = new PersistentRefreshMessage('c1', $body, 'OpCancel', time(), null, time() + 21600);
        $handler($msg);
    }

    public function testTrailingPopRearmsWhenStaleButWithinCooldown(): void
    {
        $classifier = $this->makeCooldownClassifier('OpRearm', 21600);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::never())->method('webonyxAction');

        $body = '{"operationName":"OpRearm"}';
        $lastRefreshAt = time() - 100;

        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->method('loadEntryMeta')->willReturn([
            'refreshedAt' => 1,
            'invalidatedAt' => 100,
            'lastRefreshAt' => $lastRefreshAt,
        ]);
        $persistentCache->method('isEntryStaleWithWatermark')->willReturn(true);
        $persistentCache->method('isPastCooldown')->willReturn(false);
        $persistentCache->expects(self::never())->method('clearOperationCooldown');

        $dispatched = [];
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new \Symfony\Component\Messenger\Envelope($msg);
        });

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache, $bus);

        $msg = new PersistentRefreshMessage('c1', $body, 'OpRearm', time(), null, time() + 21600);
        $handler($msg);

        self::assertCount(1, $dispatched, 're-arm dispatches exactly one trailing refresh');
        self::assertInstanceOf(PersistentRefreshMessage::class, $dispatched[0]);
        self::assertSame($lastRefreshAt + 21600, $dispatched[0]->deliverAt, 're-arm must date at current lastRefreshAt + cooldown');
    }

    public function testTrailingPopFiresWhenStaleAndPastCooldown(): void
    {
        $classifier = $this->makeCooldownClassifier('OpFire', 21600);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::once())->method('webonyxAction');

        $body = '{"operationName":"OpFire"}';

        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->method('loadEntryMeta')->willReturn(['refreshedAt' => 1, 'invalidatedAt' => 100]);
        $persistentCache->method('isEntryStaleWithWatermark')->willReturn(true);
        $persistentCache->method('isPastCooldown')->willReturn(true);
        $persistentCache->expects(self::once())->method('clearOperationCooldown');

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache);

        $msg = new PersistentRefreshMessage('c1', $body, 'OpFire', time(), null, time() + 21600);
        $handler($msg);
    }

    public function testWorkerThreadsRefreshStartTimestampOntoSyntheticRequest(): void
    {
        $classifier = $this->makeClassifier(['OpStart' => Tier::SWR_ONLY]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = new class extends WebserviceController {
            /** @var mixed */
            public $captured = 'unset';

            public function __construct()
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
                $this->captured = $request->attributes->get('_datahub_persistent_refresh_started_at');

                return new \Symfony\Component\HttpFoundation\JsonResponse(['data' => ['x' => 1]]);
            }
        };

        $handler = $this->makeHandler($classifier, $lockFactory, $controller, ['persistent_refresh_lock_ttl' => 60]);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpStart"}', 'OpStart');
        $handler($msg);

        self::assertIsInt($controller->captured, 'synthetic request must carry the refresh-start timestamp as an int');
        self::assertGreaterThan(0, $controller->captured);
        self::assertEqualsWithDelta(time(), $controller->captured, 2, 'refresh-start timestamp must be close to now');
    }

    public function testFreshnessGuardSkipsRefreshWhenEntryFreshWithinCooldown(): void
    {
        $classifier = $this->makeCooldownClassifier('OpFresh', 21600);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::never())->method('webonyxAction');

        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->method('loadEntryMeta')->willReturn(['refreshedAt' => 200, 'invalidatedAt' => 100, 'lastRefreshAt' => time()]);
        $persistentCache->method('isEntryStaleWithWatermark')->willReturn(false);
        $persistentCache->method('isPastCooldown')->willReturn(false);

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache);

        // Un-dated (read+warm duplicate) message: the freshness guard no-ops.
        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpFresh"}', 'OpFresh', time(), null, null);
        $handler($msg);
    }

    public function testFreshnessGuardFallsThroughToRefreshWhenStale(): void
    {
        $classifier = $this->makeCooldownClassifier('OpStale', 21600);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::once())->method('webonyxAction');

        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->method('loadEntryMeta')->willReturn(['refreshedAt' => 1, 'invalidatedAt' => 100]);
        $persistentCache->method('isEntryStaleWithWatermark')->willReturn(true);

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpStale"}', 'OpStale', time(), null, null);
        $handler($msg);
    }

    public function testFreshnessGuardFallsThroughToRefreshWhenStaleByWatermarkOnly(): void
    {
        $classifier = $this->makeClassifier(['OpWatermark' => Tier::SWR_ONLY]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::once())->method('webonyxAction');

        // Entry is fresh by per-entry timestamps (invalidatedAt <= refreshedAt) but
        // stale by the global fallback watermark (refreshedAt < fallbackWatermark).
        // The guard must honour the watermark path and fall through to refresh.
        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->method('loadEntryMeta')->willReturn(['refreshedAt' => 100, 'invalidatedAt' => 50]);
        $persistentCache->method('isEntryStaleWithWatermark')->willReturn(true);

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpWatermark"}', 'OpWatermark', null, null, null);
        $handler($msg);
    }

    public function testFreshnessGuardFallsThroughToRefreshOnNullMeta(): void
    {
        $classifier = $this->makeClassifier(['OpNullMeta' => Tier::SWR_ONLY]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::once())->method('webonyxAction');

        // null meta is uncertainty → fall through to refresh, never skip.
        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->method('loadEntryMeta')->willReturn(null);

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpNullMeta"}', 'OpNullMeta', time(), null, null);
        $handler($msg);
    }

    public function testFreshnessGuardFallsThroughToRefreshOnCacheFault(): void
    {
        $classifier = $this->makeClassifier(['OpFault' => Tier::SWR_ONLY]);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::once())->method('webonyxAction');

        // A cache fault during the guard must not skip the refresh.
        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->method('loadEntryMeta')->willThrowException(new \RuntimeException('cache down'));

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache);

        $msg = new PersistentRefreshMessage('c1', '{"operationName":"OpFault"}', 'OpFault', time(), null, null);
        $handler($msg);
    }

    public function testTrailingPopReDispatchesWarmOnReArm(): void
    {
        $classifier = $this->makeCooldownClassifier('OpReWarm', 21600);
        $lockFactory = new LockFactory(new InMemoryStore());

        $controller = $this->createMock(WebserviceController::class);
        $controller->expects(self::never())->method('webonyxAction');

        $body = '{"operationName":"OpReWarm"}';
        $lastRefreshAt = time() - 100;

        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->method('loadEntryMeta')->willReturn([
            'refreshedAt' => 1,
            'invalidatedAt' => 100,
            'lastRefreshAt' => $lastRefreshAt,
        ]);
        $persistentCache->method('isEntryStaleWithWatermark')->willReturn(true);
        $persistentCache->method('isPastCooldown')->willReturn(false);

        $dispatched = [];
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new \Symfony\Component\Messenger\Envelope($msg);
        });

        $handler = $this->makeCooldownHandler($classifier, $lockFactory, $controller, $persistentCache, $bus);

        $msg = new PersistentRefreshMessage('c1', $body, 'OpReWarm', time(), null, time() + 21600);
        $handler($msg);

        self::assertCount(1, $dispatched, 're-arm dispatches exactly one trailing refresh');
        self::assertInstanceOf(PersistentRefreshMessage::class, $dispatched[0]);
        self::assertFalse($dispatched[0]->readTriggered, 'a worker-re-dispatched trailing must be a warm, never a read');
        self::assertSame($lastRefreshAt + 21600, $dispatched[0]->deliverAt, 're-arm must date at current lastRefreshAt + cooldown');
    }

    private function makeCooldownClassifier(string $operationName, int $cooldownTtl): OperationClassifier
    {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => ['operations' => [
                $operationName => [
                    'tier' => Tier::SWR_ONLY->value,
                    'granularity' => Granularity::LIST->value,
                    'invalidation_cooldown_ttl' => $cooldownTtl,
                ],
            ]],
        ]);

        return new OperationClassifier($container);
    }
}
