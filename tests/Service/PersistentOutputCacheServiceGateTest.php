<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Pins the post-fold gate-widening contract on `shouldUseForRequest`. The
 * persistent-cache layer must engage for SWR_ONLY operations declared only
 * via the operations: tree — otherwise the SWR_ONLY cold-miss path is dead
 * code for the actual target operations. Exercised through `preHandle` because
 * `shouldUseForRequest` is private; a null preHandle return with applies=true
 * indicates the gate let the request through to MISS, a null without applies
 * indicates the gate rejected.
 */
final class PersistentOutputCacheServiceGateTest extends TestCase
{
    private function makeContainer(array $graphql): ContainerBagInterface
    {
        $c = $this->createMock(ContainerBagInterface::class);
        $c->method('get')->willReturn(['graphql' => $graphql]);

        return $c;
    }

    private function makeClassifier(array $operations): OperationClassifier
    {
        return new OperationClassifier($this->makeContainer(['operations' => $operations]));
    }

    /**
     * @param array<string, mixed> $graphqlExtras
     */
    private function makeService(array $graphqlExtras, ?OperationClassifier $classifier): PersistentOutputCacheService
    {
        $graphql = array_merge([
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 30,
        ], $graphqlExtras);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$this->makeContainer($graphql), $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();
        $service->method('cacheLoad')->willReturn(null);

        return $service;
    }

    private function makeResponseService(): \Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface
    {
        return new class implements \Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface {
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

    private function makeRequest(?string $operationName, string $method = 'POST'): Request
    {
        $body = json_encode([
            'query' => '{ __typename }',
            'operationName' => $operationName,
        ]);
        $req = Request::create('/datahub/graphql', $method, [], [], [], [], $body);
        $req->attributes->set('clientname', 'c1');
        $req->headers->set('Content-Type', 'application/json');

        return $req;
    }

    public function testGateEngagesForOperationsTreeOnlyOp(): void
    {
        // Pins the post-P5 single-membership surface: only the operations: tree
        // is consulted — an op in the operations tree engages the gate regardless
        // of whether it was also in the deprecated in_progress_queries list.
        $service = $this->makeService(
            [],
            $this->makeClassifier([
                'classifiedOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
            ])
        );

        $request = $this->makeRequest('classifiedOp');
        $response = $service->preHandle($request, $this->makeResponseService());

        self::assertNull($response, 'MISS expected since cacheLoad returns null');
        self::assertTrue(
            (bool)$request->attributes->get('_datahub_persistent_applies'),
            'gate must engage for operation declared in operations: tree'
        );
    }

    public function testShouldUseForRequestTrueForOperationsTreeSwrOnlyOperation(): void
    {
        $service = $this->makeService(
            [],
            $this->makeClassifier([
                'swrOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ])
        );

        $request = $this->makeRequest('swrOp');
        $response = $service->preHandle($request, $this->makeResponseService());

        self::assertNull($response);
        self::assertTrue(
            (bool)$request->attributes->get('_datahub_persistent_applies'),
            'gate must engage for SWR_ONLY operation declared via operations: tree'
        );
    }

    public function testShouldUseForRequestTrueForOperationsTreeHerdGuardedOperation(): void
    {
        $service = $this->makeService(
            [],
            $this->makeClassifier([
                'guardedOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
            ])
        );

        $request = $this->makeRequest('guardedOp');
        $response = $service->preHandle($request, $this->makeResponseService());

        self::assertNull($response);
        self::assertTrue(
            (bool)$request->attributes->get('_datahub_persistent_applies'),
            'gate must engage for HERD_GUARDED operation declared via operations: tree'
        );
    }

    public function testShouldUseForRequestFalseForUnclassifiedOperation(): void
    {
        $service = $this->makeService(
            [],
            $this->makeClassifier([
                'someOtherOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ])
        );

        $request = $this->makeRequest('unknownOp');
        $response = $service->preHandle($request, $this->makeResponseService());

        self::assertNull($response);
        self::assertFalse(
            (bool)$request->attributes->get('_datahub_persistent_applies'),
            'gate must reject unclassified operation'
        );
    }

    public function testShouldUseForRequestFalseWhenPersistentCacheDisabled(): void
    {
        $service = $this->makeService(
            ['persistent_output_cache_enabled' => false],
            $this->makeClassifier([
                'swrOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ])
        );

        $request = $this->makeRequest('swrOp');
        $response = $service->preHandle($request, $this->makeResponseService());

        self::assertNull($response);
        self::assertFalse(
            (bool)$request->attributes->get('_datahub_persistent_applies'),
            'gate must reject when persistent_output_cache_enabled=false'
        );
    }

    public function testShouldUseForRequestFalseForNonPostMethod(): void
    {
        $service = $this->makeService(
            [],
            $this->makeClassifier([
                'swrOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ])
        );

        $request = $this->makeRequest('swrOp', 'GET');
        $response = $service->preHandle($request, $this->makeResponseService());

        self::assertNull($response);
        self::assertFalse((bool)$request->attributes->get('_datahub_persistent_applies'));
    }

    public function testShouldUseForRequestFalseForMissingOperationName(): void
    {
        $service = $this->makeService(
            [],
            $this->makeClassifier([
                'swrOp' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ])
        );

        $request = $this->makeRequest(null);
        $response = $service->preHandle($request, $this->makeResponseService());

        self::assertNull($response);
        self::assertFalse((bool)$request->attributes->get('_datahub_persistent_applies'));
    }
}
