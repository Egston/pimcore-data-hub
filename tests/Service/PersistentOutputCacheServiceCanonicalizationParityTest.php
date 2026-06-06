<?php

declare(strict_types=1);

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
