<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service;

use Codeception\Test\Unit;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PersistentOutputCacheServiceTest extends Unit
{
    private ContainerBagInterface $container;

    private function makeContainer(array $graphql): ContainerBagInterface
    {
        $c = $this->createMock(ContainerBagInterface::class);
        $c->method('get')->willReturn(['graphql' => $graphql]);
        return $c;
    }

    private function makeRequest(string $client, array $body): Request
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], $payload);
        $req->attributes->set('clientname', $client);
        $req->headers->set('Content-Type', 'application/json');
        return $req;
    }

    private function makeResponseService(): ResponseServiceInterface
    {
        return new class implements ResponseServiceInterface {
            public function removeCorsHeaders(JsonResponse $response): void {}
            public function addCorsHeaders(JsonResponse $response): void { $response->headers->set('Access-Control-Allow-Origin', '*'); }
            public function addHitMissHeaders(JsonResponse $response, bool $isCacheHit): void {}
        };
    }

    public function testDisabledDoesNothing(): void
    {
        $service = new PersistentOutputCacheService($this->makeContainer([
            'persistent_output_cache_enabled' => false,
            'output_cache_lifetime' => 30,
        ]));

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);

        $this->assertNull($service->preHandle($request, $this->makeResponseService()));
        $this->assertNull($service->postHandle($request, new JsonResponse(['ok' => true])));
    }

    public function testFreshHitReturnsImmediatelyAndRefreshes(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
            'persistent_output_cache_guard_only' => true,
            'in_progress_queries' => ['TestOp'],
        ];

        // Partial mock to control cache IO
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg)])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $now = time();
        $meta = [
            'refreshedAt' => $now,
            'client' => 'c1',
            'operation' => 'TestOp',
        ];
        $payload = ['data' => ['x' => 1]];

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return $payload;
            }
            return null;
        });

        // expect a single meta refresh save (not payload rewrite)
        $service->expects($this->once())->method('cacheSave')
            ->with($this->callback(fn($k) => str_starts_with($k, 'persistent_output_meta_')));

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);

        $response = $service->preHandle($request, $this->makeResponseService());
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('HIT', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testStaleHitReturnsStaleImmediately(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
            'persistent_output_cache_guard_only' => true,
            'in_progress_queries' => ['TestOp'],
        ];

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg)])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $meta = [
            'refreshedAt' => time() - 100,
            'client' => 'c1',
            'operation' => 'TestOp',
        ];
        $payload = ['data' => ['x' => 1]];

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if ($key === 'datahub_graphql_output_last_invalidation_ts') {
                return time(); // recent invalidation -> stale
            }
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return $payload;
            }
            return null;
        });

        $service->expects($this->any())->method('cacheSave');

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);

        // preHandle should return the stale response immediately and mark for background refresh
        $pre = $service->preHandle($request, $this->makeResponseService());
        $this->assertInstanceOf(JsonResponse::class, $pre);
        $this->assertSame('STALE', $pre->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $this->assertTrue((bool)$request->attributes->get('_datahub_persistent_refresh'));
    }

    public function testGuardOnlyBlocksWhenOperationNotListed(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
            'persistent_output_cache_guard_only' => true,
            'in_progress_queries' => ['OtherOp'], // TestOp not listed
        ];

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg)])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $meta = [
            'refreshedAt' => time(),
            'client' => 'c1',
            'operation' => 'TestOp',
        ];
        $payload = ['data' => ['x' => 1]];

        // Even if keys exist, guardOnly should prevent use when op not listed
        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return $payload;
            }
            return null;
        });

        $service->expects($this->never())->method('cacheSave');

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $pre = $service->preHandle($request, $this->makeResponseService());
        $this->assertNull($pre);
    }

    public function testPostHandleSavesMetaAndPayloadWithTagsAndTtls(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,
            'persistent_output_cache_guard_only' => false,
        ];

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg)])
            ->onlyMethods(['cacheSave'])
            ->getMock();

        $saved = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value', 'tags', 'ttl');
            return null;
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $fresh = new JsonResponse(['data' => ['x' => 2]]);

        $service->postHandle($request, $fresh);

        // Find one payload save and one meta save with expected TTLs and tags
        $foundPayload = false;
        $foundMeta = false;
        foreach ($saved as $call) {
            if (str_starts_with($call['key'], 'persistent_output_payload_')) {
                $foundPayload = true;
                $this->assertSame(3456, $call['ttl']);
                $this->assertContains('datahub_graphql_persistent', $call['tags']);
                $this->assertContains('datahub_graphql_op:TestOp', $call['tags']);
                $this->assertContains('datahub_graphql_client:c1', $call['tags']);
            }
            if (str_starts_with($call['key'], 'persistent_output_meta_')) {
                $foundMeta = true;
                $this->assertSame(12, $call['ttl']);
                $this->assertContains('datahub_graphql_persistent', $call['tags']);
                $this->assertContains('datahub_graphql_op:TestOp', $call['tags']);
                $this->assertContains('datahub_graphql_client:c1', $call['tags']);
            }
        }
        $this->assertTrue($foundPayload, 'Payload save not observed');
        $this->assertTrue($foundMeta, 'Meta save not observed');
    }

    public function testGuardOnlyFalseAllowsWithoutOperationName(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
            'persistent_output_cache_guard_only' => false,
        ];

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg)])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $meta = [
            'refreshedAt' => time(),
            'client' => 'c1',
            'operation' => '',
        ];
        $payload = ['data' => ['x' => 1]];

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return $payload;
            }
            return null;
        });

        $service->expects($this->once())
            ->method('cacheSave') // meta refresh
            ->with($this->callback(fn($k) => str_starts_with($k, 'persistent_output_meta_')));

        $request = $this->makeRequest('c1', ['query' => '{ __typename }']); // no operationName
        $pre = $service->preHandle($request, $this->makeResponseService());
        $this->assertInstanceOf(JsonResponse::class, $pre);
        $this->assertSame('HIT', $pre->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
    }

    public function testSavePersistentSavesCanonicalInMeta(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,
            'persistent_output_cache_guard_only' => false,
        ];

        $saved = [];
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg)])
            ->onlyMethods(['cacheSave'])
            ->getMock();

        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);
        $fresh = new JsonResponse(['data' => ['x' => 3]]);

        $service->savePersistent($request, $fresh);

        $metaEntries = array_values(array_filter($saved, fn($c) => str_starts_with($c['key'], 'persistent_output_meta_')));
        $this->assertNotEmpty($metaEntries);
        $meta = $metaEntries[0]['value'];
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('canonical', $meta);
        $this->assertIsString($meta['canonical']);
        $this->assertNotSame('', $meta['canonical']);
    }
}
