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

use Codeception\Test\Unit;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class OutputCacheServiceTest extends Unit
{
    protected $container;

    protected $eventDispatcher;

    protected $request;

    protected $sut;

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
        // Configure guard to use 'request' strategy
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'output_cache_enabled' => true,
                'in_progress_protection_enabled' => true,
                'in_progress_queries' => ['Op'],
                'in_progress_key_strategy' => 'request',
                'in_progress_ttl' => 5,
            ],
        ]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        $sut = new OutputCacheService($container, $eventDispatcher);

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
            ->setConstructorArgs([$container, $eventDispatcher])
            ->onlyMethods(['saveToCache'])
            ->getMock();
        $sut2->method('saveToCache')->willReturnCallback(function () {});
        $sut2->save($reqA, new JsonResponse(['data' => ['ok' => true]]));
    }

    public function testBypassGuardForBackgroundRefresh()
    {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'output_cache_enabled' => true,
                'in_progress_protection_enabled' => true,
                'in_progress_queries' => ['Op'],
                'in_progress_key_strategy' => 'request',
                'in_progress_ttl' => 5,
            ],
        ]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);

        $sut = new OutputCacheService($container, $eventDispatcher);

        $req = Request::create('/api', 'POST', [], [], [], [], json_encode([
            'query' => 'query Op($id: ID!){node(id:$id){id}}',
            'variables' => ['id' => 1],
            'operationName' => 'Op',
        ]));
        $req->attributes->set('_datahub_bypass_in_progress_guard', true);

        $reject = $sut->maybeRejectOrAcquire($req);
        $this->assertNull($reject, 'Background refresh must bypass herd guard');
    }
}
