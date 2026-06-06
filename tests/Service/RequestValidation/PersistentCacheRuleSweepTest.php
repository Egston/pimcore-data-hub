<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service\RequestValidation;

use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\PersistentCacheRuleSweep;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RequestVariableValidator;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RulesLoader;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\SweepCounts;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

final class PersistentCacheRuleSweepTest extends TempfileTestCase
{
    private const CLIENT = 'public-content';

    private function makeContainer(): ContainerBagInterface
    {
        $c = $this->createMock(ContainerBagInterface::class);
        $c->method('get')->willReturn(['graphql' => ['persistent_output_cache_enabled' => true]]);

        return $c;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function makeCacheService(array &$store): PersistentOutputCacheService
    {
        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer()])
            ->onlyMethods(['cacheLoad', 'cacheSave', 'cacheRemove', 'cacheClearTag'])
            ->getMock();
        $service->method('cacheLoad')->willReturnCallback(function (string $key) use (&$store) {
            return $store[$key] ?? null;
        });
        $service->method('cacheSave')->willReturnCallback(function (string $key, $value) use (&$store): void {
            $store[$key] = $value;
        });
        $service->method('cacheRemove')->willReturnCallback(function (string $key) use (&$store): void {
            unset($store[$key]);
        });

        return $service;
    }

    private function rulesWithClient(string $client): CapturingRulesLoader
    {
        $rules = [
            'versions' => [
                '1' => [
                    'operations' => [
                        'AllowedOp' => ['variables' => ['lang' => ['kind' => 'enum', 'values' => ['en', 'de']]]],
                    ],
                ],
            ],
        ];
        $this->writeJson($rules);

        return new CapturingRulesLoader($this->file);
    }

    /**
     * @param array<string, mixed> $store
     */
    private function seedEntry(array &$store, string $client, string $opName, array $variables = []): string
    {
        $body = json_encode(['operationName' => $opName, 'query' => '{ x }', 'variables' => $variables]) ?: '';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString($body);
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $canonical);
        $metaKey = PersistentOutputCacheService::keyMetaFor($client, $canonical);

        $store[PersistentOutputCacheService::INDEX_ALL][] = $payloadKey;
        $store[$payloadKey] = ['data' => ['x' => 1]];
        $store[$metaKey] = [
            'client' => $client,
            'operation' => $opName,
            'canonical' => $canonical,
        ];

        return $canonical;
    }

    public function testRulesNullReturnsZeroCounts(): void
    {
        $store = [];
        $cacheService = $this->makeCacheService($store);
        $rulesLoader = new CapturingRulesLoader('');
        $validator = new CapturingRequestVariableValidator($rulesLoader, [self::CLIENT]);
        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader);

        $result = $sweep->sweep();

        self::assertInstanceOf(SweepCounts::class, $result);
        self::assertSame(0, $result->scanned);
        self::assertSame(0, $result->evicted);
        self::assertSame(0, $result->skippedMalformed);
        self::assertSame(0, $result->evictFailed);
        self::assertSame(0, $result->notEnforced);
        self::assertSame(0, $result->passed);
        self::assertSame(0, $result->validateFailed);
    }

    public function testConformingEntryIsNotEvicted(): void
    {
        $store = [PersistentOutputCacheService::INDEX_ALL => []];
        $rulesLoader = $this->rulesWithClient(self::CLIENT);
        $cacheService = $this->makeCacheService($store);
        $validator = new CapturingRequestVariableValidator($rulesLoader, [self::CLIENT]);
        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader);

        $this->seedEntry($store, self::CLIENT, 'AllowedOp', ['lang' => 'en']);

        $result = $sweep->sweep();

        self::assertSame(1, $result->scanned);
        self::assertSame(0, $result->evicted);
        self::assertSame(0, $result->skippedMalformed);
        self::assertSame(1, $result->passed);
        self::assertSame(0, $result->notEnforced);
    }

    public function testNonConformingEntryIsEvicted(): void
    {
        $store = [PersistentOutputCacheService::INDEX_ALL => []];
        $rulesLoader = $this->rulesWithClient(self::CLIENT);
        $cacheService = $this->makeCacheService($store);
        $validator = new CapturingRequestVariableValidator($rulesLoader, [self::CLIENT]);
        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader);

        $canonical = $this->seedEntry($store, self::CLIENT, 'AllowedOp', ['lang' => 'INVALID_VALUE']);

        $payloadKey = PersistentOutputCacheService::keyPayloadFor(self::CLIENT, $canonical);
        $metaKey = PersistentOutputCacheService::keyMetaFor(self::CLIENT, $canonical);

        $result = $sweep->sweep();

        self::assertSame(1, $result->scanned);
        self::assertSame(1, $result->evicted);
        self::assertSame(0, $result->evictFailed);

        self::assertArrayNotHasKey($payloadKey, $store, 'payload key evicted');
        self::assertArrayNotHasKey($metaKey, $store, 'meta key evicted');
    }

    public function testUnknownOperationIsEvicted(): void
    {
        $store = [PersistentOutputCacheService::INDEX_ALL => []];
        $rulesLoader = $this->rulesWithClient(self::CLIENT);
        $cacheService = $this->makeCacheService($store);
        $validator = new CapturingRequestVariableValidator($rulesLoader, [self::CLIENT]);
        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader);

        $this->seedEntry($store, self::CLIENT, 'ForbiddenOp', []);

        $result = $sweep->sweep();

        self::assertSame(1, $result->scanned);
        self::assertSame(1, $result->evicted);
    }

    public function testEvictThrowCountedAndSweepContinues(): void
    {
        $store = [PersistentOutputCacheService::INDEX_ALL => []];
        $rulesLoader = $this->rulesWithClient(self::CLIENT);

        $cacheService = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer()])
            ->onlyMethods(['cacheLoad', 'cacheSave', 'cacheRemove', 'cacheClearTag'])
            ->getMock();

        $cacheService->method('cacheLoad')->willReturnCallback(function (string $key) use (&$store) {
            return $store[$key] ?? null;
        });
        $cacheService->method('cacheSave')->willReturnCallback(function (string $key, $value) use (&$store): void {
            $store[$key] = $value;
        });
        $throwOnRemove = true;
        $cacheService->method('cacheRemove')->willReturnCallback(function (string $key) use (&$store, &$throwOnRemove): void {
            if ($throwOnRemove) {
                $throwOnRemove = false;

                throw new \RuntimeException('backend unavailable');
            }
            unset($store[$key]);
        });

        $validator = new CapturingRequestVariableValidator($rulesLoader, [self::CLIENT]);
        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader);

        $this->seedEntry($store, self::CLIENT, 'BadOp', []);
        $this->seedEntry($store, self::CLIENT, 'AlsoBadOp', []);

        $result = $sweep->sweep();

        self::assertSame(2, $result->scanned);
        self::assertSame(1, $result->evicted);
        self::assertSame(1, $result->evictFailed);
    }

    public function testMalformedMetaSkippedWithoutAbort(): void
    {
        $client = self::CLIENT;
        $canonical = PersistentOutputCacheService::canonicalizePayloadString('{"operationName":"Op","query":"{ x }"}');
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $canonical);
        $metaKey = PersistentOutputCacheService::keyMetaFor($client, $canonical);

        $store = [
            PersistentOutputCacheService::INDEX_ALL => [$payloadKey],
            $metaKey => 'not-an-array',
        ];

        $rulesLoader = $this->rulesWithClient(self::CLIENT);
        $cacheService = $this->makeCacheService($store);
        $validator = new CapturingRequestVariableValidator($rulesLoader, [self::CLIENT]);
        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader);

        $result = $sweep->sweep();

        self::assertSame(0, $result->scanned);
        self::assertSame(1, $result->skippedMalformed);
        self::assertSame(0, $result->evicted);
    }

    public function testUnenforceClientIsNotEvicted(): void
    {
        $store = [PersistentOutputCacheService::INDEX_ALL => []];
        $rulesLoader = $this->rulesWithClient(self::CLIENT);
        $cacheService = $this->makeCacheService($store);
        $validator = new CapturingRequestVariableValidator($rulesLoader, ['other-client']);
        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader);

        $this->seedEntry($store, self::CLIENT, 'ForbiddenOp', []);

        $result = $sweep->sweep();

        self::assertSame(0, $result->scanned);
        self::assertSame(0, $result->evicted);
        self::assertSame(1, $result->notEnforced);
    }

    public function testMixedEnforcedAndNotEnforcedBucketsCorrectly(): void
    {
        $store = [PersistentOutputCacheService::INDEX_ALL => []];
        $rulesLoader = $this->rulesWithClient(self::CLIENT);
        $cacheService = $this->makeCacheService($store);
        $validator = new CapturingRequestVariableValidator($rulesLoader, [self::CLIENT]);
        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader);

        $this->seedEntry($store, self::CLIENT, 'AllowedOp', ['lang' => 'en']);
        $this->seedEntry($store, 'other-client', 'AllowedOp', ['lang' => 'en']);

        $result = $sweep->sweep();

        self::assertSame(1, $result->scanned);
        self::assertSame(1, $result->passed);
        self::assertSame(0, $result->evicted);
        self::assertSame(1, $result->notEnforced);
    }

    public function testUndecodableCanonicalIsEvicted(): void
    {
        $client = self::CLIENT;
        $corruptCanonical = '"corrupted"';
        $recanonicalizedFallback = PersistentOutputCacheService::canonicalizePayloadString($corruptCanonical);
        $payloadKey = PersistentOutputCacheService::keyPayloadFor($client, $recanonicalizedFallback);
        $metaKey = PersistentOutputCacheService::keyMetaFor($client, $recanonicalizedFallback);

        $store = [
            PersistentOutputCacheService::INDEX_ALL => [$payloadKey],
            $payloadKey => ['data' => ['x' => 1]],
            $metaKey => [
                'client' => $client,
                'operation' => 'SomeOp',
                'canonical' => $corruptCanonical,
            ],
        ];

        $rulesLoader = $this->rulesWithClient(self::CLIENT);
        $cacheService = $this->makeCacheService($store);
        $validator = new CapturingRequestVariableValidator($rulesLoader, [self::CLIENT]);
        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader);

        $result = $sweep->sweep();

        self::assertSame(1, $result->evicted);
        self::assertSame(0, $result->evictFailed);
        self::assertArrayNotHasKey($payloadKey, $store);
        self::assertArrayNotHasKey($metaKey, $store);
    }

    public function testValidateThrowableCountedAndSweepContinues(): void
    {
        $store = [PersistentOutputCacheService::INDEX_ALL => []];
        $rulesLoader = $this->rulesWithClient(self::CLIENT);
        $cacheService = $this->makeCacheService($store);

        $throwCount = 0;
        $validator = new class($rulesLoader, [self::CLIENT], $throwCount) extends RequestVariableValidator {
            public function __construct(
                RulesLoader $rulesLoader,
                array $enforcedClients,
                private int &$throwCount,
            ) {
                parent::__construct($rulesLoader, $enforcedClients);
            }

            public function assertRequest(string $clientName, ?int $version, ?string $operationName, array $variables): void
            {
                if ($this->throwCount === 0) {
                    ++$this->throwCount;

                    throw new \RuntimeException('validator internal fault');
                }
                parent::assertRequest($clientName, $version, $operationName, $variables);
            }
        };

        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader);
        $this->seedEntry($store, self::CLIENT, 'AllowedOp', ['lang' => 'en']);
        $this->seedEntry($store, self::CLIENT, 'AllowedOp', ['lang' => 'de']);

        $result = $sweep->sweep();

        self::assertSame(2, $result->scanned);
        self::assertSame(1, $result->validateFailed);
        self::assertSame(1, $result->passed);
        self::assertSame(0, $result->evicted);
    }

    /**
     * Characterization: the sweep validates a stored entry whose canonical body
     * carries no `operationName` member against the index operation. The index
     * operation IS in the allowed set with valid variables, so the entry must
     * pass, not be evicted.
     */
    public function testEntryWithoutOperationNameInBodyValidatesAgainstIndexOperation(): void
    {
        $store = [PersistentOutputCacheService::INDEX_ALL => []];
        $rulesLoader = $this->rulesWithClient(self::CLIENT);
        $cacheService = $this->makeCacheService($store);
        $validator = new CapturingRequestVariableValidator($rulesLoader, [self::CLIENT]);
        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader);

        // Seed an entry whose canonical body has NO operationName key but whose
        // index `operation` is an allowed operation with conforming variables.
        $body = json_encode(['query' => '{ x }', 'variables' => ['lang' => 'en']]) ?: '';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString($body);
        $payloadKey = PersistentOutputCacheService::keyPayloadFor(self::CLIENT, $canonical);
        $metaKey = PersistentOutputCacheService::keyMetaFor(self::CLIENT, $canonical);

        $store[PersistentOutputCacheService::INDEX_ALL][] = $payloadKey;
        $store[$payloadKey] = ['data' => ['x' => 1]];
        $store[$metaKey] = [
            'client' => self::CLIENT,
            'operation' => 'AllowedOp',
            'canonical' => $canonical,
        ];

        $result = $sweep->sweep();

        self::assertSame(1, $result->scanned, 'entry should be scanned');
        self::assertSame(1, $result->passed, 'index-fallback operation validates successfully');
        self::assertSame(0, $result->evicted, 'conforming entry must not be evicted');
    }

    public function testLoggerReceivesEvictedEntry(): void
    {
        $store = [PersistentOutputCacheService::INDEX_ALL => []];
        $rulesLoader = $this->rulesWithClient(self::CLIENT);
        $cacheService = $this->makeCacheService($store);
        $validator = new CapturingRequestVariableValidator($rulesLoader, [self::CLIENT]);

        $captured = [];
        $logger = new class($captured) extends AbstractLogger {
            public function __construct(private array &$captured)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->captured[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };

        $sweep = new PersistentCacheRuleSweep($cacheService, $validator, $rulesLoader, $logger);
        $this->seedEntry($store, self::CLIENT, 'ForbiddenOp', []);

        $sweep->sweep();

        $infos = array_filter($captured, static fn ($e) => $e['message'] === 'datahub.request_validation.sweep_evicted');
        self::assertCount(1, $infos);
        $info = reset($infos);
        self::assertSame(self::CLIENT, $info['context']['client']);
    }
}
