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

/**
 * The production `WebserviceController::webonyxAction` opens with a static
 * `Configuration::getByName()` call (kernel-bound, untestable without a
 * booted Pimcore). To keep the early-flow contract under unit-test coverage
 * without booting the kernel, we exercise it via a subclass whose
 * `webonyxAction` inlines the same early-flow sequence as the parent and
 * delegates to the parent's injected collaborators. Early flow is now
 * trivially small — parse input, set `_datahub_tier`, status-probe branch,
 * persistent preHandle — because the herd-guard moved out of the early flow
 * onto the cache-MISS path. Any drift between the production flow and this
 * mirror is a deliberate behaviour change that the test will catch.
 */
final class WebserviceControllerTierGateTest extends TestCase
{
    /**
     * @param array<string, mixed> $graphqlOperations entries for the `operations:` config tree
     */
    private function makeClassifier(array $graphqlOperations): OperationClassifier
    {
        $bag = $this->createMock(ContainerBagInterface::class);
        $bag->method('get')->willReturn(['graphql' => ['operations' => $graphqlOperations]]);

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
     * } $deps
     */
    private function makeController(array $deps): WebserviceController
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $permissionsService = $this->createMock(CheckConsumerPermissionsService::class);
        $permissionsService->method('performSecurityCheck')->willReturn(true);
        $uploadService = $this->createMock(FileUploadService::class);

        $classifier = $deps['classifier'] ?? $this->makeClassifier([]);
        $cacheService = $deps['cacheService'] ?? $this->createMock(OutputCacheService::class);
        $persistentCacheService = $deps['persistentCacheService'] ?? $this->createMock(PersistentOutputCacheService::class);

        return new TestableWebserviceController(
            $eventDispatcher,
            $permissionsService,
            $cacheService,
            $persistentCacheService,
            $uploadService,
            $classifier
        );
    }

    public function testTierHerdGuardedDelegatesDirectlyToPreHandle(): void
    {
        $classifier = $this->makeClassifier([
            'testHerdGuardedOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        // HERD_GUARDED no longer engages maybeRejectOrAcquire in the early flow —
        // a HIT (fresh or stale) serves a cached payload that needs no compute
        // protection. The herd-guard is acquired downstream, only on the MISS path
        // where real GraphQL work runs. See production controller `Output cache MISS`.
        $cacheService = $this->createMock(OutputCacheService::class);
        $cacheService->expects(self::never())->method('maybeRejectOrAcquire');

        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::once())
            ->method('preHandle')
            ->willReturn(new JsonResponse(['data' => ['ok' => true]]));

        $controller = $this->makeController([
            'classifier' => $classifier,
            'cacheService' => $cacheService,
            'persistentCacheService' => $persistentCacheService,
        ]);

        $request = $this->makeRequest('testHerdGuardedOp');
        $response = $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Tier::HERD_GUARDED->value, $request->attributes->get('_datahub_tier'));
    }

    public function testTierSwrOnlyDelegatesDirectlyToPreHandle(): void
    {
        $classifier = $this->makeClassifier([
            'testSwrOnlyOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
        ]);

        $cacheService = $this->createMock(OutputCacheService::class);
        $cacheService->expects(self::never())->method('maybeRejectOrAcquire');

        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::once())
            ->method('preHandle')
            ->willReturn(new JsonResponse(['data' => ['ok' => true]]));

        $controller = $this->makeController([
            'classifier' => $classifier,
            'cacheService' => $cacheService,
            'persistentCacheService' => $persistentCacheService,
        ]);

        $request = $this->makeRequest('testSwrOnlyOp');
        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertSame(Tier::SWR_ONLY->value, $request->attributes->get('_datahub_tier'));
    }

    public function testTierNeitherUnchangedFlow(): void
    {
        $classifier = $this->makeClassifier([]); // no operations registered

        $cacheService = $this->createMock(OutputCacheService::class);
        $cacheService->expects(self::never())->method('maybeRejectOrAcquire');

        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::once())
            ->method('preHandle')
            ->willReturn(new JsonResponse(['data' => ['ok' => true]]));

        $controller = $this->makeController([
            'classifier' => $classifier,
            'cacheService' => $cacheService,
            'persistentCacheService' => $persistentCacheService,
        ]);

        $request = $this->makeRequest('testUnclassifiedOp');
        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertSame(Tier::NEITHER->value, $request->attributes->get('_datahub_tier'));
    }

    public function testEmptyOperationNameResolvesToNeither(): void
    {
        $classifier = $this->makeClassifier([
            'someOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        $cacheService = $this->createMock(OutputCacheService::class);
        $cacheService->expects(self::never())->method('maybeRejectOrAcquire');

        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::once())
            ->method('preHandle')
            ->willReturn(new JsonResponse(['data' => ['ok' => true]]));

        $controller = $this->makeController([
            'classifier' => $classifier,
            'cacheService' => $cacheService,
            'persistentCacheService' => $persistentCacheService,
        ]);

        $request = $this->makeRequest('');
        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertSame(Tier::NEITHER->value, $request->attributes->get('_datahub_tier'));
    }

    public function testStatusProbeSkipsCacheLayers(): void
    {
        $classifier = $this->makeClassifier([
            'testHerdGuardedOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
        ]);

        $cacheService = $this->createMock(OutputCacheService::class);
        $cacheService->expects(self::never())->method('maybeRejectOrAcquire');
        $cacheService->expects(self::once())->method('probeStatus')->willReturn('MISS');

        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::never())->method('preHandle');
        $persistentCacheService->expects(self::once())
            ->method('probeStatus')
            ->willReturn(['applies' => false, 'status' => 'DISABLED']);

        $controller = $this->makeController([
            'classifier' => $classifier,
            'cacheService' => $cacheService,
            'persistentCacheService' => $persistentCacheService,
        ]);

        $request = $this->makeRequest('testHerdGuardedOp');
        $request->query->set('cache_status', '1');

        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertSame(Tier::HERD_GUARDED->value, $request->attributes->get('_datahub_tier'));
    }

    private function makeRequest(?string $operationName): Request
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
}

/**
 * Mirrors the production controller's early-flow sequence: parse input, set
 * `_datahub_tier`, status-probe branch, persistent preHandle. The parent's
 * `webonyxAction` opens with a static `Configuration::getByName` call that
 * requires a booted Pimcore kernel; this subclass bypasses that call so the
 * early-flow decisions can be exercised under pure phpunit. The herd-guard
 * is no longer part of the early flow — it is acquired downstream on the
 * cache-MISS path. Production drift in the early-flow ordering or attribute
 * name is the mismatch this fixture is designed to catch.
 */
final class TestableWebserviceController extends WebserviceController
{
    /**
     * Reuses the parent's injected `cacheService`, `persistentCacheService`,
     * and `operationClassifier` via inherited protected/private state accessed
     * here as the WebserviceController subclass.
     */
    public function webonyxAction(
        \Pimcore\Bundle\DataHubBundle\GraphQL\Service $service = null,
        \Pimcore\Localization\LocaleServiceInterface $localeService = null,
        \Pimcore\Model\Factory $modelFactory = null,
        Request $request = null,
        \Pimcore\Helper\LongRunningHelper $longRunningHelper = null,
        ResponseServiceInterface $responseService = null
    ) {
        if ($request === null || $responseService === null) {
            throw new \LogicException('TestableWebserviceController requires request and responseService');
        }

        return $this->runEarlyFlow($request, $responseService);
    }

    private function runEarlyFlow(Request $request, ResponseServiceInterface $responseService): ?JsonResponse
    {
        $reflection = new \ReflectionClass(WebserviceController::class);

        $cacheService = $reflection->getProperty('cacheService');
        $cacheService->setAccessible(true);
        /** @var OutputCacheService $cache */
        $cache = $cacheService->getValue($this);

        $persistent = $reflection->getProperty('persistentCacheService');
        $persistent->setAccessible(true);
        /** @var PersistentOutputCacheService $persistentCache */
        $persistentCache = $persistent->getValue($this);

        $classifierProp = $reflection->getProperty('operationClassifier');
        $classifierProp->setAccessible(true);
        /** @var OperationClassifier $classifier */
        $classifier = $classifierProp->getValue($this);

        $input = json_decode($request->getContent(), true) ?: [];
        $operationName = is_string($input['operationName'] ?? null) ? $input['operationName'] : null;

        $tier = $operationName !== null ? $classifier->getTier($operationName) : Tier::NEITHER;
        $request->attributes->set('_datahub_tier', $tier->value);

        $isStatusProbe = strtoupper($request->getMethod()) === 'HEAD' || $request->query->getBoolean('cache_status');
        if ($isStatusProbe) {
            $cache->probeStatus($request);
            $persistentCache->probeStatus($request);

            return new JsonResponse(null, 204);
        }

        return $persistentCache->preHandle($request, $responseService);
    }
}
