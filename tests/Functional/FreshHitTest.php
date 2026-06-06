<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional;

use Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures\KernelTestCase;

/**
 * Pins the fresh-hit invariant for the two SWR tiers: a populated cache
 * served immediately on a subsequent request, with no refresh-queue
 * dispatch and no resolver run. The cache-status surfacing distinguishes a
 * fresh hit from a stale serve via the X-Pimcore-DataHub-Persistent-Cache
 * header carrying `HIT`.
 */
final class FreshHitTest extends KernelTestCase
{
    public function testHerdGuardedFreshHitServesFromCacheWithNoEnqueue(): void
    {
        $this->sendGraphQL(
            'getTestSwrGuardedItemListing',
            'query getTestSwrGuardedItemListing { getTestSwrGuardedItemListing { edges { node { id title } } } }'
        );
        $beforeDepth = $this->refreshQueueDepth();

        $response = $this->sendGraphQL(
            'getTestSwrGuardedItemListing',
            'query getTestSwrGuardedItemListing { getTestSwrGuardedItemListing { edges { node { id title } } } }'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('HIT', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        self::assertSame($beforeDepth, $this->refreshQueueDepth(), 'Fresh-hit must not enqueue any refresh');
    }

    public function testSwrOnlyFreshHitServesFromCacheWithNoEnqueue(): void
    {
        $this->sendGraphQL(
            'getTestSwrOnlyItemListing',
            'query getTestSwrOnlyItemListing($defaultLanguage: String) { getTestSwrOnlyItemListing(defaultLanguage: $defaultLanguage) { edges { node { id title } } } }',
            ['defaultLanguage' => 'en']
        );
        $beforeDepth = $this->refreshQueueDepth();

        $response = $this->sendGraphQL(
            'getTestSwrOnlyItemListing',
            'query getTestSwrOnlyItemListing($defaultLanguage: String) { getTestSwrOnlyItemListing(defaultLanguage: $defaultLanguage) { edges { node { id title } } } }',
            ['defaultLanguage' => 'en']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('HIT', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        self::assertSame($beforeDepth, $this->refreshQueueDepth());
    }
}
