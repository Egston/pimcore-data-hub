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

namespace Pimcore\Bundle\DataHubBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\GraphQLRequestCanonicalizer;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;

final class PersistentOutputCacheServiceCanonicalizationParityTest extends TestCase
{
    public function testCanonicalizePayloadStringDelegatesToCanonicalizer(): void
    {
        $body = (string)json_encode([
            'query' => 'query Q { a b }',
            'variables' => ['z' => 3, 'a' => 1],
            'operationName' => 'Q',
        ]);

        self::assertSame(
            GraphQLRequestCanonicalizer::canonicalize($body),
            PersistentOutputCacheService::canonicalizePayloadString($body)
        );
    }
}
