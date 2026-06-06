<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Lock\LockFactoryResolver;
use Pimcore\Bundle\DataHubBundle\Lock\LockSignalRefresher;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class PersistentOutputCacheServiceColdMissLockTest extends TestCase
{
    protected function setUp(): void
    {
        LockSignalRefresher::disarm();
    }

    protected function tearDown(): void
    {
        LockSignalRefresher::disarm();
    }

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

    private function makeResolverReturning(?LockFactory $factory): LockFactoryResolver
    {
        return new class($factory) extends LockFactoryResolver {
            public function __construct(private ?LockFactory $factory)
            {
            }

            public function resolve(): ?object
            {
                return $this->factory;
            }
        };
    }

    public function testAcquireColdMissLockReturnsLockOnWin(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->method('acquire')->with(false)->willReturn(true);

        $factory = $this->createMock(LockFactory::class);
        $factory->expects(self::once())
            ->method('createLock')
            ->with(self::isType('string'), 30, true)
            ->willReturn($lock);

        $service = new PersistentOutputCacheService(
            $this->makeContainer(['persistent_output_cache_enabled' => true]),
            null,
            $this->makeResolverReturning($factory)
        );

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);
        $result = $service->acquireColdMissLock($request, 30);

        self::assertSame($lock, $result);
        if (function_exists('pcntl_alarm')) {
            self::assertTrue(LockSignalRefresher::isArmed(), 'winner acquire must arm the signal refresher');
        }
    }

    public function testAcquireColdMissLockReturnsNullOnLose(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->method('acquire')->with(false)->willReturn(false);

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')->willReturn($lock);

        $service = new PersistentOutputCacheService(
            $this->makeContainer(['persistent_output_cache_enabled' => true]),
            null,
            $this->makeResolverReturning($factory)
        );

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);
        $result = $service->acquireColdMissLock($request, 30);

        self::assertNull($result);
    }

    public function testAcquireColdMissLockReturnsNullWhenFactoryUnavailable(): void
    {
        $service = new PersistentOutputCacheService(
            $this->makeContainer(['persistent_output_cache_enabled' => true]),
            null,
            $this->makeResolverReturning(null)
        );

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);
        $result = $service->acquireColdMissLock($request, 30);

        self::assertNull($result);
    }

    public function testLockKeyFormatIsMd5OfMetaPipePayload(): void
    {
        $observedResource = null;

        $lock = $this->createMock(LockInterface::class);
        $lock->method('acquire')->with(false)->willReturn(true);

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')
            ->willReturnCallback(function ($resource, $ttl, $autoRelease) use (&$observedResource, $lock) {
                $observedResource = $resource;

                return $lock;
            });

        $service = new PersistentOutputCacheService(
            $this->makeContainer(['persistent_output_cache_enabled' => true]),
            null,
            $this->makeResolverReturning($factory)
        );

        $request = $this->makeRequest('clientA', [
            'query' => '{ __typename }',
            'operationName' => 'KeyOp',
        ]);

        $service->acquireColdMissLock($request, 30);

        self::assertIsString($observedResource);
        self::assertStringStartsWith('datahub_swr_cold_miss_', $observedResource);
        // md5 hex digest is 32 chars; full resource = prefix (22) + hash (32) = 54
        self::assertSame(54, strlen($observedResource));
        // preHandle/clientAndCanonical caches the canonical body on the request attribute, so the lock-key inputs are recoverable here.
        $canonical = $request->attributes->get('_datahub_persistent_canonical');
        self::assertIsString($canonical, 'preHandle/clientAndCanonical must populate canonical');
        $sha = hash('sha256', 'client:clientA' . "\n" . $canonical);
        $expectedMetaKey = 'persistent_output_meta_' . $sha;
        $expectedPayloadKey = 'persistent_output_payload_' . $sha;
        $expectedResource = 'datahub_swr_cold_miss_' . md5($expectedMetaKey . '|' . $expectedPayloadKey);
        self::assertSame($expectedResource, $observedResource);
    }

    public function testLockKeyDifferentForDifferentClients(): void
    {
        $observed = [];

        $lock = $this->createMock(LockInterface::class);
        $lock->method('acquire')->with(false)->willReturn(true);

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')
            ->willReturnCallback(function ($resource, $ttl, $autoRelease) use (&$observed, $lock) {
                $observed[] = $resource;

                return $lock;
            });

        $service = new PersistentOutputCacheService(
            $this->makeContainer(['persistent_output_cache_enabled' => true]),
            null,
            $this->makeResolverReturning($factory)
        );

        $reqA = $this->makeRequest('clientA', ['query' => '{ __typename }', 'operationName' => 'Op']);
        $reqB = $this->makeRequest('clientB', ['query' => '{ __typename }', 'operationName' => 'Op']);

        $service->acquireColdMissLock($reqA, 30);
        $service->acquireColdMissLock($reqB, 30);

        self::assertCount(2, $observed);
        self::assertNotSame($observed[0], $observed[1], 'different clients must yield distinct lock keys');
    }

    public function testAcquireColdMissLockSwallowsAndLogsAcquireException(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->method('acquire')->willThrowException(new \RuntimeException('Redis unavailable'));

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')->willReturn($lock);

        $service = new PersistentOutputCacheService(
            $this->makeContainer(['persistent_output_cache_enabled' => true]),
            null,
            $this->makeResolverReturning($factory)
        );

        $request = $this->makeRequest('c1', ['query' => '{ __typename }', 'operationName' => 'Op']);
        $result = $service->acquireColdMissLock($request, 30);

        self::assertNull($result, 'acquire exception must be swallowed and null returned');
    }

    public function testReleaseColdMissLockIsNoopOnNull(): void
    {
        $service = new PersistentOutputCacheService(
            $this->makeContainer(['persistent_output_cache_enabled' => true]),
            null,
            $this->makeResolverReturning(null)
        );

        $service->releaseColdMissLock(null);

        self::assertFalse(LockSignalRefresher::isArmed());
    }

    public function testReleaseColdMissLockSwallowsAndLogsReleaseException(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())
            ->method('release')
            ->willThrowException(new \RuntimeException('Redis gone'));

        $service = new PersistentOutputCacheService(
            $this->makeContainer(['persistent_output_cache_enabled' => true]),
            null,
            $this->makeResolverReturning(null)
        );

        // Must not throw; the silent-renewal-loop discipline requires the
        // release exception to be swallowed + logged, never to escape.
        $service->releaseColdMissLock($lock);

        self::assertFalse(LockSignalRefresher::isArmed());
    }
}
