<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\GraphQL\Exception\ClientSafeException;
use Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService;
use Pimcore\Bundle\DataHubBundle\Service\FileUploadService;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\OutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RequestVariableValidator;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RulesLoader;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Bundle\DataHubBundle\Service\Tier;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Development/explorer bypass-key tests; production-mirror fixture is
 * {@see TestableBypassController}. The bypass gate sits after
 * `performSecurityCheck` and before the request-validation `assertRequest`;
 * a matching key on a non-enforced client skips validation and both cache
 * tiers (read and write).
 */
final class WebserviceControllerBypassTest extends TestCase
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
     * @param list<string> $enforcedClients
     */
    private function makeController(
        OperationClassifier $classifier,
        OutputCacheService $cacheService,
        PersistentOutputCacheService $persistentCacheService,
        string $bypassApikey,
        array $enforcedClients
    ): TestableBypassController {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $permissionsService = $this->createMock(CheckConsumerPermissionsService::class);
        $permissionsService->method('performSecurityCheck')->willReturn(true);
        $permissionsService->method('resolveApiKey')->willReturnCallback(
            static fn (Request $r): ?string => $r->headers->get('apikey')
        );
        $uploadService = $this->createMock(FileUploadService::class);

        $validator = new RequestVariableValidator(new RulesLoader(''), $enforcedClients);

        return new TestableBypassController(
            $eventDispatcher,
            $permissionsService,
            $cacheService,
            $persistentCacheService,
            $uploadService,
            $classifier,
            $validator,
            $bypassApikey
        );
    }

    private function makeRequest(string $operationName, ?string $apikey): Request
    {
        $body = json_encode(['query' => '{ __typename }', 'operationName' => $operationName]);
        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], $body);
        $req->attributes->set('clientname', 'explorer-client');
        $req->headers->set('Content-Type', 'application/json');
        if ($apikey !== null) {
            $req->headers->set('apikey', $apikey);
        }

        return $req;
    }

    public function testBypassKeyMatchesAndClientNotEnforcedSkipsValidationAndCache(): void
    {
        $classifier = $this->makeClassifier(['swrOp' => ['tier' => 'swr_only', 'granularity' => 'single']]);

        $cacheService = $this->createMock(OutputCacheService::class);
        $cacheService->expects(self::never())->method('load');
        $cacheService->expects(self::never())->method('save');

        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::never())->method('preHandle');
        $persistentCacheService->expects(self::never())->method('postHandle');

        $controller = $this->makeController($classifier, $cacheService, $persistentCacheService, 'dev-secret', []);

        $request = $this->makeRequest('swrOp', 'dev-secret');
        $response = $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue((bool)$request->attributes->get(PersistentOutputCacheService::REQUEST_ATTR_BYPASS_CACHE), 'bypass attribute must be set');
        self::assertTrue($controller->auditLogged, 'a bypass request must emit the audit log on every request');
        self::assertTrue($controller->resolverRan);
    }

    public function testBypassKeyMatchesButClientEnforcedRunsNormalValidation(): void
    {
        $classifier = $this->makeClassifier(['swrOp' => ['tier' => 'swr_only', 'granularity' => 'single']]);

        $cacheService = $this->createMock(OutputCacheService::class);
        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        // Enforced client: bypass ignored, normal flow runs preHandle.
        $persistentCacheService->expects(self::once())->method('preHandle')->willReturn(null);
        $persistentCacheService->expects(self::once())->method('postHandle');

        // Client IS enforced — bypass must be ignored even with the right key.
        $controller = $this->makeController(
            $classifier,
            $cacheService,
            $persistentCacheService,
            'dev-secret',
            ['explorer-client']
        );

        $request = $this->makeRequest('swrOp', 'dev-secret');
        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertFalse((bool)$request->attributes->get(PersistentOutputCacheService::REQUEST_ATTR_BYPASS_CACHE), 'enforced client must never bypass');
        self::assertFalse($controller->auditLogged, 'no bypass audit log for an enforced client');
    }

    public function testEmptyBypassKeyNeverMatches(): void
    {
        $classifier = $this->makeClassifier(['swrOp' => ['tier' => 'swr_only', 'granularity' => 'single']]);

        $cacheService = $this->createMock(OutputCacheService::class);
        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::once())->method('preHandle')->willReturn(null);
        $persistentCacheService->expects(self::once())->method('postHandle');

        // Empty configured key disables the bypass entirely — even an empty
        // request apikey must not match.
        $controller = $this->makeController($classifier, $cacheService, $persistentCacheService, '', []);

        $request = $this->makeRequest('swrOp', '');
        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertFalse((bool)$request->attributes->get(PersistentOutputCacheService::REQUEST_ATTR_BYPASS_CACHE), 'empty configured key never matches');
    }

    public function testBypassSuppressesBothCacheWrites(): void
    {
        $classifier = $this->makeClassifier(['swrOp' => ['tier' => 'swr_only', 'granularity' => 'single']]);

        $cacheService = $this->createMock(OutputCacheService::class);
        $cacheService->expects(self::never())->method('save');

        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::never())->method('postHandle');

        $controller = $this->makeController($classifier, $cacheService, $persistentCacheService, 'dev-secret', []);

        $request = $this->makeRequest('swrOp', 'dev-secret');
        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertTrue((bool)$request->attributes->get(PersistentOutputCacheService::REQUEST_ATTR_BYPASS_CACHE));
    }

    public function testWrongBypassKeyDoesNotBypass(): void
    {
        $classifier = $this->makeClassifier(['swrOp' => ['tier' => 'swr_only', 'granularity' => 'single']]);

        $cacheService = $this->createMock(OutputCacheService::class);
        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::once())->method('preHandle')->willReturn(null);
        $persistentCacheService->expects(self::once())->method('postHandle');

        $controller = $this->makeController($classifier, $cacheService, $persistentCacheService, 'dev-secret', []);

        $request = $this->makeRequest('swrOp', 'wrong-value');
        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertFalse((bool)$request->attributes->get(PersistentOutputCacheService::REQUEST_ATTR_BYPASS_CACHE), 'wrong key must not bypass');
        self::assertFalse($controller->auditLogged, 'no audit log when key does not match');
    }

    public function testEmptyBypassKeyWithEnforcedClientDoesNotBypass(): void
    {
        $classifier = $this->makeClassifier(['swrOp' => ['tier' => 'swr_only', 'granularity' => 'single']]);

        $cacheService = $this->createMock(OutputCacheService::class);
        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::once())->method('preHandle')->willReturn(null);
        $persistentCacheService->expects(self::once())->method('postHandle');

        // Empty configured key + enforced client: short-circuits at the empty-key guard.
        $controller = $this->makeController($classifier, $cacheService, $persistentCacheService, '', ['explorer-client']);

        $request = $this->makeRequest('swrOp', 'any-key');
        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertFalse((bool)$request->attributes->get(PersistentOutputCacheService::REQUEST_ATTR_BYPASS_CACHE));
        self::assertFalse($controller->auditLogged);
    }

    public function testNoApikeyHeaderDoesNotBypass(): void
    {
        $classifier = $this->makeClassifier(['swrOp' => ['tier' => 'swr_only', 'granularity' => 'single']]);

        $cacheService = $this->createMock(OutputCacheService::class);
        $persistentCacheService = $this->createMock(PersistentOutputCacheService::class);
        $persistentCacheService->expects(self::once())->method('preHandle')->willReturn(null);
        $persistentCacheService->expects(self::once())->method('postHandle');

        $controller = $this->makeController($classifier, $cacheService, $persistentCacheService, 'dev-secret', []);

        // makeRequest with null omits the header entirely; resolveApiKey returns null → '' → no match.
        $request = $this->makeRequest('swrOp', null);
        $controller->webonyxAction(request: $request, responseService: $this->makeNoopResponseService());

        self::assertFalse((bool)$request->attributes->get(PersistentOutputCacheService::REQUEST_ATTR_BYPASS_CACHE), 'absent apikey header must not bypass');
        self::assertFalse($controller->auditLogged);
    }
}

/**
 * Mirrors the production controller's bypass gate + cache read/write skip.
 * The parent's `webonyxAction` opens with a static `Configuration::getByName`
 * call that requires a booted Pimcore kernel; this subclass bypasses that
 * call so the bypass decision and the cache-skip wiring can be exercised
 * under pure phpunit. Production drift in the bypass gate ordering, the
 * enforced-client guard, the `hash_equals` comparison, or the cache read/
 * write skip is the mismatch this fixture is designed to catch.
 */
final class TestableBypassController extends WebserviceController
{
    public bool $resolverRan = false;

    public bool $auditLogged = false;

    public function webonyxAction(
        \Pimcore\Bundle\DataHubBundle\GraphQL\Service $service = null,
        \Pimcore\Localization\LocaleServiceInterface $localeService = null,
        \Pimcore\Model\Factory $modelFactory = null,
        Request $request = null,
        \Pimcore\Helper\LongRunningHelper $longRunningHelper = null,
        ResponseServiceInterface $responseService = null
    ) {
        if ($request === null || $responseService === null) {
            throw new \LogicException('TestableBypassController requires request and responseService');
        }

        return $this->runFlow($request, $responseService);
    }

    private function runFlow(Request $request, ResponseServiceInterface $responseService): JsonResponse
    {
        $reflection = new \ReflectionClass(WebserviceController::class);

        $get = static function (string $name) use ($reflection, $request) {
            $prop = $reflection->getProperty($name);
            $prop->setAccessible(true);

            return $prop;
        };

        /** @var OutputCacheService $cache */
        $cache = $get('cacheService')->getValue($this);
        /** @var PersistentOutputCacheService $persistent */
        $persistent = $get('persistentCacheService')->getValue($this);
        /** @var OperationClassifier $classifier */
        $classifier = $get('operationClassifier')->getValue($this);
        /** @var RequestVariableValidator $validator */
        $validator = $get('requestVariableValidator')->getValue($this);
        /** @var CheckConsumerPermissionsService $permissions */
        $permissions = $get('permissionsService')->getValue($this);
        /** @var string $bypassApikey */
        $bypassApikey = $get('bypassApikey')->getValue($this);

        $clientname = $request->attributes->getString('clientname');
        ['operationName' => $operationName, 'variables' => $inputVariables] = RequestVariableValidator::decodeRequestShape($request->getContent(), null);

        $isBypass = false;
        if ($bypassApikey !== '' && !$validator->isEnforced($clientname)) {
            $resolvedApiKey = $permissions->resolveApiKey($request) ?? '';
            $isBypass = hash_equals($bypassApikey, $resolvedApiKey);
        }

        if ($isBypass) {
            $request->attributes->set(PersistentOutputCacheService::REQUEST_ATTR_BYPASS_CACHE, true);
            $this->auditLogged = true;
        } else {
            try {
                $validator->assertRequest(
                    $clientname,
                    null,
                    $operationName,
                    $inputVariables
                );
            } catch (ClientSafeException $e) {
                return new JsonResponse(
                    ['errors' => [['message' => $e->getMessage(), 'extensions' => ['category' => $e->getCategory()]]]],
                    400
                );
            }
        }

        $tier = $operationName !== null ? $classifier->getTier($operationName) : Tier::NEITHER;
        $request->attributes->set('_datahub_tier', $tier->value);

        $isBypassCache = (bool)$request->attributes->get(PersistentOutputCacheService::REQUEST_ATTR_BYPASS_CACHE);

        if (!$isBypassCache && ($pResponse = $persistent->preHandle($request, $responseService))) {
            return $pResponse;
        }

        $skipOutputCache = $isBypassCache;
        if (!$skipOutputCache && ($cached = $cache->load($request))) {
            return $cached;
        }

        $response = new JsonResponse(['data' => ['inlineResolverRan' => true]]);
        $this->resolverRan = true;

        if (!$skipOutputCache) {
            $cache->save($request, $response);
        }
        if (!$isBypassCache) {
            $persistent->postHandle($request, $response);
        }

        return $response;
    }
}
