<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional;

use Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures\KernelTestCase;

/**
 * Pins the cold-miss tier-behaviour invariant across all three tiers:
 *
 *  - HERD_GUARDED on cache miss returns HTTP 503 + Retry-After header so the
 *    retry-aware programmatic consumer can back off and a second caller
 *    cannot fan out an unprotected resolver run alongside the first.
 *  - SWR_ONLY on cache miss runs the resolver inline and returns 200; the
 *    never-503-for-browsers invariant rides on this path.
 *  - NEITHER falls through to the standard output cache layer; the second
 *    request observes an output-cache HIT.
 */
final class ColdMissTest extends KernelTestCase
{
    public function testHerdGuardedColdMissReturns503WithRetryAfter(): void
    {
        $response = $this->sendGraphQL(
            'getTestSwrGuardedItemListing',
            'query getTestSwrGuardedItemListing { getTestSwrGuardedItemListing { edges { node { id title } } } }'
        );

        self::assertSame(503, $response->getStatusCode(), 'HERD_GUARDED cold miss must reject duplicates with 503');
        self::assertGreaterThan(0, (int)$response->headers->get('Retry-After', '0'), 'HERD_GUARDED 503 must carry a positive Retry-After value');
    }

    public function testSwrOnlyColdMissRunsResolverInline(): void
    {
        $response = $this->sendGraphQL(
            'getTestSwrOnlyItemListing',
            'query getTestSwrOnlyItemListing($defaultLanguage: String) { getTestSwrOnlyItemListing(defaultLanguage: $defaultLanguage) { edges { node { id title } } } }',
            ['defaultLanguage' => 'en']
        );

        self::assertSame(200, $response->getStatusCode(), 'SWR_ONLY must never 503 on cold miss');
        $body = json_decode((string)$response->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('errors', $body, 'SWR_ONLY inline resolver must complete without error');
    }

    public function testNeitherFallsThroughToStandardOutputCacheOnSecondHit(): void
    {
        $first = $this->sendGraphQL(
            'getTestUncachedItemListing',
            'query getTestUncachedItemListing { getTestUncachedItemListing { edges { node { id title } } } }'
        );
        self::assertSame(200, $first->getStatusCode());

        $second = $this->sendGraphQL(
            'getTestUncachedItemListing',
            'query getTestUncachedItemListing { getTestUncachedItemListing { edges { node { id title } } } }'
        );
        self::assertSame(200, $second->getStatusCode());
        self::assertSame('HIT', $second->headers->get('X-Pimcore-DataHub-Cache'), 'NEITHER tier must surface X-Pimcore-DataHub-Cache: HIT on second hit');
    }
}
