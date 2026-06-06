<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService;
use Pimcore\Bundle\DataHubBundle\Service\FileUploadService;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\OutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Bundle\DataHubBundle\Service\Tier;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** SWR_ONLY cold-miss branch tests; production-mirror fixture is `TestableSwrColdMissController`. */
final class WebserviceControllerSwrColdMissTest extends TestCase
{
    private function makeClassifier(array $operations): OperationClassifier
    {
        $bag = $this->createMock(ContainerBagInterface::class);
        $bag->method('get')->willReturn(['graphql' => ['operations' => $operations]]);

        return new OperationClassifier($bag);
    }

    private function makeNoopResponseService(): ResponseServiceInterface
    {
        return new class implements ResponseServiceInterface {
            public function removeCorsHeaders(JsonResponse $response): void
            {
            }

            public function addCorsHeaders(JsonResponse $response): void
            {
            }

            public function addHitMissHeaders(JsonResponse $response, bool $isCacheHit): void
            {
            }
        };
    }

    /**
     * @param array{
     *     classifier?: OperationClassifier,
     *     cacheService?: OutputCacheService,
     *     persistentCacheService?: PersistentOutputCacheService,
     *     graphqlCfg?: array<string, mixed>,
     * } $deps
     */
    private function makeController(array $deps): TestableSwrColdMissController
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $permissionsService = $this->createMock(CheckConsumerPermissionsService::class);
        $permissionsService->method('performSecurityCheck')->willReturn(true);
        $uploadService = $this->createMock(FileUploadService::class);

        $classifier = $deps['classifier'] ?? $this->makeClassifier([]);
        $cacheService = $deps['cacheService'] ?? $this->createMock(OutputCacheService::class);
        $persistentCacheService = $deps['persistentCacheService'] ?? $this->createMock(PersistentOutputCacheService::class);

        $controller = new TestableSwrColdMissController(
            $eventDispatcher,
            $permissionsService,
            $cacheService,
            $persistentCacheService,
            $uploadService,
            $classifier
        );
        $controller->graphqlCfg = $deps['graphqlCfg'] ?? [
            'swr_cold_miss_lock_wait_ms' => 5000,
            'swr_cold_miss_lock_ttl' => 30,
        ];

        return $controller;
    }

    private function makeRequest(string $operationName): Request
    {
        $body = json_encode([
            'query' => '{ __typename }',
            'operationName' => $operationName,
        ]);
        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], $body);
        $req->attributes->set('clientname', 'client-test');
        $req->headers->set('Content-Type', 'application/json');

        return $req;
    }

    public function testSwrOnlyWinnerAcquiresLockAndRunsResolverInline(): void
    {
        $classifier = $this->makeClassifier([
            'swrOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $winnerLock = new \stdClass();

        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::once())
            ->method('preHandle')
            ->willReturn(null);
        $persistentCacheService->expects(self::once())
            ->method('acquireColdMissLock')
            ->with(self::anything(), 30)
            ->willReturn($winnerLock);
        $persistentCacheService->expects(self::once())
            ->method('postHandle');
        $persistentCacheService->expects(self::once())
            ->method('releaseColdMissLock')
            ->with($winnerLock);

        $controller = $this->makeController([
            'classifier' => $classifier,
            'persistentCacheService' => $persistentCacheService,
        ]);

        $request = $this->makeRequest('swrOp');
        $response = $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertNotSame(503, $response->getStatusCode());
        self::assertFalse(
            $request->attributes->has('_datahub_swr_cold_miss_lock'),
            'attribute must be cleared after release'
        );
        self::assertTrue($controller->resolverRan, 'winner must run the inline resolver');
    }

    public function testSwrOnlyLoserPollsAndReturnsCachedResponseWhenWinnerWrites(): void
    {
        $classifier = $this->makeClassifier([
            'swrOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $callCount = 0;
        $cachedPayload = new JsonResponse(['data' => ['fromWinner' => true]]);

        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->method('preHandle')
            ->willReturnCallback(function () use (&$callCount, $cachedPayload): ?JsonResponse {
                $callCount++;

                // First call: cold (controller's pre-cold-miss preHandle).
                // Second call: still cold during first poll tick.
                // Third call: winner published — return the cached response.
                return $callCount >= 3 ? $cachedPayload : null;
            });
        $persistentCacheService->expects(self::once())
            ->method('acquireColdMissLock')
            ->willReturn(null);
        $persistentCacheService->expects(self::never())->method('postHandle');
        $persistentCacheService->expects(self::never())->method('releaseColdMissLock');
        $persistentCacheService->expects(self::never())->method('savePersistent');

        $cacheService = $this->createMock(OutputCacheService::class);
        $cacheService->expects(self::never())->method('save');

        $controller = $this->makeController([
            'classifier' => $classifier,
            'cacheService' => $cacheService,
            'persistentCacheService' => $persistentCacheService,
            'graphqlCfg' => [
                'swr_cold_miss_lock_wait_ms' => 5000,
                'swr_cold_miss_lock_ttl' => 30,
            ],
        ]);

        $request = $this->makeRequest('swrOp');
        $response = $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertSame($cachedPayload, $response);
        self::assertFalse(
            $request->attributes->has('_datahub_swr_cold_miss_lock'),
            'loser never acquired the lock; attribute must not be present'
        );
        self::assertFalse($controller->resolverRan, 'loser must not run resolver on poll-hit');
    }

    public function testSwrOnlyLoserTimeoutFallsThroughToInlineResolverNever503(): void
    {
        $classifier = $this->makeClassifier([
            'swrOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->method('preHandle')->willReturn(null);
        $persistentCacheService->expects(self::once())
            ->method('acquireColdMissLock')
            ->willReturn(null);
        $persistentCacheService->expects(self::once())->method('postHandle');
        $persistentCacheService->expects(self::once())
            ->method('releaseColdMissLock')
            ->with(null); // loser-after-timeout holds no lock; release is no-op

        $controller = $this->makeController([
            'classifier' => $classifier,
            'persistentCacheService' => $persistentCacheService,
            'graphqlCfg' => [
                'swr_cold_miss_lock_wait_ms' => 100,
                'swr_cold_miss_lock_ttl' => 30,
            ],
        ]);

        $request = $this->makeRequest('swrOp');
        $response = $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertNotSame(
            503,
            $response->getStatusCode(),
            'never-503-for-SWR_ONLY browsers: loser-after-timeout must fall through to inline resolver'
        );
        self::assertFalse(
            $request->attributes->has('_datahub_swr_cold_miss_lock'),
            'loser-after-timeout holds no lock; attribute must not be present'
        );
        self::assertTrue(
            $controller->resolverRan,
            'loser-after-timeout must run the inline resolver as defensive fallback'
        );
    }

    public function testSwrOnlyTimeoutFallbackClearsPersistentRefreshAttribute(): void
    {
        $classifier = $this->makeClassifier([
            'swrOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $preHandleCallCount = 0;
        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->method('preHandle')
            ->willReturnCallback(function (Request $request) use (&$preHandleCallCount): ?JsonResponse {
                $preHandleCallCount++;
                // First preHandle (pre-cold-miss): clean MISS, no refresh.
                // Subsequent calls (poll iterations): simulate a brief STALE
                // observation that sets the refresh attribute and returns
                // null. The timeout fallback must clear it before postHandle.
                if ($preHandleCallCount >= 2) {
                    $request->attributes->set('_datahub_persistent_refresh', true);
                }

                return null;
            });
        $persistentCacheService->method('acquireColdMissLock')->willReturn(null);
        $persistentCacheService->expects(self::once())
            ->method('postHandle')
            ->willReturnCallback(function (Request $request, JsonResponse $response): void {
                // The contract: by the time postHandle observes the request,
                // the refresh attribute must be cleared — otherwise the
                // kernel.terminate listener would fire a refresh against the
                // very response we just wrote.
                if ((bool)$request->attributes->get('_datahub_persistent_refresh')) {
                    throw new \LogicException(
                        'timeout fallback must clear _datahub_persistent_refresh before postHandle'
                    );
                }
            });

        $controller = $this->makeController([
            'classifier' => $classifier,
            'persistentCacheService' => $persistentCacheService,
            'graphqlCfg' => [
                'swr_cold_miss_lock_wait_ms' => 100,
                'swr_cold_miss_lock_ttl' => 30,
            ],
        ]);

        $request = $this->makeRequest('swrOp');
        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertFalse(
            (bool)$request->attributes->get('_datahub_persistent_refresh'),
            '_datahub_persistent_refresh must be cleared by the timeout-fallback branch'
        );
    }

    public function testSwrOnlyBackgroundRefreshSkipsColdMissBranch(): void
    {
        $classifier = $this->makeClassifier([
            'swrOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->method('preHandle')->willReturn(null);
        $persistentCacheService->expects(self::never())->method('acquireColdMissLock');
        $persistentCacheService->expects(self::once())->method('postHandle');
        $persistentCacheService->expects(self::once())
            ->method('releaseColdMissLock')
            ->with(null);

        $controller = $this->makeController([
            'classifier' => $classifier,
            'persistentCacheService' => $persistentCacheService,
        ]);

        $request = $this->makeRequest('swrOp');
        $request->attributes->set('_datahub_persistent_refresh', true);
        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertTrue($controller->resolverRan, 'background-refresh request must run inline resolver');
    }
}

/**
 * Mirrors the production controller's early-flow + SWR_ONLY cold-miss branch
 * + downstream save flow. The parent's `webonyxAction` opens with a static
 * `Configuration::getByName` call that requires a booted Pimcore kernel;
 * this subclass bypasses that call so the cold-miss decision can be
 * exercised under pure phpunit. Production drift in cold-miss gate ordering,
 * attribute names, or lock release timing is the mismatch this fixture is
 * designed to catch.
 */
final class TestableSwrColdMissController extends WebserviceController
{
    /** @var array<string, mixed> */
    public array $graphqlCfg = [];

    public bool $resolverRan = false;

    public function webonyxAction(
        \Pimcore\Bundle\DataHubBundle\GraphQL\Service $service = null,
        \Pimcore\Localization\LocaleServiceInterface $localeService = null,
        \Pimcore\Model\Factory $modelFactory = null,
        Request $request = null,
        \Pimcore\Helper\LongRunningHelper $longRunningHelper = null,
        ResponseServiceInterface $responseService = null
    ) {
        if ($request === null || $responseService === null) {
            throw new \LogicException('TestableSwrColdMissController requires request and responseService');
        }

        return $this->runFlow($request, $responseService);
    }

    private function runFlow(Request $request, ResponseServiceInterface $responseService): JsonResponse
    {
        $reflection = new \ReflectionClass(WebserviceController::class);

        $cacheProp = $reflection->getProperty('cacheService');
        $cacheProp->setAccessible(true);
        /** @var OutputCacheService $cache */
        $cache = $cacheProp->getValue($this);

        $persistentProp = $reflection->getProperty('persistentCacheService');
        $persistentProp->setAccessible(true);
        /** @var PersistentOutputCacheService $persistent */
        $persistent = $persistentProp->getValue($this);

        $classifierProp = $reflection->getProperty('operationClassifier');
        $classifierProp->setAccessible(true);
        /** @var OperationClassifier $classifier */
        $classifier = $classifierProp->getValue($this);

        $input = json_decode($request->getContent(), true) ?: [];
        $operationName = is_string($input['operationName'] ?? null) ? $input['operationName'] : null;

        $tier = $operationName !== null ? $classifier->getTier($operationName) : Tier::NEITHER;
        $request->attributes->set('_datahub_tier', $tier->value);

        if ($pResponse = $persistent->preHandle($request, $responseService)) {
            $responseService->addHitMissHeaders($pResponse, true);

            return $pResponse;
        }

        // SWR_ONLY cold-miss branch — mirror of production.
        if ($tier === Tier::SWR_ONLY
            && !$request->attributes->get('_datahub_persistent_refresh')
        ) {
            $waitMs = max(0, (int)($this->graphqlCfg['swr_cold_miss_lock_wait_ms'] ?? 5000));
            $lockTtl = max(1, (int)($this->graphqlCfg['swr_cold_miss_lock_ttl'] ?? 30));

            $lock = $persistent->acquireColdMissLock($request, $lockTtl);
            if ($lock === null && $waitMs > 0) {
                $deadline = microtime(true) + ($waitMs / 1000.0);
                while (microtime(true) < $deadline) {
                    usleep(50_000);
                    $pResp = $persistent->preHandle($request, $responseService);
                    if ($pResp) {
                        $responseService->addHitMissHeaders($pResp, true);

                        return $pResp;
                    }
                }
                $request->attributes->remove('_datahub_persistent_refresh');
            }
            if ($lock !== null) {
                $request->attributes->set('_datahub_swr_cold_miss_lock', $lock);
            }
        }

        $response = new JsonResponse(['data' => ['inlineResolverRan' => true]]);
        $this->resolverRan = true;

        try {
            $persistent->postHandle($request, $response);
        } finally {
            $persistent->releaseColdMissLock(
                $request->attributes->get('_datahub_swr_cold_miss_lock')
            );
            $request->attributes->remove('_datahub_swr_cold_miss_lock');
        }

        return $response;
    }
}
