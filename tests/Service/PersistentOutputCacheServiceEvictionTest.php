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
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

final class PersistentOutputCacheServiceEvictionTest extends TestCase
{
    private function makeContainer(): ContainerBagInterface
    {
        $c = $this->createMock(ContainerBagInterface::class);
        $c->method('get')->willReturn(['graphql' => ['persistent_output_cache_enabled' => true]]);

        return $c;
    }

    /**
     * @param array<string, mixed> $store by-ref backing store
     */
    private function makeService(array &$store): PersistentOutputCacheService
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer()])
            ->onlyMethods(['cacheLoad', 'cacheSave', 'cacheRemove'])
            ->getMock();
        $service->method('cacheLoad')->willReturnCallback(fn (string $key) => $store[$key] ?? null);
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value) use (&$store): void {
            $store[$key] = $value;
        });
        $service->method('cacheRemove')->willReturnCallback(function (string $key) use (&$store): void {
            unset($store[$key]);
        });

        return $service;
    }

    public function testEvictRemovesReverseIndexPairForwardIndexMembershipAndBothKeys(): void
    {
        $client = 'c1';
        $body = '{"operationName":"TagOp","query":"{ a }"}';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString($body);
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $canonical);
        $metaKey = PersistentOutputCacheService::keyMetaFor($client, $canonical);

        $objTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . 'SomeClass_11';
        $classTag = PersistentOutputCacheService::TAG_CLASS_PREFIX . 'SomeClass';
        $reverseObjKey = PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objTag;
        $reverseClassKey = PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $classTag;

        $otherPair = ['persistent_output_payload_other', 'persistent_output_meta_other'];

        $store = [
            $payloadKey => ['data' => ['x' => 1]],
            $metaKey => [
                'operation' => 'TagOp',
                'client' => $client,
                'tags' => [PersistentOutputCacheService::TAG_COMMON, $objTag, $classTag],
            ],
            PersistentOutputCacheService::INDEX_ALL => [$payloadKey, 'persistent_output_payload_other'],
            PersistentOutputCacheService::INDEX_OP_PREFIX . 'TagOp' => [$payloadKey],
            PersistentOutputCacheService::INDEX_CLIENT_PREFIX . $client => [$payloadKey, 'persistent_output_payload_other'],
            $reverseObjKey => [[$payloadKey, $metaKey], $otherPair],
            $reverseClassKey => [[$payloadKey, $metaKey]],
        ];

        $service = $this->makeService($store);
        $service->evictEntry($client, $body, 'TagOp');

        self::assertArrayNotHasKey($payloadKey, $store, 'payload key removed');
        self::assertArrayNotHasKey($metaKey, $store, 'meta key removed');

        self::assertSame(['persistent_output_payload_other'], $store[PersistentOutputCacheService::INDEX_ALL]);
        self::assertSame([], $store[PersistentOutputCacheService::INDEX_OP_PREFIX . 'TagOp']);
        self::assertSame(['persistent_output_payload_other'], $store[PersistentOutputCacheService::INDEX_CLIENT_PREFIX . $client]);

        // Reverse index: our pair gone, the other pair intact.
        self::assertSame([$otherPair], $store[$reverseObjKey]);
        self::assertSame([], $store[$reverseClassKey]);
    }

    public function testEvictWithMetaAbsentStillRemovesForwardIndexAndKeysWithoutFatal(): void
    {
        $client = 'c1';
        $body = '{"operationName":"TagOp","query":"{ a }"}';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString($body);
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $canonical);

        $store = [
            $payloadKey => ['data' => ['x' => 1]],
            PersistentOutputCacheService::INDEX_ALL => [$payloadKey],
            PersistentOutputCacheService::INDEX_OP_PREFIX . 'TagOp' => [$payloadKey],
            PersistentOutputCacheService::INDEX_CLIENT_PREFIX . $client => [$payloadKey],
        ];

        $service = $this->makeService($store);
        $service->evictEntry($client, $body, 'TagOp');

        self::assertArrayNotHasKey($payloadKey, $store);
        self::assertSame([], $store[PersistentOutputCacheService::INDEX_ALL]);
        self::assertSame([], $store[PersistentOutputCacheService::INDEX_OP_PREFIX . 'TagOp']);
        self::assertSame([], $store[PersistentOutputCacheService::INDEX_CLIENT_PREFIX . $client]);
    }

    public function testEvictWithMalformedTagsSkipsReverseIndexButCleansForwardIndex(): void
    {
        $client = 'c1';
        $body = '{"operationName":"TagOp","query":"{ a }"}';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString($body);
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $canonical);
        $metaKey = PersistentOutputCacheService::keyMetaFor($client, $canonical);

        foreach ([['tags' => 'not-an-array'], ['tags' => []], []] as $metaShape) {
            $store = [
                $payloadKey => ['data' => ['x' => 1]],
                $metaKey => ['operation' => 'TagOp', 'client' => $client] + $metaShape,
                PersistentOutputCacheService::INDEX_ALL => [$payloadKey],
                PersistentOutputCacheService::INDEX_CLIENT_PREFIX . $client => [$payloadKey],
            ];

            $service = $this->makeService($store);
            $service->evictEntry($client, $body, 'TagOp');

            self::assertArrayNotHasKey($payloadKey, $store);
            self::assertArrayNotHasKey($metaKey, $store);
            self::assertSame([], $store[PersistentOutputCacheService::INDEX_ALL]);
            self::assertSame([], $store[PersistentOutputCacheService::INDEX_CLIENT_PREFIX . $client]);
        }
    }

    public function testEvictIsIdempotentOnAlreadyAbsentEntry(): void
    {
        $client = 'c1';
        $body = '{"operationName":"TagOp","query":"{ a }"}';

        $store = [];
        $service = $this->makeService($store);

        $service->evictEntry($client, $body, 'TagOp');
        $service->evictEntry($client, $body, 'TagOp');

        self::assertSame([], $store, 'eviction on an empty store leaves no residue');
    }

    public function testEvictWithNullOperationNameSkipsOpIndexButCleansOtherIndicesAndKeys(): void
    {
        $client = 'c1';
        $body = '{"query":"{ a }"}';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString($body);
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $canonical);
        $metaKey = PersistentOutputCacheService::keyMetaFor($client, $canonical);

        $opIndexKey = PersistentOutputCacheService::INDEX_OP_PREFIX . 'someOp';

        $store = [
            $payloadKey => ['data' => ['x' => 1]],
            $metaKey => ['client' => $client, 'tags' => []],
            PersistentOutputCacheService::INDEX_ALL => [$payloadKey],
            PersistentOutputCacheService::INDEX_CLIENT_PREFIX . $client => [$payloadKey],
            $opIndexKey => ['unrelated-payload'],
        ];

        $service = $this->makeService($store);
        $service->evictEntry($client, $body, null);

        self::assertArrayNotHasKey($payloadKey, $store);
        self::assertArrayNotHasKey($metaKey, $store);
        self::assertSame([], $store[PersistentOutputCacheService::INDEX_ALL]);
        self::assertSame([], $store[PersistentOutputCacheService::INDEX_CLIENT_PREFIX . $client]);
        self::assertSame(['unrelated-payload'], $store[$opIndexKey], 'null operationName must not touch any op-index');
    }

    public function testEvictDoesNotFatalOnMalformedReverseIndexPair(): void
    {
        $client = 'c1';
        $body = '{"operationName":"TagOp","query":"{ a }"}';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString($body);
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $canonical);
        $metaKey = PersistentOutputCacheService::keyMetaFor($client, $canonical);

        $objTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . 'SomeClass_11';
        $reverseObjKey = PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objTag;

        $store = [
            $payloadKey => ['data' => ['x' => 1]],
            $metaKey => [
                'operation' => 'TagOp',
                'client' => $client,
                'tags' => [$objTag],
            ],
            $reverseObjKey => ['not-a-pair', [123], [$payloadKey, $metaKey]],
        ];

        $service = $this->makeService($store);
        $service->evictEntry($client, $body, 'TagOp');

        // Malformed entries preserved, only the matching pair dropped.
        self::assertSame(['not-a-pair', [123]], $store[$reverseObjKey]);
    }
}
