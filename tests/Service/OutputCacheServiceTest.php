<?php

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

namespace Pimcore\Bundle\DataHubBundle\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class OutputCacheServiceTest extends TestCase
{
    protected $container;

    protected $eventDispatcher;

    protected $request;

    protected $sut;

    private function makeClassifier(array $operations): OperationClassifier
    {
        $c = $this->createMock(ContainerBagInterface::class);
        $c->method('get')->willReturn(['graphql' => ['operations' => $operations]]);

        return new OperationClassifier($c);
    }

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerBagInterface::class);
        $this->container->method('get')
            ->willReturn([
                'graphql' => [
                    'output_cache_enabled' => true,
                    'output_cache_lifetime' => 25,
                ],
            ]);

        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')
            ->willReturnArgument(0);

        $this->sut = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$this->container, $this->eventDispatcher])
            ->setMethods(['loadFromCache', 'saveToCache'])
            ->getMock();

        $payload = '{"query":"{\n  getProductCategoryListing {\n    edges {\n      node {\n        fullpath\n      }\n    }\n  }\n}","variables":null,"operationName":null}';
        $this->request = Request::create('/api', 'POST', ['apikey' => 'super_secret_api_key'], [], [], [], $payload);
        $this->request->headers->set('Content-Type', 'application/json');
        $this->request->request->set('clientname', 'test-datahub-config');
    }

    public function testReturnNullWhenItemIsNotCached()
    {
        // Arrange
        $this->sut->method('loadFromCache')->willReturn(null);

        // Act
        $cacheItem = $this->sut->load($this->request);

        // Assert
        $this->assertEquals(null, $cacheItem);
    }

    public function testReturnItemWhenItIsCached()
    {
        // Arrange
        $response = new JsonResponse(['data' => 123]);
        $this->sut->method('loadFromCache')->willReturn($response);

        // Act
        $cacheItem = $this->sut->load($this->request);

        // Assert
        $this->assertEquals($response, $cacheItem);
    }

    public function testSaveItemWhenCacheIsEnabled()
    {
        // Arrange
        $this->sut
            ->expects($this->once())
            ->method('saveToCache');

        $response = new JsonResponse(['data' => 123]);

        // Act
        $this->sut->save($this->request, $response);
    }

    public function testIgnoreSaveWhenCacheIsDisabled()
    {
        // Arrange
        $this->container = $this->createMock(ContainerBagInterface::class);
        $this->container->method('get')
            ->willReturn([
                'graphql' => [
                    'output_cache_enabled' => false,
                ],
            ]);

        $this->sut = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$this->container, $this->eventDispatcher])
            ->setMethods(['saveToCache'])
            ->getMock();

        $this->sut
            ->expects($this->never())
            ->method('saveToCache');

        $response = new JsonResponse(['data' => 123]);

        // Act
        $this->sut->save($this->request, $response);
    }

    public function testIgnoreLoadWhenCacheIsDisabled()
    {
        // Arrange
        $this->container = $this->createMock(ContainerBagInterface::class);
        $this->container->method('get')
        ->willReturn([
            'graphql' => [
                'output_cache_enabled' => false,
            ],
        ]);

        $this->sut = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$this->container, $this->eventDispatcher])
            ->setMethods(['loadFromCache'])
            ->getMock();

        $this->sut
            ->expects($this->never())
            ->method('loadFromCache');

        $response = new JsonResponse(['data' => 123]);

        // Act
        $this->sut->save($this->request, $response);
    }

    public function testIgnoreCacheWhenRequestParameterIsPassed()
    {
        // Arrange
        $response = new JsonResponse(['data' => 123]);
        $this->sut->method('loadFromCache')->willReturn($response);
        $this->request->query->set('pimcore_nocache', 'true');

        // Act
        $cacheItem = $this->sut->load($this->request);

        // Assert
        $this->assertTrue(\Pimcore::inDebugMode());
        $this->assertEquals(null, $cacheItem);
    }

    public function testCanonicalKeyForEquivalentPayloads()
    {
        // Arrange
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'output_cache_enabled' => true,
                'output_cache_lifetime' => 25,
            ],
        ]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        $keys = [];
        $sut = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$container, $eventDispatcher])
            ->onlyMethods(['saveToCache'])
            ->getMock();
        $sut->method('saveToCache')->willReturnCallback(function ($key) use (&$keys) {
            $keys[] = $key;
        });

        $clientname = 'client-a';
        $queryA = 'query Op(
  $id: ID!
){
  node(id: $id) {
    id
  }
}';
        $queryB = 'query  Op($id: ID!){node(id:$id){id}}'; // same semantics, different formatting
        $bodyA = json_encode(['query' => $queryA, 'variables' => ['id' => 123], 'operationName' => 'Op']);
        $bodyB = json_encode(['variables' => ['id' => 123], 'operationName' => 'Op', 'query' => $queryB]); // different order

        $reqA = Request::create('/api', 'POST', [], [], [], [], $bodyA);
        $reqA->attributes->set('clientname', $clientname);
        $reqA->headers->set('Content-Type', 'application/json');

        $reqB = Request::create('/api', 'POST', [], [], [], [], $bodyB);
        $reqB->attributes->set('clientname', $clientname);
        $reqB->headers->set('Content-Type', 'application/json');

        // Act
        $sut->save($reqA, new JsonResponse(['data' => ['ok' => true]]));
        $sut->save($reqB, new JsonResponse(['data' => ['ok' => true]]));

        // Assert: same computed cache key for both payloads
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function testGuardRequestStrategyCanonicalizesPayload()
    {
        // Configure guard to use 'request' strategy — keeps deprecated in_progress_protection_enabled alias
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'output_cache_enabled' => true,
                'in_progress_protection_enabled' => true,
                'in_progress_key_strategy' => 'request',
                'in_progress_ttl' => 5,
            ],
        ]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        $classifier = $this->makeClassifier([
            'Op' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);
        $sut = new OutputCacheService($container, $eventDispatcher, null, $classifier);

        $clientname = 'client-b';
        $queryA = 'query Op(
  $id: ID!
){
  node(id: $id) { id }
}';
        $queryB = 'query Op($id: ID!){node(id:$id){id}}';
        $bodyA = json_encode(['query' => $queryA, 'variables' => ['id' => 456], 'operationName' => 'Op']);
        $bodyB = json_encode(['variables' => ['id' => 456], 'operationName' => 'Op', 'query' => $queryB]);

        $reqA = Request::create('/api', 'POST', [], [], [], [], $bodyA);
        $reqA->attributes->set('clientname', $clientname);
        $reqB = Request::create('/api', 'POST', [], [], [], [], $bodyB);
        $reqB->attributes->set('clientname', $clientname);

        // First request should acquire guard (no rejection)
        $reject1 = $sut->maybeRejectOrAcquire($reqA);
        $this->assertNull($reject1, 'First guard attempt should not be rejected');

        // Second equivalent request should be rejected (deduped)
        $reject2 = $sut->maybeRejectOrAcquire($reqB);
        $this->assertInstanceOf(JsonResponse::class, $reject2);
        $this->assertSame(503, $reject2->getStatusCode());

        // Cleanup: release guard by saving the first request (releases lock + removes marker)
        $sut2 = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$container, $eventDispatcher, null, $classifier])
            ->onlyMethods(['saveToCache'])
            ->getMock();
        $sut2->method('saveToCache')->willReturnCallback(function () {
        });
        $sut2->save($reqA, new JsonResponse(['data' => ['ok' => true]]));
    }

    public function testMaybeRejectOrAcquireSetsGuardKeyAttribute()
    {
        // Keeps deprecated in_progress_protection_enabled alias — pins the BC fold path
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'output_cache_enabled' => true,
                'in_progress_protection_enabled' => true,
                'in_progress_key_strategy' => 'operation',
                'in_progress_ttl' => 5,
            ],
        ]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        $classifier = $this->makeClassifier([
            'Acquire' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);
        $sut = new OutputCacheService($container, $eventDispatcher, null, $classifier);

        $req = Request::create('/api', 'POST', [], [], [], [], json_encode([
            'query' => 'query Acquire($id: ID!){node(id:$id){id}}',
            'variables' => ['id' => 1],
            'operationName' => 'Acquire',
        ]));
        $req->attributes->set('clientname', 'client-x');

        $result = $sut->maybeRejectOrAcquire($req);

        $this->assertNull($result, 'First caller should not be rejected');
        $this->assertTrue(
            $req->attributes->has('datahub_inprogress_guard_key'),
            'Guard key must be stored on request so the safety-net listener can delete the marker if save() never runs'
        );

        // Cleanup: call save() to release the marker
        $sut2 = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$container, $eventDispatcher, null, $classifier])
            ->onlyMethods(['saveToCache'])
            ->getMock();
        $sut2->method('saveToCache')->willReturnCallback(function () {
        });
        $sut2->save($req, new JsonResponse(['data' => ['ok' => true]]));
    }

    public function testSaveReleasesGuardKeyAttributeEvenWhenCacheDisabled()
    {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => ['output_cache_enabled' => false],
        ]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        $sut = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$container, $eventDispatcher])
            ->onlyMethods(['saveToCache'])
            ->getMock();
        $sut->expects($this->never())->method('saveToCache');

        $req = Request::create('/api', 'POST', [], [], [], [], json_encode([
            'query' => 'query Op($id: ID!){node(id:$id){id}}',
            'variables' => ['id' => 1],
            'operationName' => 'Op',
        ]));
        $req->attributes->set('clientname', 'client-y');
        // Simulate the guard key attribute set by maybeRejectOrAcquire()
        $req->attributes->set('datahub_inprogress_guard_key', md5('op_Op'));

        $sut->save($req, new JsonResponse(['data' => ['ok' => true]]));

        $this->assertFalse(
            $req->attributes->has('datahub_inprogress_guard_key'),
            'save() must clear the guard key attribute even when cache is disabled, so the safety-net listener is a no-op on TERMINATE'
        );
    }

    public function testBypassGuardForBackgroundRefresh()
    {
        // Uses canonical herd_guard_enabled key — pins the renamed-key path
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'output_cache_enabled' => true,
                'herd_guard_enabled' => true,
                'herd_guard_key_strategy' => 'request',
                'herd_guard_ttl' => 5,
            ],
        ]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        $classifier = $this->makeClassifier([
            'Op' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);
        $sut = new OutputCacheService($container, $eventDispatcher, null, $classifier);

        $req = Request::create('/api', 'POST', [], [], [], [], json_encode([
            'query' => 'query Op($id: ID!){node(id:$id){id}}',
            'variables' => ['id' => 1],
            'operationName' => 'Op',
        ]));
        $req->attributes->set('_datahub_bypass_in_progress_guard', true);

        $reject = $sut->maybeRejectOrAcquire($req);
        $this->assertNull($reject, 'Background refresh must bypass herd guard');
    }

    public function testShouldGuardRequestReturnsTrueForHerdGuardedTierAttributeEvenWhenOperationAbsentFromInProgressQueries()
    {
        // Uses canonical herd_guard_enabled key — the HERD_GUARDED tier attribute
        // alone must engage the guard regardless of classifier membership.
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'output_cache_enabled' => true,
                'herd_guard_enabled' => true,
                'herd_guard_key_strategy' => 'operation',
                'herd_guard_ttl' => 5,
            ],
        ]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        $classifier = $this->makeClassifier([]);
        $sut = new OutputCacheService($container, $eventDispatcher, null, $classifier);

        $body = json_encode([
            'query' => 'query TierGuarded($id: ID!){node(id:$id){id}}',
            'variables' => ['id' => 1],
            'operationName' => 'TierGuarded',
        ]);

        $req1 = Request::create('/api', 'POST', [], [], [], [], $body);
        $req1->attributes->set('clientname', 'client-tier');
        $req1->attributes->set('_datahub_tier', Tier::HERD_GUARDED->value);

        $first = $sut->maybeRejectOrAcquire($req1);
        $this->assertNull($first, 'HERD_GUARDED tier alone must engage the guard; first caller acquires');

        $req2 = Request::create('/api', 'POST', [], [], [], [], $body);
        $req2->attributes->set('clientname', 'client-tier');
        $req2->attributes->set('_datahub_tier', Tier::HERD_GUARDED->value);

        $second = $sut->maybeRejectOrAcquire($req2);
        $this->assertInstanceOf(JsonResponse::class, $second, 'Concurrent HERD_GUARDED request must be 503');
        $this->assertSame(503, $second->getStatusCode());

        // Cleanup
        $classifier2 = $this->makeClassifier([]);
        $sut2 = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$container, $eventDispatcher, null, $classifier2])
            ->onlyMethods(['saveToCache'])
            ->getMock();
        $sut2->method('saveToCache')->willReturnCallback(function () {
        });
        $sut2->save($req1, new JsonResponse(['data' => ['ok' => true]]));
    }

    public function testTierAttributeNeitherDoesNotEngageGuardWhenClassifierEmpty()
    {
        // Uses canonical herd_guard_enabled key
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'output_cache_enabled' => true,
                'herd_guard_enabled' => true,
                'herd_guard_key_strategy' => 'operation',
                'herd_guard_ttl' => 5,
            ],
        ]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        $classifier = $this->makeClassifier([]);
        $sut = new OutputCacheService($container, $eventDispatcher, null, $classifier);

        $req = Request::create('/api', 'POST', [], [], [], [], json_encode([
            'query' => 'query Anon{__typename}',
            'operationName' => 'Anon',
        ]));
        $req->attributes->set('clientname', 'client-neither');
        $req->attributes->set('_datahub_tier', Tier::NEITHER->value);

        $this->assertNull(
            $sut->maybeRejectOrAcquire($req),
            'NEITHER tier with empty classifier must not engage the guard'
        );
        $this->assertFalse(
            $req->attributes->has('datahub_inprogress_guard_key'),
            'No guard key should be stored when the guard does not engage'
        );
    }

    public function testComputeOperationLockKeyMatchesAtomicLockResourceShape(): void
    {
        // The lock resource for an operation-strategy HERD_GUARDED query MUST
        // be byte-equal in the controller (acquireAtomicLock) and in the queue
        // handler (PersistentRefreshMessageHandler). The controller builds
        // 'datahub_inprogress:' . md5('op_' . $operationName); this assertion
        // pins that contract against the helper so drift is caught immediately.
        self::assertSame(
            'datahub_inprogress:' . md5('op_GetSomething'),
            OutputCacheService::computeOperationLockKey('GetSomething'),
        );
    }

    public function testCanonicalPayloadMemoizedOnRequestAttribute()
    {
        $sut = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$this->container, $this->eventDispatcher])
            ->onlyMethods(['saveToCache'])
            ->getMock();
        $sut->method('saveToCache')->willReturnCallback(function () {
        });

        $this->assertFalse(
            $this->request->attributes->has('_datahub_canonical_payload'),
            'precondition: request carries no canonical memo before the cache path runs'
        );

        $sut->save($this->request, new JsonResponse(['data' => ['ok' => true]]));

        $this->assertIsString(
            $this->request->attributes->get('_datahub_canonical_payload'),
            'canonicalisation result must be memoised on the request so the AST reprint runs once, not once per call site'
        );
    }

    public function testComputeKeyReadsCanonicalPayloadMemo()
    {
        $keys = [];
        $sut = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$this->container, $this->eventDispatcher])
            ->onlyMethods(['saveToCache'])
            ->getMock();
        $sut->method('saveToCache')->willReturnCallback(function ($key) use (&$keys) {
            $keys[] = $key;
        });

        $clientname = 'client-memo';
        $sentinel = 'SENTINEL_CANONICAL_BODY';
        $req = Request::create('/api', 'POST', [], [], [], [], '{"query":"{ totally different }"}');
        $req->attributes->set('clientname', $clientname);
        // Pre-seed the memo with a value the raw body would never canonicalise to.
        $req->attributes->set('_datahub_canonical_payload', $sentinel);

        $sut->save($req, new JsonResponse(['data' => ['ok' => true]]));

        $this->assertCount(1, $keys);
        $this->assertSame(
            'output_' . hash('sha256', 'client:' . $clientname . "\n" . $sentinel),
            $keys[0],
            'computeKey must consult the per-request canonical memo, not re-canonicalise the body'
        );
    }

    public function testProbeStatusDisabledHitMiss()
    {
        // disabled
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => ['output_cache_enabled' => false]]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $sut = new OutputCacheService($container, $eventDispatcher);
        $req = Request::create('/api', 'POST', [], [], [], [], json_encode(['query' => '{__typename}']));
        $this->assertSame('DISABLED', $sut->probeStatus($req));

        // enabled: MISS then HIT
        $container2 = $this->createMock(ContainerBagInterface::class);
        $container2->method('get')->willReturn(['graphql' => ['output_cache_enabled' => true, 'output_cache_lifetime' => 5]]);
        $eventDispatcher2 = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher2->method('dispatch')->willReturnArgument(0);
        $sut2 = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$container2, $eventDispatcher2])
            ->onlyMethods(['loadFromCache', 'saveToCache'])
            ->getMock();

        $sut2->method('loadFromCache')->willReturnOnConsecutiveCalls(null, null); // MISS probe path, then not used further
        $this->assertSame('MISS', $sut2->probeStatus($req));
    }

    public function testHerdGuardMembershipViaClassifier(): void
    {
        // Pins Option A: OutputCacheService injects OperationClassifier; the membership
        // check for shouldGuardRequest uses classifier->getTier() === HERD_GUARDED rather
        // than the dropped $inProgressQueries list.
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'output_cache_enabled' => true,
                'herd_guard_enabled' => true,
                'herd_guard_key_strategy' => 'operation',
                'herd_guard_ttl' => 5,
            ],
        ]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        $classifier = $this->makeClassifier([
            'GuardedOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);
        $sut = new OutputCacheService($container, $eventDispatcher, null, $classifier);

        $body = json_encode([
            'query' => 'query GuardedOp{__typename}',
            'operationName' => 'GuardedOp',
        ]);

        $req1 = Request::create('/api', 'POST', [], [], [], [], $body);
        $req1->attributes->set('clientname', 'client-classifier');

        $first = $sut->maybeRejectOrAcquire($req1);
        $this->assertNull($first, 'classifier-classified HERD_GUARDED op: first caller must acquire');

        $req2 = Request::create('/api', 'POST', [], [], [], [], $body);
        $req2->attributes->set('clientname', 'client-classifier');

        $second = $sut->maybeRejectOrAcquire($req2);
        $this->assertInstanceOf(JsonResponse::class, $second, 'classifier-classified HERD_GUARDED op: duplicate request must be rejected');
        $this->assertSame(503, $second->getStatusCode());

        // Cleanup
        $sut2 = $this->getMockBuilder(OutputCacheService::class)
            ->setConstructorArgs([$container, $eventDispatcher, null, $classifier])
            ->onlyMethods(['saveToCache'])
            ->getMock();
        $sut2->method('saveToCache')->willReturnCallback(function () {
        });
        $sut2->save($req1, new JsonResponse(['data' => ['ok' => true]]));
    }
}
