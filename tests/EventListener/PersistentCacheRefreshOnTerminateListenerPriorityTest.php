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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class PersistentCacheRefreshOnTerminateListenerPriorityTest extends TestCase
{
    /**
     * @param array<string, mixed> $graphqlConfig
     */
    private function makeListener(
        array $graphqlConfig,
        WebserviceController $controller,
        MessageBusInterface $bus
    ): PersistentCacheRefreshOnTerminateListener {
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

    private function makeTerminateEvent(Request $request): TerminateEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new TerminateEvent($kernel, $request, new Response('ok'));
    }

    public function testDispatchThreadsRefreshedAtAttributeIntoMessage(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_refresh_priority_strategy' => 'oldest_refreshed_at_first',
            'persistent_enqueue_dedupe_ttl' => 60,
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $controller = $this->createMock(WebserviceController::class);
        $listener = $this->makeListener($graphql, $controller, $bus);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpAttr',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);
        $req->attributes->set('_datahub_persistent_refreshed_at', 1700001234);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(PersistentRefreshMessage::class, $dispatched[0]);
        self::assertSame(1700001234, $dispatched[0]->refreshedAt);
    }

    public function testDispatchFallsBackToTimeWhenAttributeAbsent(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_refresh_priority_strategy' => 'oldest_refreshed_at_first',
            'persistent_enqueue_dedupe_ttl' => 60,
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $controller = $this->createMock(WebserviceController::class);
        $listener = $this->makeListener($graphql, $controller, $bus);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpFallback',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);

        $before = time();
        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        self::assertCount(1, $dispatched);
        self::assertNotNull($dispatched[0]->refreshedAt);
        self::assertGreaterThanOrEqual($before, $dispatched[0]->refreshedAt);
        self::assertLessThanOrEqual($before + 5, $dispatched[0]->refreshedAt);
        self::assertNull($dispatched[0]->priorityWeight);
    }

    public function testDispatchThreadsClassifiedPriorityWeightIntoMessage(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_refresh_priority_strategy' => 'oldest_refreshed_at_first',
            'persistent_enqueue_dedupe_ttl' => 60,
            'operations' => [
                'OpClassified' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'priority_weight' => 5,
                ],
            ],
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $controller = $this->createMock(WebserviceController::class);
        $listener = $this->makeListener($graphql, $controller, $bus);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpClassified',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);
        $req->attributes->set('_datahub_persistent_refreshed_at', 1700001234);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        self::assertCount(1, $dispatched);
        self::assertSame(5, $dispatched[0]->priorityWeight);
        self::assertSame(1700001234, $dispatched[0]->refreshedAt);
    }

    public function testDisabledStrategyDispatchesUnclassifiedOpWithNullScoreFields(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_refresh_priority_strategy' => 'disabled',
            'persistent_enqueue_dedupe_ttl' => 60,
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $controller = $this->createMock(WebserviceController::class);
        $listener = $this->makeListener($graphql, $controller, $bus);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpUnclassified',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);
        $req->attributes->set('_datahub_persistent_refreshed_at', 1700001234);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        self::assertCount(1, $dispatched);
        self::assertNull($dispatched[0]->refreshedAt);
        self::assertNull($dispatched[0]->priorityWeight);
    }

    public function testDisabledStrategyDispatchesClassifiedOpWithThreadedWeight(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_refresh_priority_strategy' => 'disabled',
            'persistent_enqueue_dedupe_ttl' => 60,
            'operations' => [
                'OpClassified' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'priority_weight' => 5,
                ],
            ],
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $controller = $this->createMock(WebserviceController::class);
        $listener = $this->makeListener($graphql, $controller, $bus);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpClassified',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);
        $req->attributes->set('_datahub_persistent_refreshed_at', 1700001234);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        self::assertCount(1, $dispatched);
        self::assertSame(5, $dispatched[0]->priorityWeight);
        self::assertNull($dispatched[0]->refreshedAt);
    }

    public function testDispatchUnderBandStrategyThreadsClassifiedWeight(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_refresh_priority_strategy' => 'oldest_refreshed_at_first_with_weight_bands',
            'persistent_enqueue_dedupe_ttl' => 60,
            'operations' => [
                'OpClassified' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'priority_weight' => 5,
                ],
            ],
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $controller = $this->createMock(WebserviceController::class);
        $listener = $this->makeListener($graphql, $controller, $bus);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'OpClassified',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);
        $req->attributes->set('_datahub_persistent_refreshed_at', 1700001234);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        self::assertCount(1, $dispatched);
        self::assertSame(5, $dispatched[0]->priorityWeight);
        self::assertSame(1700001234, $dispatched[0]->refreshedAt);
    }

    public function testDispatchWithBandStrategyAndEmptyClassifierStillDispatches(): void
    {
        $graphql = [
            'persistent_refresh_queue_enabled' => true,
            'persistent_refresh_priority_strategy' => 'oldest_refreshed_at_first_with_weight_bands',
            'persistent_enqueue_dedupe_ttl' => 60,
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $controller = $this->createMock(WebserviceController::class);
        $listener = $this->makeListener($graphql, $controller, $bus);

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'AnyOp',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->attributes->set('_datahub_persistent_refresh', true);
        $req->attributes->set('_datahub_persistent_refreshed_at', 1700001234);

        $listener->onKernelTerminate($this->makeTerminateEvent($req));

        self::assertCount(1, $dispatched);
        self::assertNull($dispatched[0]->priorityWeight);
        self::assertSame(1700001234, $dispatched[0]->refreshedAt);
    }
}
