<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\DependencyCollector;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\ElementInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PersistentOutputCacheServiceTagCollectionTest extends TestCase
{
    /**
     * @param array<string, mixed> $graphql
     */
    private function makeContainer(array $graphql): ContainerBagInterface
    {
        $c = $this->createMock(ContainerBagInterface::class);
        $c->method('get')->willReturn(['graphql' => $graphql]);

        return $c;
    }

    private function makeClassifier(array $graphql): OperationClassifier
    {
        return new OperationClassifier($this->makeContainer($graphql));
    }

    private function makeRequest(string $client, array $body): Request
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], $payload);
        $req->attributes->set('clientname', $client);
        $req->headers->set('Content-Type', 'application/json');

        return $req;
    }

    private function fakeElement(int $id): ElementInterface
    {
        $mock = $this->createMock(AbstractObject::class);
        $mock->method('getId')->willReturn($id);

        return $mock;
    }

    private static function sanitize(string $fqcn): string
    {
        return str_replace('\\', '_', ltrim($fqcn, '\\'));
    }

    /**
     * @param array<int, array{key: string, value: mixed, tags: list<string>, ttl: ?int}> $saved by-ref capture
     */
    private function makeService(array $graphql, ?DependencyCollector $collector, ?OperationClassifier $classifier, array &$saved): PersistentOutputCacheService
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphql), $classifier, null, $collector])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();
        $service->method('cacheLoad')->willReturn(null);
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$saved) {
            $saved[] = compact('key', 'value', 'tags', 'ttl');
        });

        return $service;
    }

    public function testSavePersistentMergesCollectorTags(): void
    {
        $graphql = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,

            'operations' => [
                'TagOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ],
        ];

        $collector = new DependencyCollector();
        $e1 = $this->fakeElement(11);
        $e2 = $this->fakeElement(22);
        $collector->recordObject($e1);
        $collector->recordObject($e2);

        $saved = [];
        $service = $this->makeService($graphql, $collector, $this->makeClassifier($graphql), $saved);

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TagOp']);
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));

        $payloadCalls = array_values(array_filter($saved, fn ($c) => str_starts_with($c['key'], 'persistent_output_payload_')));
        self::assertNotEmpty($payloadCalls);
        $tags = $payloadCalls[0]['tags'];

        // Base tags preserved.
        self::assertContains(PersistentOutputCacheService::TAG_COMMON, $tags);
        self::assertContains(PersistentOutputCacheService::TAG_CLIENT_PREFIX . 'c1', $tags);
        self::assertContains(PersistentOutputCacheService::TAG_OP_PREFIX . 'TagOp', $tags);

        // Collector tags merged in (SINGLE → per-object).
        self::assertContains(PersistentOutputCacheService::TAG_OBJECT_PREFIX . self::sanitize(get_class($e1)) . '_11', $tags);
        self::assertContains(PersistentOutputCacheService::TAG_OBJECT_PREFIX . self::sanitize(get_class($e2)) . '_22', $tags);
    }

    public function testSavePersistentEmitsClassTagsForListGranularity(): void
    {
        $graphql = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,

            'operations' => [
                'ListOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ],
        ];

        $collector = new DependencyCollector();
        $e1 = $this->fakeElement(11);
        $collector->recordObject($e1);
        // record the same mock again so per-class set stays bounded to one entry
        $collector->recordObject($e1);

        $saved = [];
        $service = $this->makeService($graphql, $collector, $this->makeClassifier($graphql), $saved);

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'ListOp']);
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));

        $payloadCalls = array_values(array_filter($saved, fn ($c) => str_starts_with($c['key'], 'persistent_output_payload_')));
        self::assertNotEmpty($payloadCalls);
        $tags = $payloadCalls[0]['tags'];
        self::assertContains(PersistentOutputCacheService::TAG_CLASS_PREFIX . self::sanitize(get_class($e1)), $tags);

        // SINGLE-shape per-object tags must NOT appear for LIST granularity.
        foreach ($tags as $tag) {
            self::assertStringStartsNotWith(PersistentOutputCacheService::TAG_OBJECT_PREFIX, $tag);
        }
    }

    public function testSavePersistentMaintainsReverseIndex(): void
    {
        $graphql = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,

            'operations' => [
                'TagOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ],
        ];

        $collector = new DependencyCollector();
        $e1 = $this->fakeElement(11);
        $collector->recordObject($e1);

        $saved = [];
        $service = $this->makeService($graphql, $collector, $this->makeClassifier($graphql), $saved);

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TagOp']);
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));

        $expectedTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . self::sanitize(get_class($e1)) . '_11';
        $expectedIndexKey = PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $expectedTag;

        $reverseIndexCalls = array_values(array_filter(
            $saved,
            fn ($c) => $c['key'] === $expectedIndexKey
        ));
        self::assertNotEmpty($reverseIndexCalls, 'reverse index entry was not written for the per-object tag');

        $entry = $reverseIndexCalls[0];
        self::assertContains(PersistentOutputCacheService::TAG_COMMON, $entry['tags']);
        self::assertNull($entry['ttl']);
        self::assertIsArray($entry['value']);
        self::assertCount(1, $entry['value']);
        $pair = $entry['value'][0];
        self::assertIsArray($pair);
        self::assertStringStartsWith('persistent_output_payload_', $pair[0]);
        self::assertStringStartsWith('persistent_output_meta_', $pair[1]);
    }

    public function testSavePersistentUsesClassifierTtl(): void
    {
        $graphql = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 60,

            'operations' => [
                'TtlOp' => [
                    'tier' => 'swr_only',
                    'granularity' => 'single',
                    'ttl_override' => 999,
                ],
            ],
        ];

        $collector = new DependencyCollector();
        $collector->recordObject($this->fakeElement(11));

        $saved = [];
        $service = $this->makeService($graphql, $collector, $this->makeClassifier($graphql), $saved);

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TtlOp']);
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));

        $payloadCalls = array_values(array_filter($saved, fn ($c) => str_starts_with($c['key'], 'persistent_output_payload_')));
        self::assertNotEmpty($payloadCalls);
        self::assertSame(999, $payloadCalls[0]['ttl'], 'classifier ttl override must override $payloadTtl');
    }

    public function testSavePersistentSingleGranularityWithEmptyCollectorLogsWarning(): void
    {
        $graphql = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 60,

            'operations' => [
                'EmptyOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ],
        ];

        $collector = new DependencyCollector();
        // No recordObject() calls — collector is empty.

        $observed = [];
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphql), $this->makeClassifier($graphql), null, $collector])
            ->onlyMethods(['cacheLoad', 'cacheSave', 'logCollectorEmptyOnSave'])
            ->getMock();
        $service->method('cacheLoad')->willReturn(null);
        $service->method('cacheSave');
        $service->expects(self::once())
            ->method('logCollectorEmptyOnSave')
            ->with('EmptyOp', 'c1')
            ->willReturnCallback(function (string $op, string $client) use (&$observed) {
                $observed[] = [$op, $client];
            });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'EmptyOp']);
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));

        self::assertSame([['EmptyOp', 'c1']], $observed);
    }

    public function testSavePersistentListGranularityWithEmptyCollectorAlsoLogsWarning(): void
    {
        // A LIST op that resolves without firing POST_LOAD on any element produces
        // a cache write with no per-class tags — no reverse-index entry is added,
        // and invalidation can never find this query. Previously the warning was
        // scoped to SINGLE granularity, leaving LIST as a silent failure surface;
        // the symptom was a listing that stayed served from cache forever despite
        // editor saves. Warning must fire for any classified op with an empty
        // collector.
        $graphql = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 60,

            'operations' => [
                'EmptyListOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ],
        ];

        $collector = new DependencyCollector();

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphql), $this->makeClassifier($graphql), null, $collector])
            ->onlyMethods(['cacheLoad', 'cacheSave', 'logCollectorEmptyOnSave'])
            ->getMock();
        $service->method('cacheLoad')->willReturn(null);
        $service->method('cacheSave');
        $service->expects(self::once())
            ->method('logCollectorEmptyOnSave')
            ->with('EmptyListOp', 'c1');

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'EmptyListOp']);
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));
    }

    public function testSavePersistentFallsBackToConfiguredTtlWhenClassifierReturnsNull(): void
    {
        // When the classifier has no ttl_override and the granularity-TTL map resolves to
        // the per-granularity default, the scalar persistent_output_cache_payload_ttl acts
        // as the outer fallback (via ?? $this->payloadTtl in savePersistent). This pins
        // that the fallback is non-null so Symfony Cache never receives TTL=null.
        $graphql = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 1234,
            'persistent_output_cache_payload_ttl_by_granularity' => ['single' => 1234, 'list' => 1234],
            'operations' => [
                'ClassifiedOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ],
        ];

        $collector = new DependencyCollector();
        $saved = [];
        $service = $this->makeService($graphql, $collector, $this->makeClassifier($graphql), $saved);

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'ClassifiedOp']);
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));

        $payloadCalls = array_values(array_filter($saved, fn ($c) => str_starts_with($c['key'], 'persistent_output_payload_')));
        self::assertNotEmpty($payloadCalls);
        self::assertSame(1234, $payloadCalls[0]['ttl']);
    }

    public function testSavePersistentDedupsSamePayloadKeyInReverseIndex(): void
    {
        $graphql = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,

            'operations' => [
                'TagOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ],
        ];

        $collector = new DependencyCollector();
        $e1 = $this->fakeElement(11);
        $collector->recordObject($e1);

        $expectedTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . self::sanitize(get_class($e1)) . '_11';
        $expectedIndexKey = PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $expectedTag;

        $store = [];
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphql), $this->makeClassifier($graphql), null, $collector])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use (&$store) {
            return $store[$key] ?? null;
        });
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value) use (&$store) {
            $store[$key] = $value;
        });

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TagOp']);

        // First save writes the reverse-index entry.
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));
        // Second save with same op+client+collector state must not duplicate the pair.
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));

        $reverseIndex = $store[$expectedIndexKey] ?? null;
        self::assertIsArray($reverseIndex);
        self::assertCount(1, $reverseIndex, 'reverse-index must not accumulate duplicate pairs for the same payloadKey');
    }

    /**
     * Store-backed service double: cacheLoad reads from / cacheSave writes to an
     * in-memory `$capture['store']`, and every cacheSave is recorded in
     * `$capture['writes']` as a {key,value,tags,ttl} record so a test can assert
     * both the write count and the TTL/tags contract of each write.
     *
     * @param array<string, mixed>                                                                                                  $graphql
     * @param array{store: array<string, mixed>, writes: list<array{key: string, value: mixed, tags: list<string>, ttl: ?int}>} $capture by-ref state (reset here)
     */
    private function makeStoreBackedService(array $graphql, DependencyCollector $collector, array &$capture): PersistentOutputCacheService
    {
        $capture = ['store' => [], 'writes' => []];
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphql), $this->makeClassifier($graphql), null, $collector])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();
        // NOTE: a regular closure with use(&$capture), not an arrow fn — an arrow
        // fn captures $capture by value at definition time, so cacheLoad would
        // forever return the empty initial store and the already-present
        // detection these tests exercise would never fire.
        $service->method('cacheLoad')->willReturnCallback(function (string $key) use (&$capture) {
            return $capture['store'][$key] ?? null;
        });
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value, array $tags, ?int $ttl) use (&$capture) {
            $capture['store'][$key] = $value;
            $capture['writes'][] = compact('key', 'value', 'tags', 'ttl');
        });

        return $service;
    }

    /**
     * @param array{store: array<string, mixed>, writes: list<array{key: string, value: mixed, tags: list<string>, ttl: ?int}>} $capture
     *
     * @return list<array{key: string, value: mixed, tags: list<string>, ttl: ?int}>
     */
    private static function writesTo(array $capture, string $key): array
    {
        return array_values(array_filter($capture['writes'], static fn ($w) => $w['key'] === $key));
    }

    public function testTouchingExistingListReverseIndexRenewsItsTtl(): void
    {
        // Reproduces the fallback-watermark-storm shape: a list-granularity query
        // records a per-CLASS reverse index (taginx). A refresh of the
        // already-cached query must re-save that entry so its null TTL (the cache
        // pool's 7-day default) window is renewed. When the save was skipped for
        // an already-present pair, the taginx expired after 7 days and the next
        // save of any element of that class took the global watermark-bump path.
        $graphql = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,

            'operations' => [
                'ListOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ],
        ];

        $collector = new DependencyCollector();
        $e1 = $this->fakeElement(11);
        $collector->recordObject($e1);

        $expectedIndexKey = PersistentOutputCacheService::REVERSE_INDEX_PREFIX
            . PersistentOutputCacheService::TAG_CLASS_PREFIX . self::sanitize(get_class($e1));

        $capture = [];
        $service = $this->makeStoreBackedService($graphql, $collector, $capture);

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'ListOp']);
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));

        $indexWrites = self::writesTo($capture, $expectedIndexKey);
        self::assertCount(2, $indexWrites, 'reverse index must be re-saved on touch to renew its TTL');
        // The renewal mechanism itself: each re-save passes a null TTL (pool
        // default) and carries TAG_COMMON so clearAll() still drops the entry.
        self::assertNull($indexWrites[1]['ttl'], 're-save must pass null TTL so the pool-default window is renewed');
        self::assertContains(PersistentOutputCacheService::TAG_COMMON, $indexWrites[1]['tags']);
        // Dedup still holds: the re-save must not accumulate duplicate pairs.
        self::assertCount(1, $capture['store'][$expectedIndexKey]);
    }

    public function testTouchingExistingForwardIndexMemberRenewsItsTtl(): void
    {
        // Same TTL-renewal contract for the forward index INDEX_ALL, which the
        // sweep's listAllEntries enumerates (the per-op / per-client indices are
        // maintained on the same path but consumed only by targeted eviction):
        // a repeat save of an already-indexed payload key must re-save the index
        // so it does not silently expire and drop entries from the sweep.
        $graphql = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,

            'operations' => [
                'TagOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ],
        ];

        $collector = new DependencyCollector();
        $collector->recordObject($this->fakeElement(11));

        $capture = [];
        $service = $this->makeStoreBackedService($graphql, $collector, $capture);

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'TagOp']);
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));

        $allIndexWrites = self::writesTo($capture, PersistentOutputCacheService::INDEX_ALL);
        self::assertCount(2, $allIndexWrites, 'forward index must be re-saved on touch to renew its TTL');
        self::assertNull($allIndexWrites[1]['ttl'], 're-save must pass null TTL so the pool-default window is renewed');
        self::assertContains(PersistentOutputCacheService::TAG_COMMON, $allIndexWrites[1]['tags']);
        self::assertCount(1, $capture['store'][PersistentOutputCacheService::INDEX_ALL]);
    }

    public function testCollectorObjectTagsDoNotAppearInPayloadForListGranularity(): void
    {
        // For list-granularity operations, collectorTagsForOperation() emits class-level
        // tags but NOT per-object tags (to avoid unbounded tag fan-out on listing results).
        $graphql = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 12,
            'persistent_output_cache_payload_ttl' => 3456,

            'operations' => [
                'ListGranOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ],
        ];

        $collector = new DependencyCollector();
        $e1 = $this->fakeElement(42);
        $collector->recordObject($e1);

        $saved = [];
        $service = $this->makeService($graphql, $collector, $this->makeClassifier($graphql), $saved);

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'ListGranOp']);
        $service->savePersistent($request, new JsonResponse(['data' => ['x' => 1]]));

        $payloadCalls = array_values(array_filter($saved, fn ($c) => str_starts_with($c['key'], 'persistent_output_payload_')));
        self::assertNotEmpty($payloadCalls);
        foreach ($payloadCalls[0]['tags'] as $tag) {
            self::assertStringStartsNotWith(
                PersistentOutputCacheService::TAG_OBJECT_PREFIX,
                $tag,
                'list-granularity operation must not receive collector per-object tags'
            );
        }
    }
}
