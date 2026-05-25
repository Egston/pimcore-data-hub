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
            'persistent_output_cache_guard_only' => true,
            'in_progress_queries' => ['TestOp'],
        ]]);

        $service = $this->getMockBuilder(PersistentOutputCacheService::class)
            ->setConstructorArgs([$container])
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
            if ($key === PersistentOutputCacheService::KEY_LAST_INVALIDATION) {
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
