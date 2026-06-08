<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PersistentOutputCacheServiceTest extends TestCase
{
    private function makeContainer(array $graphql): ContainerBagInterface
    {
        $c = $this->createMock(ContainerBagInterface::class);
        $c->method('get')->willReturn(['graphql' => $graphql]);

        return $c;
    }

    private function makeClassifier(array $operations): OperationClassifier
    {
        $c = $this->createMock(ContainerBagInterface::class);
        $c->method('get')->willReturn(['graphql' => ['operations' => $operations]]);

        return new OperationClassifier($c);
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
            public function removeCorsHeaders(JsonResponse $response): void
            {
            }

            public function addCorsHeaders(JsonResponse $response): void
            {
                $response->headers->set('Access-Control-Allow-Origin', '*');
            }

            public function addHitMissHeaders(JsonResponse $response, bool $isCacheHit): void
            {
            }
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
        // postHandle is void; assert it does not throw on disabled config
        $service->postHandle($request, new JsonResponse(['ok' => true]));
    }

    public function testFreshHitReturnsImmediatelyAndRefreshes(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        // Partial mock to control cache IO
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
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
            ->with($this->callback(fn ($k) => str_starts_with($k, 'persistent_output_meta_')));

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);

        $response = $service->preHandle($request, $this->makeResponseService());
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('HIT', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertTrue((bool)$request->attributes->get('_datahub_persistent_applies'), 'applies flag not set on fresh HIT');
    }

    public function testFreshHitBelowRepaintThresholdSkipsPayloadRewrite(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $now = time();
        $meta = [
            'refreshedAt' => $now - 1,
            'client' => 'c1',
            'operation' => 'TestOp',
            'payloadSavedAt' => $now - 100,
            'payloadTtl' => 3600,
            'tags' => ['datahub_graphql_persistent', 'datahub_graphql_obj_Foo_42'],
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

        $saves = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saves) {
            $saves[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $response = $service->preHandle($request, $this->makeResponseService());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('HIT', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $this->assertCount(1, $saves, 'below-threshold HIT must not rewrite the payload');
        $this->assertStringStartsWith('persistent_output_meta_', $saves[0]['key']);
    }

    public function testFreshHitAtOrAboveRepaintThresholdRewritesPayload(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $now = time();
        $storedTags = ['datahub_graphql_persistent', 'datahub_graphql_obj_Foo_42', 'datahub_graphql_class_Foo'];
        $meta = [
            'refreshedAt' => $now - 1,
            'client' => 'c1',
            'operation' => 'TestOp',
            'payloadSavedAt' => $now - 2000,
            'payloadTtl' => 3600,
            'tags' => $storedTags,
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

        $saves = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saves) {
            $saves[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $response = $service->preHandle($request, $this->makeResponseService());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('HIT', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $this->assertCount(2, $saves, 'at-threshold HIT must rewrite both payload and meta');

        $payloadSaves = array_values(array_filter($saves, fn ($s) => str_starts_with($s['key'], 'persistent_output_payload_')));
        $metaSaves = array_values(array_filter($saves, fn ($s) => str_starts_with($s['key'], 'persistent_output_meta_')));
        $this->assertCount(1, $payloadSaves);
        $this->assertCount(1, $metaSaves);

        $this->assertSame(3600, $payloadSaves[0]['ttl'], 'payload rewrite must reuse stored payloadTtl');
        $this->assertSame($storedTags, $payloadSaves[0]['tags'], 'payload rewrite must reuse stored tags');
        $this->assertSame($payload, $payloadSaves[0]['value']);

        $rewrittenMeta = $metaSaves[0]['value'];
        $this->assertGreaterThanOrEqual($now, (int)$rewrittenMeta['payloadSavedAt'], 'payloadSavedAt must advance to now after repaint');
    }

    public function testFreshHitAtExactThresholdRewritesPayload(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $now = time();
        $meta = [
            'refreshedAt' => $now - 1,
            'client' => 'c1',
            'operation' => 'TestOp',
            'payloadSavedAt' => $now - 1800,
            'payloadTtl' => 3600,
            'tags' => ['datahub_graphql_persistent'],
        ];

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta) {
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return ['data' => ['x' => 1]];
            }

            return null;
        });

        $saves = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saves) {
            $saves[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $service->preHandle($request, $this->makeResponseService());

        $this->assertCount(2, $saves, 'at-exact-threshold (>=) must trigger payload repaint');
    }

    public function testFreshHitOneSecondBelowThresholdSkipsPayloadRewrite(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $now = time();
        $meta = [
            'refreshedAt' => $now - 1,
            'client' => 'c1',
            'operation' => 'TestOp',
            'payloadSavedAt' => $now - 1799,
            'payloadTtl' => 3600,
            'tags' => ['datahub_graphql_persistent'],
        ];

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta) {
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return ['data' => ['x' => 1]];
            }

            return null;
        });

        $saves = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saves) {
            $saves[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $service->preHandle($request, $this->makeResponseService());

        $this->assertCount(1, $saves, 'one-second-below threshold must not rewrite the payload');
    }

    public function testFreshHitWithPayloadSavedAtButNoTtlSkipsRepaint(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $now = time();
        $meta = [
            'refreshedAt' => $now - 1,
            'client' => 'c1',
            'operation' => 'TestOp',
            'payloadSavedAt' => $now - 2000,
        ];

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta) {
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return ['data' => ['x' => 1]];
            }

            return null;
        });

        $saves = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saves) {
            $saves[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $service->preHandle($request, $this->makeResponseService());

        $this->assertCount(1, $saves, 'partial sidecar (payloadSavedAt without payloadTtl) must not trigger repaint');
    }

    public function testStaleHitReturnsStaleImmediately(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $meta = [
            'refreshedAt' => time() - 100,
            'client' => 'c1',
            'operation' => 'TestOp',
            'payloadSavedAt' => time() - 100,
            'payloadTtl' => 3600,
            'tags' => ['datahub_graphql_persistent'],
        ];
        $payload = ['data' => ['x' => 1]];

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if ($key === 'datahub_graphql_fallback_watermark_ts') {
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

        $service->expects($this->never())->method('cacheSave');

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);

        // preHandle should return the stale response immediately and mark for background refresh
        $pre = $service->preHandle($request, $this->makeResponseService());
        $this->assertInstanceOf(JsonResponse::class, $pre);
        $this->assertSame('STALE', $pre->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $this->assertTrue((bool)$request->attributes->get('_datahub_persistent_refresh'));
        $this->assertTrue((bool)$request->attributes->get('_datahub_persistent_applies'), 'applies flag not set on stale HIT');
    }

    public function testGateBlocksUnclassifiedOperation(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        // Classifier has OtherOp but not TestOp — TestOp must be blocked.
        $classifier = $this->makeClassifier([
            'OtherOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $meta = [
            'refreshedAt' => time(),
            'client' => 'c1',
            'operation' => 'TestOp',
        ];
        $payload = ['data' => ['x' => 1]];

        // Even if keys exist, gate must block when op not in classifier
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

    public function testGateBlocksRequestWithoutOperationName(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'SomeOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->expects($this->never())->method('cacheSave');

        // No operationName in the body — gate must reject
        $request = $this->makeRequest('c1', ['query' => '{ __typename }']);
        $pre = $service->preHandle($request, $this->makeResponseService());
        $this->assertNull($pre);
        $this->assertFalse(
            (bool)$request->attributes->get('_datahub_persistent_applies'),
            'gate must reject requests without an operationName'
        );
    }

    public function testPostHandleSavesMetaAndPayloadWithTagsAndTtls(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'swr_only', 'granularity' => 'single', 'ttl_override' => 3456],
        ]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheSave'])
            ->getMock();

        $saved = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
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
                $this->assertContains('datahub_graphql_op_TestOp', $call['tags']);
                $this->assertContains('datahub_graphql_client_c1', $call['tags']);
            }
            if (str_starts_with($call['key'], 'persistent_output_meta_')) {
                $foundMeta = true;
                $this->assertSame(12, $call['ttl']);
                $this->assertContains('datahub_graphql_persistent', $call['tags']);
                $this->assertContains('datahub_graphql_op_TestOp', $call['tags']);
                $this->assertContains('datahub_graphql_client_c1', $call['tags']);
            }
        }
        $this->assertTrue($foundPayload, 'Payload save not observed');
        $this->assertTrue($foundMeta, 'Meta save not observed');
    }

    public function testSavePersistentWritesRepaintSidecarFieldsInMeta(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 7200,
        ];
        $classifier = $this->makeClassifier([
            'Op' => ['tier' => 'swr_only', 'granularity' => 'single', 'ttl_override' => 7200],
        ]);

        $saved = [];
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturn(null);
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);
        $before = time();
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 4]]));
        $after = time();

        $metaEntries = array_values(array_filter($saved, fn ($c) => str_starts_with($c['key'], 'persistent_output_meta_')));
        $this->assertNotEmpty($metaEntries);
        $meta = $metaEntries[0]['value'];

        $this->assertIsArray($meta);
        $this->assertArrayHasKey('payloadSavedAt', $meta);
        $this->assertArrayHasKey('payloadTtl', $meta);
        $this->assertArrayHasKey('tags', $meta);

        $this->assertIsInt($meta['payloadSavedAt']);
        $this->assertGreaterThanOrEqual($before, $meta['payloadSavedAt']);
        $this->assertLessThanOrEqual($after, $meta['payloadSavedAt']);

        $this->assertSame(7200, $meta['payloadTtl'], 'stored payloadTtl must reflect classifier-resolved value');

        $this->assertIsArray($meta['tags']);
        $this->assertContains('datahub_graphql_persistent', $meta['tags']);
        $this->assertContains('datahub_graphql_op_Op', $meta['tags']);
        $this->assertContains('datahub_graphql_client_c1', $meta['tags']);
    }

    public function testSavePersistentSavesCanonicalInMeta(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,
        ];
        $classifier = $this->makeClassifier([
            'Op' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $saved = [];
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturn(null);
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);
        $fresh = new JsonResponse(['data' => ['x' => 3]]);

        $service->savePersistent($request, $fresh);

        $metaEntries = array_values(array_filter($saved, fn ($c) => str_starts_with($c['key'], 'persistent_output_meta_')));
        $this->assertNotEmpty($metaEntries);
        $meta = $metaEntries[0]['value'];
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('canonical', $meta);
        $this->assertIsString($meta['canonical']);
        $this->assertNotSame('', $meta['canonical']);
    }

    /**
     * Anchors the errors-only shapes (no `data`, null `data`, empty `data`,
     * all-null `data` members) that must not enter the persistent cache.
     *
     * @dataProvider provideErrorsOnlyPayloads
     */
    public function testSavePersistentRefusesErrorsOnlyResponse(array $payload): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,
        ];
        $classifier = $this->makeClassifier([
            'Op' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $saved = [];
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturn(null);
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);
        $service->savePersistent($request, new JsonResponse($payload));

        $this->assertSame([], $saved, 'no cache writes should occur for an errors-only payload');
    }

    public static function provideErrorsOnlyPayloads(): array
    {
        return [
            'errors-only, no data key' => [['errors' => [['message' => 'type definition X not found']]]],
            'errors with null data' => [['data' => null, 'errors' => [['message' => 'boom']]]],
            'errors with empty-array data' => [['data' => [], 'errors' => [['message' => 'boom']]]],
            // A resolver-thrown error nulls its field but keeps the data key,
            // so `data` is a non-empty array carrying nothing useful.
            'errors with all-null data members' => [['data' => ['getListing' => null], 'errors' => [['message' => 'invalid sortOrder']]]],
        ];
    }

    /**
     * Partial-success responses (`data` non-empty AND `errors` present) MUST
     * still be cached: the data is useful to clients and the errors are
     * deterministic against the input.
     */
    public function testSavePersistentCachesPartialSuccessWithErrors(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,
        ];
        $classifier = $this->makeClassifier([
            'Op' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $saved = [];
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturn(null);
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);
        $partial = new JsonResponse([
            'data' => ['someField' => 'value', 'failingField' => null],
            'errors' => [['message' => 'failingField could not be resolved']],
        ]);
        $service->savePersistent($request, $partial);

        $payloadEntries = array_values(array_filter($saved, fn ($c) => str_starts_with($c['key'], 'persistent_output_payload_')));
        $this->assertNotEmpty($payloadEntries, 'partial-success payload should be cached');
    }

    public function testSavePersistentCachesAllNullDataWithoutErrors(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,
        ];
        $classifier = $this->makeClassifier([
            'Op' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $saved = [];
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturn(null);
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);
        // All-null data but no errors key — a legitimate empty result, not a resolver failure.
        // The $hasErrors && !$hasUsefulData gate must not fire; the payload must be cached.
        $service->savePersistent($request, new JsonResponse(['data' => ['getListing' => null]]));

        $payloadEntries = array_values(array_filter($saved, fn ($c) => str_starts_with($c['key'], 'persistent_output_payload_')));
        $this->assertNotEmpty($payloadEntries, 'all-null data without errors must be cached');
    }

    public function testAppliesFlagSetOnMiss(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        // MISS: cacheLoad returns null for both meta and payload
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad'])
            ->getMock();
        $service->method('cacheLoad')->willReturn(null);

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $pre = $service->preHandle($request, $this->makeResponseService());
        $this->assertNull($pre, 'preHandle should not return on MISS');
        $this->assertTrue((bool)$request->attributes->get('_datahub_persistent_applies'), 'applies flag not set on MISS');
    }

    public function testProbeStatusHitMissStale(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        // HIT
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad'])
            ->getMock();

        $meta = ['refreshedAt' => time(), 'client' => 'c1'];
        $payload = ['data' => ['x' => 1]];
        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if ($key === 'datahub_graphql_fallback_watermark_ts') {
                return 0;
            }
            if (str_contains($key, 'meta_')) {
                return $meta;
            }
            if (str_contains($key, 'payload_')) {
                return $payload;
            }

            return null;
        });

        $req = $this->makeRequest('c1', ['query' => '{__typename}', 'operationName' => 'TestOp']);
        $probe = $service->probeStatus($req);
        $this->assertTrue($probe['applies']);
        $this->assertSame('HIT', $probe['status']);

        // STALE
        $service2 = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad'])
            ->getMock();
        $meta2 = ['refreshedAt' => time() - 100, 'client' => 'c1'];
        $service2->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta2, $payload) {
            if ($key === 'datahub_graphql_fallback_watermark_ts') {
                return time();
            }
            if (str_contains($key, 'meta_')) {
                return $meta2;
            }
            if (str_contains($key, 'payload_')) {
                return $payload;
            }

            return null;
        });
        $probe2 = $service2->probeStatus($req);
        $this->assertTrue($probe2['applies']);
        $this->assertSame('STALE', $probe2['status']);

        // MISS
        $service3 = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad'])
            ->getMock();
        $service3->method('cacheLoad')->willReturn(null);
        $probe3 = $service3->probeStatus($req);
        $this->assertTrue($probe3['applies']);
        $this->assertSame('MISS', $probe3['status']);
    }

    public function testSavePersistentUpdatesIndices(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 60,
            'persistent_output_cache_payload_ttl' => 3600,
        ];
        $classifier = $this->makeClassifier([
            'IdxOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
        ]);

        $saved = [];
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturn(null);
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value', 'tags', 'ttl');
        });

        $request = $this->makeRequest('clientA', [
            'query' => '{ __typename }',
            'operationName' => 'IdxOp',
        ]);
        $fresh = new JsonResponse(['data' => ['ok' => true]]);

        $service->savePersistent($request, $fresh);

        $keys = array_column($saved, 'key');
        $this->assertContains('datahub_graphql_persistent_index_all', $keys);
        $this->assertContains('datahub_graphql_persistent_index_client_clientA', $keys);
        $this->assertContains('datahub_graphql_persistent_index_op_IdxOp', $keys);

        // index entries use TTL null (no expiry); Symfony's CacheItem::expiresAfter(0)
        // means "expires now", which is why null is correct here, not 0.
        foreach ($saved as $call) {
            if (str_starts_with($call['key'], 'datahub_graphql_persistent_index_')) {
                $this->assertNull($call['ttl']);
                $this->assertContains('datahub_graphql_persistent', $call['tags']);
            }
        }
    }

    public function testBumpFallbackWatermarkStoresTimestamp(): void
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer([
                'persistent_output_cache_enabled' => true,
            ])])
            ->onlyMethods(['cacheSave'])
            ->getMock();

        $observed = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$observed) {
            $observed = compact('key', 'value', 'tags', 'ttl');
        });

        $service->bumpFallbackWatermark(123456);

        $this->assertSame('datahub_graphql_fallback_watermark_ts', $observed['key']);
        $this->assertSame(123456, $observed['value']);
        $this->assertNull($observed['ttl']);
        $this->assertContains('datahub_graphql_persistent_watermark', $observed['tags']);
    }

    public function testShouldUseSkipsNonPost(): void
    {
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);
        $service = new PersistentOutputCacheService($this->makeContainer([
            'persistent_output_cache_enabled' => true,
        ]), $classifier);

        $req = Request::create('/datahub/graphql', 'GET');
        $req->attributes->set('clientname', 'c1');
        $this->assertNull($service->preHandle($req, $this->makeResponseService()));
        $service->postHandle($req, new JsonResponse(['x' => 1]));
    }

    public function testArmOperationCooldownStoresSentinelTaggedWatermark(): void
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer([
                'persistent_output_cache_enabled' => true,
            ])])
            ->onlyMethods(['cacheSave'])
            ->getMock();

        $observed = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$observed) {
            $observed = compact('key', 'value', 'tags', 'ttl');
        });

        $service->armOperationCooldown('abc123', 21600);

        $this->assertSame(PersistentOutputCacheService::KEY_OP_COOLDOWN_PREFIX . 'abc123', $observed['key']);
        $this->assertSame(21600, $observed['ttl']);
        // TAG_WATERMARK (not TAG_COMMON) so clearAll() preserves the sentinel.
        $this->assertContains(PersistentOutputCacheService::TAG_WATERMARK, $observed['tags']);
        $this->assertNotContains(PersistentOutputCacheService::TAG_COMMON, $observed['tags']);
    }

    public function testOperationCooldownArmHasClearRoundTrip(): void
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer([
                'persistent_output_cache_enabled' => true,
            ])])
            ->onlyMethods(['cacheLoad', 'cacheSave', 'cacheRemove'])
            ->getMock();

        $store = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$store) {
            $store[$key] = $value;
        });
        $service->method('cacheLoad')->willReturnCallback(function (string $key) use (&$store) {
            return $store[$key] ?? null;
        });
        $service->method('cacheRemove')->willReturnCallback(function (string $key) use (&$store): bool {
            unset($store[$key]);

            return true;
        });

        $this->assertFalse($service->hasOperationCooldown('hash9'));
        $service->armOperationCooldown('hash9', 600);
        $this->assertTrue($service->hasOperationCooldown('hash9'));
        $service->clearOperationCooldown('hash9');
        $this->assertFalse($service->hasOperationCooldown('hash9'));
    }

    public function testHasOperationCooldownReturnsFalseWhenCacheReturnsFalse(): void
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer([
                'persistent_output_cache_enabled' => true,
            ])])
            ->onlyMethods(['cacheLoad'])
            ->getMock();

        $service->method('cacheLoad')->willReturn(false);

        $this->assertFalse($service->hasOperationCooldown('missinghash'));
    }

    public function testIsEntryStaleEntryPathAloneFiresWithoutWatermark(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
        ]);

        $now = time();
        $meta = [
            'refreshedAt'   => $now - 100,
            'invalidatedAt' => $now - 50,
            'client' => 'c1',
            'operation' => 'TestOp',
        ];
        $payload = ['data' => ['x' => 1]];

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if ($key === 'datahub_graphql_fallback_watermark_ts') {
                return 0;
            }
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return $payload;
            }

            return null;
        });
        $service->method('cacheSave')->willReturnCallback(function () {
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $response = $service->preHandle($request, $this->makeResponseService());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('STALE', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $this->assertTrue((bool)$request->attributes->get('_datahub_persistent_refresh'));
    }

    public function testIsEntryStaleEqualTimestampReadAsFresh(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
        ]);

        $now = time();
        $meta = [
            'refreshedAt'   => $now,
            'invalidatedAt' => $now,
            'client' => 'c1',
            'operation' => 'TestOp',
        ];
        $payload = ['data' => ['x' => 1]];

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if ($key === 'datahub_graphql_fallback_watermark_ts') {
                return 0;
            }
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return $payload;
            }

            return null;
        });
        $service->method('cacheSave')->willReturnCallback(function () {
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $response = $service->preHandle($request, $this->makeResponseService());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('HIT', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
    }

    public function testIsEntryStaleWatermarkPathPreservesBackCompat(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
        ]);

        $now = time();
        $meta = [
            'refreshedAt' => $now - 100,
            'client' => 'c1',
            'operation' => 'TestOp',
        ];
        $payload = ['data' => ['x' => 1]];

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload, $now) {
            if ($key === 'datahub_graphql_fallback_watermark_ts') {
                return $now;
            }
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return $payload;
            }

            return null;
        });
        $service->method('cacheSave')->willReturnCallback(function () {
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $response = $service->preHandle($request, $this->makeResponseService());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('STALE', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
    }

    public function testIsEntryStaleReturnsFreshWhenInvalidatedAtNotExceedsRefreshedAt(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
        ]);

        $now = time();
        $meta = [
            'refreshedAt'   => $now,
            'invalidatedAt' => $now - 50,
            'client' => 'c1',
            'operation' => 'TestOp',
        ];
        $payload = ['data' => ['x' => 1]];

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if ($key === 'datahub_graphql_fallback_watermark_ts') {
                return 0;
            }
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return $payload;
            }

            return null;
        });

        $saves = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saves) {
            $saves[] = $key;
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $response = $service->preHandle($request, $this->makeResponseService());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('HIT', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
    }

    public function testPreHandleReturnsStaleForPerEntryInvalidatedAtWithoutWatermark(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
        ]);

        $now = time();
        $meta = [
            'refreshedAt'   => $now - 200,
            'invalidatedAt' => $now - 100,
            'client' => 'c1',
            'operation' => 'TestOp',
        ];
        $payload = ['data' => ['x' => 1]];

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if ($key === 'datahub_graphql_fallback_watermark_ts') {
                return 0;
            }
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return $payload;
            }

            return null;
        });
        $service->method('cacheSave')->willReturnCallback(function () {
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $response = $service->preHandle($request, $this->makeResponseService());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame('STALE', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $this->assertTrue((bool)$request->attributes->get('_datahub_persistent_refresh'));
    }

    public function testProbeStatusReportsStaleForPerEntryInvalidatedAt(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
        ]);

        $now = time();
        $meta = [
            'refreshedAt'   => $now - 200,
            'invalidatedAt' => $now - 100,
            'client' => 'c1',
        ];
        $payload = ['data' => ['x' => 1]];

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad'])
            ->getMock();

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if ($key === 'datahub_graphql_fallback_watermark_ts') {
                return 0;
            }
            if (str_contains($key, 'meta_')) {
                return $meta;
            }
            if (str_contains($key, 'payload_')) {
                return $payload;
            }

            return null;
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $probe = $service->probeStatus($request);

        $this->assertTrue($probe['applies']);
        $this->assertSame('STALE', $probe['status']);
    }

    public function testStampInvalidatedAtWritesIntoMetaBlobPreservingExistingKeys(): void
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer([
                'persistent_output_cache_enabled' => true,
                'persistent_output_cache_lifetime' => 60,
            ])])
            ->onlyMethods(['cacheSave'])
            ->getMock();

        $now = time();
        $meta = [
            'refreshedAt' => $now - 100,
            'client'      => 'c1',
            'operation'   => 'SomeOp',
            'tags'        => ['datahub_graphql_persistent', 'datahub_graphql_op_SomeOp'],
        ];

        $saved = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value', 'tags', 'ttl');
        });

        $service->stampInvalidatedAt('persistent_output_meta_abc', $meta, $now);

        $this->assertCount(1, $saved);
        $this->assertSame('persistent_output_meta_abc', $saved[0]['key']);
        $this->assertArrayHasKey('invalidatedAt', $saved[0]['value']);
        $this->assertSame($now, $saved[0]['value']['invalidatedAt']);
        $this->assertArrayHasKey('refreshedAt', $saved[0]['value'], 'existing meta keys must be preserved');
        $this->assertSame($now - 100, $saved[0]['value']['refreshedAt']);
        $this->assertArrayHasKey('client', $saved[0]['value']);
        $this->assertSame(['datahub_graphql_persistent', 'datahub_graphql_op_SomeOp'], $saved[0]['tags']);
    }

    public function testStampInvalidatedAtFallsBackToTagCommonWhenMetaHasNoTags(): void
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer([
                'persistent_output_cache_enabled' => true,
                'persistent_output_cache_lifetime' => 60,
            ])])
            ->onlyMethods(['cacheSave'])
            ->getMock();

        $now = time();
        $meta = [
            'refreshedAt' => $now - 100,
            'client'      => 'c1',
            'operation'   => 'SomeOp',
        ];

        $saved = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value', 'tags', 'ttl');
        });

        $service->stampInvalidatedAt('persistent_output_meta_abc', $meta, $now);

        $this->assertCount(1, $saved);
        $this->assertSame([PersistentOutputCacheService::TAG_COMMON], $saved[0]['tags']);
    }

    public function testStampInvalidatedAtSwallowsCacheSaveFailure(): void
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer([
                'persistent_output_cache_enabled' => true,
                'persistent_output_cache_lifetime' => 60,
            ])])
            ->onlyMethods(['cacheSave'])
            ->getMock();

        $service->method('cacheSave')->willThrowException(new \RuntimeException('cache down'));

        $meta = ['refreshedAt' => time() - 100, 'operation' => 'Op'];

        $this->expectNotToPerformAssertions();
        $service->stampInvalidatedAt('some_meta_key', $meta, time());
    }

    public function testWindowEndsAtReturnsLastRefreshAtPlusCooldown(): void
    {
        $service = new PersistentOutputCacheService($this->makeContainer([
            'persistent_output_cache_enabled' => true,
        ]));

        $lastRefreshAt = 1_000_000;
        $cooldown = 3600;
        $meta = ['lastRefreshAt' => $lastRefreshAt];

        $this->assertSame($lastRefreshAt + $cooldown, $service->windowEndsAt($meta, $cooldown));
        $this->assertSame($cooldown, $service->windowEndsAt([], $cooldown), 'absent lastRefreshAt treated as 0');
    }

    public function testClearPendingFlagRaisesOnCacheFault(): void
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer([
                'persistent_output_cache_enabled' => true,
            ])])
            ->onlyMethods(['cacheRemove'])
            ->getMock();

        $service->method('cacheRemove')->willThrowException(new \RuntimeException('cache down'));

        $this->expectException(\RuntimeException::class);
        $service->clearPendingFlag('somehash');
    }

    public function testLoadPendingFlagReturnsTrueWhenCacheHit(): void
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer([
                'persistent_output_cache_enabled' => true,
            ])])
            ->onlyMethods(['cacheLoad'])
            ->getMock();

        $service->method('cacheLoad')->willReturn('1');

        $this->assertTrue($service->loadPendingFlag('somehash'));
    }

    public function testLoadPendingFlagReturnsFalseWhenCacheMiss(): void
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer([
                'persistent_output_cache_enabled' => true,
            ])])
            ->onlyMethods(['cacheLoad'])
            ->getMock();

        $service->method('cacheLoad')->willReturn(false);

        $this->assertFalse($service->loadPendingFlag('somehash'));
    }

    public function testClearEnqueueDedupeRaisesOnCacheFault(): void
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer([
                'persistent_output_cache_enabled' => true,
            ])])
            ->onlyMethods(['cacheRemove'])
            ->getMock();

        $service->method('cacheRemove')->willThrowException(new \RuntimeException('cache down'));

        $this->expectException(\RuntimeException::class);
        $service->clearEnqueueDedupe('somehash');
    }

    /**
     * @dataProvider providePastCooldownCases
     */
    public function testIsPastCooldown(?int $lastRefreshAt, int $cooldown, int $now, bool $expected): void
    {
        $service = new PersistentOutputCacheService($this->makeContainer([
            'persistent_output_cache_enabled' => true,
        ]));

        $meta = $lastRefreshAt === null ? ['operation' => 'Op'] : ['lastRefreshAt' => $lastRefreshAt];

        $this->assertSame($expected, $service->isPastCooldown($meta, $cooldown, $now));
    }

    public static function providePastCooldownCases(): array
    {
        return [
            'absent lastRefreshAt is past any cooldown' => [null, 600, 1_000_000, true],
            'inside window is not past' => [1_000_000, 600, 1_000_300, false],
            'exactly at boundary is past (>=)' => [1_000_000, 600, 1_000_600, true],
            'beyond window is past' => [1_000_000, 600, 1_000_900, true],
        ];
    }

    public function testSavePersistentRefreshedAtReadsStartAttributeWhenPresent(): void
    {
        $service = $this->makeSaveCapturingService();
        $saved = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value');
        });

        $start = time() - 5;
        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);
        $request->attributes->set('_datahub_persistent_refresh_started_at', $start);

        $before = time();
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));
        $after = time();

        $meta = $this->onlyMeta($saved);
        $this->assertArrayHasKey('lastRefreshAt', $meta, 'savePersistent must write lastRefreshAt into meta');
        $this->assertSame($start, $meta['refreshedAt'], 'refreshedAt must anchor to the refresh-start attribute');
        $this->assertGreaterThanOrEqual($before, $meta['lastRefreshAt']);
        $this->assertLessThanOrEqual($after, $meta['lastRefreshAt']);
        $this->assertNotSame($meta['refreshedAt'], $meta['lastRefreshAt'], 'start and completion clocks must be distinct here');
    }

    public function testSavePersistentRefreshedAtFallsBackToNowWhenStartAttributeAbsent(): void
    {
        $service = $this->makeSaveCapturingService();
        $saved = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);

        $before = time();
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));
        $after = time();

        $meta = $this->onlyMeta($saved);
        $this->assertGreaterThanOrEqual($before, $meta['refreshedAt']);
        $this->assertLessThanOrEqual($after, $meta['refreshedAt']);
        $this->assertSame($meta['refreshedAt'], $meta['lastRefreshAt'], 'first-write start ≈ completion');
    }

    public function testFreshHitRepaintLeavesRefreshedAtUnchanged(): void
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $refreshedAt = time() - 7;
        $meta = [
            'refreshedAt' => $refreshedAt,
            'client' => 'c1',
            'operation' => 'TestOp',
            'tags' => ['datahub_graphql_persistent'],
        ];

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta) {
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return ['data' => ['x' => 1]];
            }

            return null;
        });

        $saves = [];
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saves) {
            $saves[] = compact('key', 'value');
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $response = $service->preHandle($request, $this->makeResponseService());

        $this->assertSame('HIT', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $metaSave = $this->onlyMeta($saves);
        $this->assertSame($refreshedAt, $metaSave['refreshedAt'], 'FRESH-HIT repaint must not advance refreshedAt (it is a refresh-event clock, not a TTL marker)');
    }

    public function testPreHandleReportsStaleForEditLandingMidRefresh(): void
    {
        // With refreshedAt anchored to the refresh START (S), an edit recorded
        // at t where S < t < completion must leave the entry STALE on the next read.
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ];
        $classifier = $this->makeClassifier([
            'TestOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
        ]);

        $start = 1_000_000;
        $edit = 1_000_003;   // S < t
        $meta = [
            'refreshedAt'   => $start,
            'invalidatedAt' => $edit,
            'client' => 'c1',
            'operation' => 'TestOp',
        ];

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta) {
            if ($key === 'datahub_graphql_fallback_watermark_ts') {
                return 0;
            }
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return ['data' => ['x' => 1]];
            }

            return null;
        });
        $service->method('cacheSave')->willReturnCallback(function () {
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TestOp']);
        $response = $service->preHandle($request, $this->makeResponseService());

        $this->assertSame('STALE', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $this->assertTrue((bool)$request->attributes->get('_datahub_persistent_refresh'));
    }

    private function makeSaveCapturingService(): PersistentOutputCacheService
    {
        $graphqlCfg = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,
        ];
        $classifier = $this->makeClassifier([
            'Op' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphqlCfg), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();
        $service->method('cacheLoad')->willReturn(null);

        return $service;
    }

    /**
     * @param list<array{key: string, value: mixed}> $saved
     *
     * @return array<string, mixed>
     */
    private function onlyMeta(array $saved): array
    {
        foreach ($saved as $call) {
            if (str_starts_with($call['key'], 'persistent_output_meta_')) {
                return $call['value'];
            }
        }
        $this->fail('no meta save observed');
    }
}
