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

    public function testSavePersistentListGranularityWithEmptyCollectorDoesNotWarn(): void
    {
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
        $service->expects(self::never())->method('logCollectorEmptyOnSave');

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
