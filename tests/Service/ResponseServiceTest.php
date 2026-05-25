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
use Symfony\Component\HttpFoundation\JsonResponse;

final class ResponseServiceTest extends TestCase
{
    public function testAddCorsHeadersExposesCacheStatusHeadersToBrowserJs(): void
    {
        $service = new ResponseService();
        $response = new JsonResponse();

        $service->addCorsHeaders($response);

        $expose = $response->headers->get('Access-Control-Expose-Headers');
        self::assertNotNull(
            $expose,
            'Without Access-Control-Expose-Headers, browser JS cannot read custom cache headers '
            . 'on cross-origin GraphQL responses — the SWR stale banner stays invisible.'
        );

        // SWR signal consumed by the admin translation-verification stale banner.
        self::assertStringContainsString('X-Pimcore-DataHub-Persistent-Cache', $expose);
        // RFC 7234 companion warning emitted alongside STALE.
        self::assertStringContainsString('Warning', $expose);
        // Regular HIT/MISS marker, useful for ops debugging from the browser.
        self::assertStringContainsString('X-Pimcore-DataHub-Cache', $expose);
    }

    public function testAddCorsHeadersKeepsExistingAllowHeaders(): void
    {
        $service = new ResponseService();
        $response = new JsonResponse();

        $service->addCorsHeaders($response);

        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        self::assertSame('GET, POST, OPTIONS', $response->headers->get('Access-Control-Allow-Methods'));
        self::assertSame(
            'Origin, Content-Type, X-Auth-Token',
            $response->headers->get('Access-Control-Allow-Headers')
        );
    }
}
