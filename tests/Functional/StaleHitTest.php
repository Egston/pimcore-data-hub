<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional;

use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures\KernelTestCase;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Concrete;

/**
 * Pins serve-stale behaviour across the two SWR tiers:
 *
 *  - A populated cache, then an invalidation watermark bump, must surface as
 *    STALE on the next request.
 *  - The STALE response carries the pre-invalidation refreshedAt in its meta
 *    sidecar; a subsequent kernel.terminate refresh-enqueue is observable on
 *    the priority transport.
 *  - The Warning: 110 header rides on the response so retry-aware consumers
 *    can see staleness without re-reading the sidecar.
 */
final class StaleHitTest extends KernelTestCase
{
    public function testHerdGuardedStaleHitServesCacheAndEnqueuesRefresh(): void
    {
        $this->warmCacheForGuardedListing();
        $this->bumpInvalidationWatermark();

        $response = $this->sendGraphQL(
            'getTestSwrGuardedItemListing',
            'query getTestSwrGuardedItemListing { getTestSwrGuardedItemListing { edges { node { id title } } } }'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('STALE', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        self::assertNotNull($response->headers->get('Warning'), 'STALE response must carry Warning: 110');
        self::assertGreaterThan(0, $this->refreshQueueDepth(), 'STALE hit on HERD_GUARDED must enqueue a refresh');
    }

    public function testSwrOnlyStaleHitServesCacheAndEnqueuesRefresh(): void
    {
        $this->warmCacheForSwrOnlyListing();
        $this->bumpInvalidationWatermark();

        $response = $this->sendGraphQL(
            'getTestSwrOnlyItemListing',
            'query getTestSwrOnlyItemListing($defaultLanguage: String) { getTestSwrOnlyItemListing(defaultLanguage: $defaultLanguage) { edges { node { id title } } } }',
            ['defaultLanguage' => 'en']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('STALE', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        self::assertGreaterThan(0, $this->refreshQueueDepth(), 'STALE hit on SWR_ONLY must enqueue a refresh');
    }

    public function testStaleMetaCarriesPreInvalidationRefreshedAt(): void
    {
        $this->warmCacheForGuardedListing();
        $preInvalidationTs = time();
        sleep(1);
        $this->bumpInvalidationWatermark();

        $response = $this->sendGraphQL(
            'getTestSwrGuardedItemListing',
            'query getTestSwrGuardedItemListing { getTestSwrGuardedItemListing { edges { node { id title } } } }'
        );

        self::assertSame('STALE', $response->headers->get('X-Pimcore-DataHub-Persistent-Cache'));

        $canonical = PersistentOutputCacheService::canonicalizePayloadString(json_encode([
            'operationName' => 'getTestSwrGuardedItemListing',
            'query' => 'query getTestSwrGuardedItemListing { getTestSwrGuardedItemListing { edges { node { id title } } } }',
            'variables' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $metaKey = PersistentOutputCacheService::keyMetaFor('default', $canonical);
        $rawMeta = \Pimcore\Cache::load($metaKey);
        self::assertIsArray($rawMeta, 'meta sidecar must be readable from cache after stale serve');
        self::assertArrayHasKey('refreshedAt', $rawMeta);
        self::assertLessThanOrEqual($preInvalidationTs, (int)$rawMeta['refreshedAt'], 'meta sidecar must carry the pre-invalidation refreshedAt');
    }

    public function testPerTagInvalidationListenerDispatchesAndPreservesWatermark(): void
    {
        $this->warmCacheForGuardedListing();
        $watermarkBefore = (int)(\Pimcore\Cache::load(PersistentOutputCacheService::KEY_FALLBACK_WATERMARK_TS) ?: 0);

        $ids = $this->fixtureIds()['TestSwrGuardedItem'] ?? [];
        self::assertNotSame([], $ids, 'TestSwrGuardedItem fixtures must be loaded');
        $object = Concrete::getById($ids[0]);
        self::assertNotNull($object);

        $event = new DataObjectEvent($object);
        \Pimcore::getEventDispatcher()->dispatch($event, DataObjectEvents::POST_UPDATE);

        $watermarkAfter = (int)(\Pimcore\Cache::load(PersistentOutputCacheService::KEY_FALLBACK_WATERMARK_TS) ?: 0);
        self::assertSame(
            $watermarkBefore,
            $watermarkAfter,
            'invalidation listener must not bump watermark when per-tag dispatch succeeds'
        );
        self::assertGreaterThan(0, $this->refreshQueueDepth(), 'invalidation listener must enqueue a refresh for the tagged entry');
    }

    public function testPerTagInvalidationListenerBumpsWatermarkWhenReverseIndexEmpty(): void
    {
        $watermarkBefore = (int)(\Pimcore\Cache::load(PersistentOutputCacheService::KEY_FALLBACK_WATERMARK_TS) ?: 0);

        $ids = $this->fixtureIds()['TestSwrGuardedItem'] ?? [];
        self::assertNotSame([], $ids, 'TestSwrGuardedItem fixtures must be loaded');
        $object = Concrete::getById($ids[0]);
        self::assertNotNull($object);

        $event = new DataObjectEvent($object);
        \Pimcore::getEventDispatcher()->dispatch($event, DataObjectEvents::POST_UPDATE);

        $watermarkAfter = (int)(\Pimcore\Cache::load(PersistentOutputCacheService::KEY_FALLBACK_WATERMARK_TS) ?: 0);
        self::assertGreaterThan($watermarkBefore, $watermarkAfter, 'invalidation listener must bump watermark when reverse-index is empty');
        self::assertSame(0, $this->refreshQueueDepth(), 'invalidation listener must not enqueue when no reverse-index entry exists');
    }

    private function warmCacheForGuardedListing(): void
    {
        $this->sendGraphQL(
            'getTestSwrGuardedItemListing',
            'query getTestSwrGuardedItemListing { getTestSwrGuardedItemListing { edges { node { id title } } } }'
        );
    }

    private function warmCacheForSwrOnlyListing(): void
    {
        $this->sendGraphQL(
            'getTestSwrOnlyItemListing',
            'query getTestSwrOnlyItemListing($defaultLanguage: String) { getTestSwrOnlyItemListing(defaultLanguage: $defaultLanguage) { edges { node { id title } } } }',
            ['defaultLanguage' => 'en']
        );
    }

    private function bumpInvalidationWatermark(): void
    {
        \Pimcore\Cache::save(
            time() + 60,
            PersistentOutputCacheService::KEY_FALLBACK_WATERMARK_TS,
            [PersistentOutputCacheService::TAG_COMMON],
            null,
            0,
            true
        );
    }
}
