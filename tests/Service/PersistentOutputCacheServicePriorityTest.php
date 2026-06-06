<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PersistentOutputCacheServicePriorityTest extends TestCase
{
    public function testPreHandleSetsRefreshedAtAttributeOnStaleTransition(): void
    {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
        ]]);

        $classifierContainer = $this->createMock(ContainerBagInterface::class);
        $classifierContainer->method('get')->willReturn(['graphql' => [
            'operations' => [
                'TestOp' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
            ],
        ]]);
        $classifier = new OperationClassifier($classifierContainer);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$container, $classifier])
            ->onlyMethods(['cacheLoad', 'cacheSave'])
            ->getMock();

        $past = time() - 3600;
        $meta = [
            'refreshedAt' => $past,
            'client' => 'c1',
            'operation' => 'TestOp',
        ];
        $payload = ['data' => ['x' => 1]];

        $service->method('cacheLoad')->willReturnCallback(function (string $key) use ($meta, $payload) {
            if (str_starts_with($key, 'persistent_output_meta_')) {
                return $meta;
            }
            if (str_starts_with($key, 'persistent_output_payload_')) {
                return $payload;
            }
            if ($key === PersistentOutputCacheService::KEY_FALLBACK_WATERMARK_TS) {
                return time();
            }

            return null;
        });

        $responseService = new class implements ResponseServiceInterface {
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

        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], json_encode([
            'query' => '{ __typename }',
            'operationName' => 'TestOp',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $req->attributes->set('clientname', 'c1');
        $req->headers->set('Content-Type', 'application/json');

        $response = $service->preHandle($req, $responseService);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertTrue($req->attributes->get('_datahub_persistent_refresh'));
        self::assertSame($past, $req->attributes->get('_datahub_persistent_refreshed_at'));
    }
}
