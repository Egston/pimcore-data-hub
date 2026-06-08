<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

final class PersistentOutputCacheServiceListAllEntriesTest extends TestCase
{
    private function makeContainer(): ContainerBagInterface
    {
        $c = $this->createMock(ContainerBagInterface::class);
        $c->method('get')->willReturn(['graphql' => ['persistent_output_cache_enabled' => true]]);

        return $c;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function makeService(array &$store): PersistentOutputCacheService
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer()])
            ->onlyMethods(['cacheLoad'])
            ->getMock();
        $service->method('cacheLoad')->willReturnCallback(fn (string $key) => $store[$key] ?? null);

        return $service;
    }

    public function testEmptyIndexReturnsEmpty(): void
    {
        $store = [];
        $result = $this->makeService($store)->listAllEntries();

        self::assertSame(['entries' => [], 'skipped' => 0], $result);
    }

    public function testNonArrayIndexReturnsEmpty(): void
    {
        $store = [PersistentOutputCacheService::INDEX_ALL => 'not-an-array'];
        $result = $this->makeService($store)->listAllEntries();

        self::assertSame(['entries' => [], 'skipped' => 0], $result);
    }

    public function testDerivesMetaKeyFromPayloadKeyHash(): void
    {
        $client = 'c1';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString('{"operationName":"Op","query":"{ a }"}');
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $canonical);
        $metaKey = PersistentOutputCacheService::keyMetaFor($client, $canonical);

        $store = [
            PersistentOutputCacheService::INDEX_ALL => [$payloadKey],
            $metaKey => [
                'client' => $client,
                'operation' => 'Op',
                'canonical' => $canonical,
            ],
        ];

        $result = $this->makeService($store)->listAllEntries();

        self::assertCount(1, $result['entries']);
        self::assertSame(0, $result['skipped']);
        self::assertSame($client, $result['entries'][0]['client']);
        self::assertSame('Op', $result['entries'][0]['operation']);
        self::assertSame($canonical, $result['entries'][0]['canonical']);
    }

    public function testMissingMetaIsSkipped(): void
    {
        $client = 'c1';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString('{"operationName":"Op","query":"{ a }"}');
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $canonical);

        $store = [
            PersistentOutputCacheService::INDEX_ALL => [$payloadKey],
        ];

        $result = $this->makeService($store)->listAllEntries();

        self::assertCount(0, $result['entries']);
        self::assertSame(1, $result['skipped']);
    }

    public function testMalformedMetaMissingClientIsSkipped(): void
    {
        $client = 'c1';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString('{"operationName":"Op","query":"{ a }"}');
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $canonical);
        $metaKey = PersistentOutputCacheService::keyMetaFor($client, $canonical);

        $store = [
            PersistentOutputCacheService::INDEX_ALL => [$payloadKey],
            $metaKey => ['operation' => 'Op', 'canonical' => $canonical],
        ];

        $result = $this->makeService($store)->listAllEntries();

        self::assertCount(0, $result['entries']);
        self::assertSame(1, $result['skipped']);
    }

    public function testNonPayloadKeyPrefixEntryIsSkipped(): void
    {
        $store = [
            PersistentOutputCacheService::INDEX_ALL => ['not_a_payload_key_abc123'],
        ];

        $result = $this->makeService($store)->listAllEntries();

        self::assertCount(0, $result['entries']);
        self::assertSame(1, $result['skipped']);
    }

    public function testMultipleEntriesWithSomeSkipped(): void
    {
        $client = 'c1';
        $bodyA = '{"operationName":"OpA","query":"{ a }"}';
        $bodyB = '{"operationName":"OpB","query":"{ b }"}';
        $canonicalA = PersistentOutputCacheService::canonicalizePayloadString($bodyA);
        $canonicalB = PersistentOutputCacheService::canonicalizePayloadString($bodyB);
        $payloadKeyA = PersistentOutputCacheService::keyPayloadFor($client, $canonicalA);
        $payloadKeyB = PersistentOutputCacheService::keyPayloadFor($client, $canonicalB);
        $metaKeyA = PersistentOutputCacheService::keyMetaFor($client, $canonicalA);

        $store = [
            PersistentOutputCacheService::INDEX_ALL => [$payloadKeyA, $payloadKeyB],
            $metaKeyA => ['client' => $client, 'operation' => 'OpA', 'canonical' => $canonicalA],
        ];

        $result = $this->makeService($store)->listAllEntries();

        self::assertCount(1, $result['entries']);
        self::assertSame(1, $result['skipped']);
        self::assertSame('OpA', $result['entries'][0]['operation']);
    }

    public function testNullOperationInMetaIsPreserved(): void
    {
        $client = 'c1';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString('{"query":"{ a }"}');
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $canonical);
        $metaKey = PersistentOutputCacheService::keyMetaFor($client, $canonical);

        $store = [
            PersistentOutputCacheService::INDEX_ALL => [$payloadKey],
            $metaKey => ['client' => $client, 'canonical' => $canonical],
        ];

        $result = $this->makeService($store)->listAllEntries();

        self::assertCount(1, $result['entries']);
        self::assertNull($result['entries'][0]['operation']);
    }
}
