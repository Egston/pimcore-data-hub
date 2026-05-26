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

namespace Pimcore\Bundle\DataHubBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\EventListener\PersistentCacheInvalidationListener;
use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\DocumentEvents;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\AbstractObject;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class PersistentCacheInvalidationListenerTest extends TestCase
{
    /**
     * @param array<string, mixed> $cacheBacking
     */
    private function makeListener(
        array $graphqlConfig,
        ?MessageBusInterface $bus,
        PersistentOutputCacheService $cacheService,
        array &$cacheBacking
    ): PersistentCacheInvalidationListener {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => $graphqlConfig]);

        return new class($cacheService, $container, $bus, $cacheBacking) extends PersistentCacheInvalidationListener {
            /** @var array<string, mixed> */
            private array $store;

            public function __construct(
                PersistentOutputCacheService $cache,
                ContainerBagInterface $container,
                ?MessageBusInterface $bus,
                array &$store
            ) {
                parent::__construct($cache, $container, $bus);
                $this->store = &$store;
            }

            protected function cacheLoad(string $key)
            {
                return $this->store[$key] ?? null;
            }

            protected function cacheSave($value, string $key, array $tags, int $ttl): void
            {
                $this->store[$key] = $value;
            }
        };
    }

    private function makeDataObject(int $id): AbstractObject
    {
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn($id);

        return $object;
    }

    public function testSubscribedEventsBcWithSixEvents(): void
    {
        $events = PersistentCacheInvalidationListener::getSubscribedEvents();
        self::assertCount(6, $events);
        self::assertSame('mark', $events[DataObjectEvents::POST_UPDATE]);
        self::assertSame('mark', $events[DataObjectEvents::POST_DELETE]);
        self::assertSame('mark', $events[DocumentEvents::POST_UPDATE]);
        self::assertSame('mark', $events[DocumentEvents::POST_DELETE]);
        self::assertSame('mark', $events[AssetEvents::POST_UPDATE]);
        self::assertSame('mark', $events[AssetEvents::POST_DELETE]);
    }

    public function testQueueEnabledDispatchesPerTagRefreshMessages(): void
    {
        $object = $this->makeDataObject(42);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_42';

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_aaa', 'persistent_output_meta_aaa'],
                ['persistent_output_payload_bbb', 'persistent_output_meta_bbb'],
            ],
            'persistent_output_meta_aaa' => [
                'client' => 'c1',
                'canonical' => '{"q":"a"}',
                'operation' => 'OpA',
            ],
            'persistent_output_meta_bbb' => [
                'client' => 'c1',
                'canonical' => '{"q":"b"}',
                'operation' => 'OpB',
            ],
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::never())->method('bumpFallbackWatermark');

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store
        );

        $listener->mark(new DataObjectEvent($object));

        self::assertCount(2, $dispatched);
        foreach ($dispatched as $msg) {
            self::assertInstanceOf(PersistentRefreshMessage::class, $msg);
        }
    }

    public function testQueueEnabledSaveVersionOnlyIsSkipped(): void
    {
        // Pimcore admin's auto-save (`task=autoSave`) and "save as draft"
        // (`task=version`) buttons go through Concrete::saveVersion(), which
        // dispatches POST_UPDATE with `'saveVersionOnly' => true`. These
        // create a new version but DO NOT update the published-version
        // pointer that the DataHub GraphQL resolver reads from, so the
        // public-facing SWR cache is unaffected and we must not
        // dispatch refreshes or bump the watermark.
        //
        // The "publish" / "unpublish" / "first save of never-published item"
        // paths go through AbstractObject::save() (the full path) and fire
        // POST_UPDATE WITHOUT this argument, so they fall through to
        // dispatch normally (covered by other tests).
        $object = $this->makeDataObject(42);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::never())->method('bumpFallbackWatermark');

        $store = [];

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store
        );

        $listener->mark(new DataObjectEvent($object, ['saveVersionOnly' => true, 'isAutoSave' => true]));
    }

    public function testQueueEnabledCoalescedDispatchWritesPendingFlag(): void
    {
        // Pending-flag overlay: when a POST_UPDATE arrives and the enqueue-
        // dedupe sentinel is alive, the listener must record a pending flag
        // for the same hash. The worker reads + clears this flag in its
        // happy-path `finally` and fires a trailing refresh if set. Without
        // the flag, an invalidation event arriving during processing (or
        // between worker completion and dedupe-TTL expiry) would silently
        // drop its changes.
        $object = $this->makeDataObject(8);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_8';

        $client = 'c1';
        $canonical = '{"q":"pending"}';
        $hash = hash('sha256', 'client:' . $client . "\n" . $canonical);
        $dedupeKey = PersistentOutputCacheService::ENQUEUE_DEDUPE_PREFIX . $hash;
        $pendingKey = PersistentOutputCacheService::PENDING_REFRESH_PREFIX . $hash;

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_pending', 'persistent_output_meta_pending'],
            ],
            'persistent_output_meta_pending' => [
                'client' => $client,
                'canonical' => $canonical,
                'operation' => 'OpPending',
            ],
            $dedupeKey => 1,
        ];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::never())->method('bumpFallbackWatermark');

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store
        );

        $listener->mark(new DataObjectEvent($object));

        self::assertArrayHasKey($pendingKey, $store, 'coalesce path must write the pending flag for the worker to pick up');
    }

    public function testQueueEnabledDedupSentinelSuppressesSecondDispatchWithoutWatermarkBump(): void
    {
        // Coalesce path: the dedupe sentinel is alive from a prior dispatch.
        // The load-bearing assertion is "no watermark bump" — bumping it
        // would mark every persistent-cache entry STALE and cascade
        // unrelated refreshes via the kernel.terminate dispatcher.
        $object = $this->makeDataObject(7);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_7';

        $client = 'c1';
        $canonical = '{"q":"dup"}';
        $dedupeKey = PersistentOutputCacheService::ENQUEUE_DEDUPE_PREFIX
            . hash('sha256', 'client:' . $client . "\n" . $canonical);

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_dup', 'persistent_output_meta_dup'],
            ],
            'persistent_output_meta_dup' => [
                'client' => $client,
                'canonical' => $canonical,
                'operation' => 'OpDup',
            ],
            $dedupeKey => 1,
        ];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::never())->method('bumpFallbackWatermark');

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store
        );

        $listener->mark(new DataObjectEvent($object));
    }

    public function testQueueDisabledCallsBumpFallbackWatermark(): void
    {
        $object = $this->makeDataObject(42);

        $store = [];

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::once())->method('bumpFallbackWatermark');

        $listener = $this->makeListener(
            ['persistent_refresh_queue_enabled' => false],
            null,
            $cache,
            $store
        );

        $listener->mark(new DataObjectEvent($object));
    }

    public function testQueueEnabledButBusMissingFallsBackToWatermark(): void
    {
        $object = $this->makeDataObject(42);

        $store = [];

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::once())->method('bumpFallbackWatermark');

        $listener = $this->makeListener(
            ['persistent_refresh_queue_enabled' => true],
            null, // bus missing — gate falls through to watermark
            $cache,
            $store
        );

        $listener->mark(new DataObjectEvent($object));
    }

    public function testThrowableInLookupLogsErrorWithoutPropagating(): void
    {
        $object = $this->makeDataObject(42);

        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('boom'));

        $cache = $this->createMock(PersistentOutputCacheService::class);
        // Exception in the try block triggers the safety-floor watermark bump.
        $cache->expects(self::once())->method('bumpFallbackWatermark');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $listener = new PersistentCacheInvalidationListener($cache, $container, $bus);

        // Must not propagate.
        $listener->mark(new DataObjectEvent($object));
    }

    public function testDispatchForTagsSkipsMalformedReverseIndexEntries(): void
    {
        $object = $this->makeDataObject(55);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_55';

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                // non-string key (would cast to "Array")
                [['nested'], 'persistent_output_meta_ok'],
                // missing PAYLOAD_KEY_PREFIX
                ['wrong_prefix_key', 'persistent_output_meta_ok'],
                // valid entry
                ['persistent_output_payload_valid', 'persistent_output_meta_valid'],
            ],
            'persistent_output_meta_valid' => [
                'client' => 'c1',
                'canonical' => '{"q":"v"}',
                'operation' => 'OpV',
            ],
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::never())->method('bumpFallbackWatermark');

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store
        );

        $listener->mark(new DataObjectEvent($object));

        self::assertCount(1, $dispatched, 'only the valid entry should produce a dispatch');
        self::assertInstanceOf(PersistentRefreshMessage::class, $dispatched[0]);
    }

    public function testQueueEnabledWithNullElementFallsBackToWatermark(): void
    {
        // A non-ElementEventInterface event yields null from extractElement(),
        // which must fall back to the watermark rather than silently dropping
        // the invalidation.
        $store = [];
        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::once())->method('bumpFallbackWatermark');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store
        );

        // Use a plain Event (not ElementEventInterface) so extractElement() returns null.
        $listener->mark(new \Symfony\Contracts\EventDispatcher\Event());
    }

    public function testQueueEnabledThrowFallsBackToWatermark(): void
    {
        // A throw inside the queue-dispatch path must still bump the watermark
        // as the safety floor so invalidation is never silently lost.
        $object = $this->makeDataObject(42);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_42';

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_x', 'persistent_output_meta_x'],
            ],
            'persistent_output_meta_x' => [
                'client' => 'c1',
                'canonical' => '{"q":"x"}',
                'operation' => 'OpX',
            ],
        ];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('bus down'));

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::once())->method('bumpFallbackWatermark');

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store
        );

        $listener->mark(new DataObjectEvent($object));
    }

    public function testQueueEnabledZeroDispatchesFallsBackToWatermark(): void
    {
        $object = $this->makeDataObject(99);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_99';
        $classTag = PersistentOutputCacheService::TAG_CLASS_PREFIX . $sanitizedClass;

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [],
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $classTag => [],
        ];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::once())->method('bumpFallbackWatermark');

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store
        );

        $listener->mark(new DataObjectEvent($object));
    }

    public function testQueueEnabledAllMalformedPairsDoesNotBumpWatermark(): void
    {
        // When reverse-index entries exist but are all malformed, the listener
        // already warns per-entry. The earlier behaviour also bumped the
        // global watermark, which made every persistent-cache entry STALE and
        // kicked the kernel.terminate dispatcher into a cascade of unrelated
        // refreshes on the next probe. The fix: do not amplify a data-shape
        // bug into a global invalidation — log and move on.
        $object = $this->makeDataObject(11);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_11';

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                [null, null],
            ],
        ];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::never())->method('bumpFallbackWatermark');

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store
        );

        $listener->mark(new DataObjectEvent($object));
    }

    public function testQueueEnabledNullElementIdFallsBackToWatermark(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(null);

        $store = [];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::once())->method('bumpFallbackWatermark');

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store
        );

        $listener->mark(new DataObjectEvent($object));
    }
}
