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
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
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
        array &$cacheBacking,
        ?OperationClassifier $classifier = null
    ): PersistentCacheInvalidationListener {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => $graphqlConfig]);

        return new class($cacheService, $container, $bus, $cacheBacking, $classifier) extends PersistentCacheInvalidationListener {
            /** @var array<string, mixed> */
            private array $store;

            public function __construct(
                PersistentOutputCacheService $cache,
                ContainerBagInterface $container,
                ?MessageBusInterface $bus,
                array &$store,
                ?OperationClassifier $classifier
            ) {
                parent::__construct($cache, $container, $bus, $classifier);
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

    /**
     * @param array<string, array<string, mixed>> $operations
     */
    private function makeClassifier(array $operations): OperationClassifier
    {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => ['operations' => $operations]]);

        return new OperationClassifier($container);
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
        $hash = PersistentOutputCacheService::entryHash($client, $canonical);
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
            . PersistentOutputCacheService::entryHash($client, $canonical);

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

    public function testCooldownOpLeadingEdgeDispatchesImmediatelyAndArmsSentinel(): void
    {
        // No lastRefreshAt in meta → the entry was never refreshed → leading
        // edge: warm immediately (null deliverAt) and arm the sentinel as the
        // new window's dispatch-dedup.
        $object = $this->makeDataObject(21);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_21';

        $client = 'c1';
        $canonical = '{"q":"cooldown"}';
        $hash = PersistentOutputCacheService::entryHash($client, $canonical);

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_cd', 'persistent_output_meta_cd'],
            ],
            'persistent_output_meta_cd' => [
                'client' => $client,
                'canonical' => $canonical,
                'operation' => 'CooldownOp',
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
        $cache->expects(self::once())->method('armOperationCooldown')->with($hash, 21600);

        $classifier = $this->makeClassifier([
            'CooldownOp' => [
                'tier' => 'swr_only',
                'granularity' => 'list',
                'invalidation_cooldown_ttl' => 21600,
            ],
        ]);

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store,
            $classifier
        );

        $before = time();
        $listener->mark(new DataObjectEvent($object));
        $after = time();

        self::assertCount(2, $dispatched, 'leading-edge dispatches exactly two messages: immediate warm + window-end trailing');
        foreach ($dispatched as $msg) {
            self::assertInstanceOf(PersistentRefreshMessage::class, $msg);
        }

        $immediates = array_values(array_filter($dispatched, fn ($m) => $m->deliverAt === null));
        $trailings = array_values(array_filter($dispatched, fn ($m) => $m->deliverAt !== null));

        self::assertCount(1, $immediates, 'exactly one immediate (null deliverAt) message');
        self::assertCount(1, $trailings, 'exactly one window-end dated trailing message');

        $trailing = $trailings[0];
        self::assertGreaterThanOrEqual($before + 21600, $trailing->deliverAt, 'trailing deliverAt must be at least now+cooldown at dispatch time');
        self::assertLessThanOrEqual($after + 21600, $trailing->deliverAt, 'trailing deliverAt must be at most now+cooldown at dispatch time');
    }

    public function testCooldownOpWithinWindowSchedulesDatedTrailingRefresh(): void
    {
        // lastRefreshAt is recent (inside the cooldown window) and no trailing
        // is yet scheduled → coalesce to a single window-end-dated trailing
        // refresh at lastRefreshAt + cooldown.
        $object = $this->makeDataObject(25);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_25';

        $client = 'c1';
        $canonical = '{"q":"within"}';
        $hash = PersistentOutputCacheService::entryHash($client, $canonical);
        $lastRefreshAt = time() - 100;

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_wn', 'persistent_output_meta_wn'],
            ],
            'persistent_output_meta_wn' => [
                'client' => $client,
                'canonical' => $canonical,
                'operation' => 'CooldownOp',
                'lastRefreshAt' => $lastRefreshAt,
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
        $cache->method('hasOperationCooldown')->with($hash)->willReturn(false);
        $cache->expects(self::once())->method('armOperationCooldown')->with($hash, 21600);

        $classifier = $this->makeClassifier([
            'CooldownOp' => [
                'tier' => 'swr_only',
                'granularity' => 'list',
                'invalidation_cooldown_ttl' => 21600,
            ],
        ]);

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store,
            $classifier
        );

        $listener->mark(new DataObjectEvent($object));

        self::assertCount(1, $dispatched, 'within-window invalidation schedules exactly one dated refresh');
        $msg = $dispatched[0];
        self::assertInstanceOf(PersistentRefreshMessage::class, $msg);
        self::assertSame($lastRefreshAt + 21600, $msg->deliverAt, 'trailing must be dated at lastRefreshAt + cooldown');

        self::assertArrayNotHasKey(
            PersistentOutputCacheService::ENQUEUE_DEDUPE_PREFIX . $hash,
            $store
        );
    }

    public function testCooldownOpSecondInvalidationWithLiveSentinelIsNoOp(): void
    {
        $object = $this->makeDataObject(22);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_22';

        $client = 'c1';
        $canonical = '{"q":"cooldown2"}';
        $hash = PersistentOutputCacheService::entryHash($client, $canonical);

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_cd2', 'persistent_output_meta_cd2'],
            ],
            'persistent_output_meta_cd2' => [
                'client' => $client,
                'canonical' => $canonical,
                'operation' => 'CooldownOp',
                // Recent refresh → within cooldown window, so the live sentinel
                // is the "trailing already scheduled" guard.
                'lastRefreshAt' => time() - 100,
            ],
        ];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $cache = $this->createMock(PersistentOutputCacheService::class);
        // Sentinel alive from a prior edit's schedule: must not re-arm, must not
        // dispatch, and must NOT bump the global watermark (which would cascade-
        // stale everything and defeat the cooldown).
        $cache->method('hasOperationCooldown')->with($hash)->willReturn(true);
        $cache->expects(self::never())->method('armOperationCooldown');
        $cache->expects(self::never())->method('bumpFallbackWatermark');

        $classifier = $this->makeClassifier([
            'CooldownOp' => [
                'tier' => 'swr_only',
                'granularity' => 'list',
                'invalidation_cooldown_ttl' => 21600,
            ],
        ]);

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store,
            $classifier
        );

        $listener->mark(new DataObjectEvent($object));
    }

    public function testNonCooldownOpDispatchesImmediatelyAsRegressionGuard(): void
    {
        $object = $this->makeDataObject(23);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_23';

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_nc', 'persistent_output_meta_nc'],
            ],
            'persistent_output_meta_nc' => [
                'client' => 'c1',
                'canonical' => '{"q":"nocooldown"}',
                'operation' => 'PlainOp',
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
        $cache->expects(self::never())->method('armOperationCooldown');

        // PlainOp is classified but has no invalidation_cooldown_ttl → immediate path.
        $classifier = $this->makeClassifier([
            'PlainOp' => ['tier' => 'swr_only', 'granularity' => 'list'],
        ]);

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store,
            $classifier
        );

        $listener->mark(new DataObjectEvent($object));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(PersistentRefreshMessage::class, $dispatched[0]);
        self::assertNull($dispatched[0]->deliverAt, 'non-cooldown op must dispatch an immediate (null deliverAt) refresh');
    }

    public function testAllEntriesCooldownHandledDoesNotBumpWatermark(): void
    {
        // When every surviving reverse-index entry is a cooldown op that is
        // suppressed by a live sentinel, the outer fallback must treat them as
        // handled (coalesced) and NOT bump the global watermark.
        $object = $this->makeDataObject(24);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_24';

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_h', 'persistent_output_meta_h'],
            ],
            'persistent_output_meta_h' => [
                'client' => 'c1',
                'canonical' => '{"q":"handled"}',
                'operation' => 'CooldownOp',
                // within cooldown window
                'lastRefreshAt' => time() - 100,
            ],
        ];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('hasOperationCooldown')->willReturn(true);
        $cache->expects(self::never())->method('bumpFallbackWatermark');

        $classifier = $this->makeClassifier([
            'CooldownOp' => [
                'tier' => 'swr_only',
                'granularity' => 'list',
                'invalidation_cooldown_ttl' => 21600,
            ],
        ]);

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store,
            $classifier
        );

        $listener->mark(new DataObjectEvent($object));
    }

    public function testCooldownImmediateDispatchPathStampsInvalidatedAt(): void
    {
        $object = $this->makeDataObject(31);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_31';

        $client = 'c1';
        $canonical = '{"q":"stamp_immediate"}';
        $hash = PersistentOutputCacheService::entryHash($client, $canonical);

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_si', 'persistent_output_meta_si'],
            ],
            'persistent_output_meta_si' => [
                'client' => $client,
                'canonical' => $canonical,
                'operation' => 'CooldownOp',
            ],
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new \Symfony\Component\Messenger\Envelope($msg);
        });

        $before = time();

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::once())->method('armOperationCooldown');
        $cache->expects(self::never())->method('bumpFallbackWatermark');
        $cache->expects(self::once())->method('stampInvalidatedAt')
            ->with(
                'persistent_output_meta_si',
                self::callback(fn ($m) => is_array($m) && ($m['operation'] ?? null) === 'CooldownOp'),
                self::callback(fn ($ts) => $ts >= $before)
            );

        $classifier = $this->makeClassifier([
            'CooldownOp' => [
                'tier' => 'swr_only',
                'granularity' => 'list',
                'invalidation_cooldown_ttl' => 21600,
            ],
        ]);

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store,
            $classifier
        );

        $listener->mark(new DataObjectEvent($object));

        self::assertCount(2, $dispatched);
    }

    public function testCooldownCoalescePathAlsoStampsInvalidatedAt(): void
    {
        $object = $this->makeDataObject(32);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_32';

        $client = 'c1';
        $canonical = '{"q":"stamp_coalesce"}';
        $hash = PersistentOutputCacheService::entryHash($client, $canonical);

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_sc', 'persistent_output_meta_sc'],
            ],
            'persistent_output_meta_sc' => [
                'client' => $client,
                'canonical' => $canonical,
                'operation' => 'CooldownOp',
                // within cooldown window
                'lastRefreshAt' => time() - 100,
            ],
        ];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $before = time();

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('hasOperationCooldown')->with($hash)->willReturn(true);
        $cache->expects(self::never())->method('armOperationCooldown');
        $cache->expects(self::never())->method('bumpFallbackWatermark');
        $cache->expects(self::once())->method('stampInvalidatedAt')
            ->with(
                'persistent_output_meta_sc',
                self::callback(fn ($m) => is_array($m) && ($m['operation'] ?? null) === 'CooldownOp'),
                self::callback(fn ($ts) => $ts >= $before)
            );

        $classifier = $this->makeClassifier([
            'CooldownOp' => [
                'tier' => 'swr_only',
                'granularity' => 'list',
                'invalidation_cooldown_ttl' => 21600,
            ],
        ]);

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store,
            $classifier
        );

        $listener->mark(new DataObjectEvent($object));
    }

    public function testCooldownOpLeadingEdgeAlsoEnqueuesWindowEndDatedTrailing(): void
    {
        $object = $this->makeDataObject(41);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_41';

        $client = 'c1';
        $canonical = '{"q":"leading_trailing"}';
        $hash = PersistentOutputCacheService::entryHash($client, $canonical);

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_lt', 'persistent_output_meta_lt'],
            ],
            'persistent_output_meta_lt' => [
                'client' => $client,
                'canonical' => $canonical,
                'operation' => 'CooldownOp',
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
        $cache->expects(self::once())->method('armOperationCooldown')->with($hash, 21600);

        $classifier = $this->makeClassifier([
            'CooldownOp' => [
                'tier' => 'swr_only',
                'granularity' => 'list',
                'invalidation_cooldown_ttl' => 21600,
            ],
        ]);

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store,
            $classifier
        );

        $before = time();
        $listener->mark(new DataObjectEvent($object));
        $after = time();

        self::assertCount(2, $dispatched, 'leading-edge (never-refreshed) dispatches immediate warm + window-end trailing');

        $immediates = array_values(array_filter($dispatched, fn ($m) => $m->deliverAt === null));
        $trailings = array_values(array_filter($dispatched, fn ($m) => $m->deliverAt !== null));

        self::assertCount(1, $immediates, 'exactly one immediate message');
        self::assertCount(1, $trailings, 'exactly one dated trailing message');

        $trailing = $trailings[0];
        self::assertGreaterThanOrEqual($before + 21600, $trailing->deliverAt, 'trailing deliverAt must be now+cooldown, not lastRefreshAt+cooldown');
        self::assertLessThanOrEqual($after + 21600, $trailing->deliverAt, 'trailing deliverAt must be within now+cooldown tolerance');

        foreach ($dispatched as $msg) {
            self::assertSame($client, $msg->client);
            self::assertSame($canonical, $msg->bodyJson);
            self::assertSame('CooldownOp', $msg->operationName);
        }
    }

    public function testLeadingEdgeOrphanTimelineIsClosedByWindowEndTrailing(): void
    {
        // Two sequential marks through the same listener instance against the
        // same hash. The first mark hits the "within window, no trailing yet"
        // branch (lastRefreshAt recent, sentinel absent) — arms the sentinel
        // and schedules the window-end trailing. The second mark arrives while
        // the sentinel armed by the first is still live — must coalesce and
        // write the pending flag rather than scheduling a second trailing.
        // The causal connection: the second mark's coalesce is only possible
        // because the first mark called armOperationCooldown.
        $object = $this->makeDataObject(42);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_42';

        $client = 'c1';
        $canonical = '{"q":"orphan_close"}';
        $hash = PersistentOutputCacheService::entryHash($client, $canonical);
        $lastRefreshAt = time() - 100;
        $pendingKey = PersistentOutputCacheService::PENDING_REFRESH_PREFIX . $hash;

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_oc', 'persistent_output_meta_oc'],
            ],
            'persistent_output_meta_oc' => [
                'client' => $client,
                'canonical' => $canonical,
                'operation' => 'CooldownOp',
                'lastRefreshAt' => $lastRefreshAt,
            ],
        ];

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        // Stateful arm: armOperationCooldown records the hash; hasOperationCooldown
        // returns true only after it has been armed by this same listener instance.
        $armed = [];
        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->expects(self::never())->method('bumpFallbackWatermark');
        $cache->method('armOperationCooldown')
            ->willReturnCallback(function (string $h) use (&$armed): void {
                $armed[$h] = true;
            });
        $cache->method('hasOperationCooldown')
            ->willReturnCallback(function (string $h) use (&$armed): bool {
                return $armed[$h] ?? false;
            });

        $classifier = $this->makeClassifier([
            'CooldownOp' => [
                'tier' => 'swr_only',
                'granularity' => 'list',
                'invalidation_cooldown_ttl' => 21600,
            ],
        ]);

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store,
            $classifier
        );

        // First mark: within window, no sentinel yet → arm + schedule trailing.
        $firstNow = time();
        $listener->mark(new DataObjectEvent($object));

        $trailings = array_values(array_filter($dispatched, fn ($m) => $m->deliverAt !== null));
        self::assertCount(1, $trailings, 'first mark schedules exactly one window-end trailing');
        self::assertSame($lastRefreshAt + 21600, $trailings[0]->deliverAt, 'trailing deliverAt is lastRefreshAt + cooldown');
        self::assertArrayNotHasKey($pendingKey, $store, 'pending flag must not be written on the first (scheduling) mark');

        // Second mark: sentinel now live (armed by first mark) → coalesce and write pending flag.
        $listener->mark(new DataObjectEvent($object));

        self::assertCount(1, $dispatched, 'second mark must not dispatch any new message');
        self::assertArrayHasKey($pendingKey, $store, 'coalesce path must write the pending flag so the worker fires a trailing refresh');
    }

    public function testCooldownOpPastWindowFiresLeadingEdge(): void
    {
        $object = $this->makeDataObject(44);
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($object), '\\'));
        $objectTag = PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_44';

        $client = 'c1';
        $canonical = '{"q":"past_window"}';
        $hash = PersistentOutputCacheService::entryHash($client, $canonical);
        $lastRefreshAt = time() - 2 * 21600;

        $store = [
            PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $objectTag => [
                ['persistent_output_payload_pw', 'persistent_output_meta_pw'],
            ],
            'persistent_output_meta_pw' => [
                'client' => $client,
                'canonical' => $canonical,
                'operation' => 'CooldownOp',
                'lastRefreshAt' => $lastRefreshAt,
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
        $cache->expects(self::once())->method('armOperationCooldown')->with($hash, 21600);

        $classifier = $this->makeClassifier([
            'CooldownOp' => [
                'tier' => 'swr_only',
                'granularity' => 'list',
                'invalidation_cooldown_ttl' => 21600,
            ],
        ]);

        $listener = $this->makeListener(
            [
                'persistent_refresh_queue_enabled' => true,
                'persistent_enqueue_dedupe_ttl' => 60,
            ],
            $bus,
            $cache,
            $store,
            $classifier
        );

        $before = time();
        $listener->mark(new DataObjectEvent($object));
        $after = time();

        self::assertCount(2, $dispatched, 'elapsed-window leading-edge dispatches immediate warm + window-end trailing');

        $immediates = array_values(array_filter($dispatched, fn ($m) => $m->deliverAt === null));
        $trailings = array_values(array_filter($dispatched, fn ($m) => $m->deliverAt !== null));

        self::assertCount(1, $immediates, 'exactly one immediate message');
        self::assertCount(1, $trailings, 'exactly one dated trailing message');

        $trailing = $trailings[0];
        self::assertGreaterThanOrEqual($before + 21600, $trailing->deliverAt, 'elapsed-window trailing must use now+cooldown, not the stale lastRefreshAt+cooldown');
        self::assertLessThanOrEqual($after + 21600, $trailing->deliverAt, 'trailing deliverAt must be within now+cooldown tolerance');
    }
}
