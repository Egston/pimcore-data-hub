<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\EventListener\ObjectLoadListener;
use Pimcore\Bundle\DataHubBundle\Service\DependencyCollector;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\DocumentEvents;
use Pimcore\Event\Model\AssetEvent;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Event\Model\DocumentEvent;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Document;

final class ObjectLoadListenerTest extends TestCase
{
    public function testGetSubscribedEventsShape(): void
    {
        $events = ObjectLoadListener::getSubscribedEvents();
        self::assertArrayHasKey(DataObjectEvents::POST_LOAD, $events);
        self::assertArrayHasKey(AssetEvents::POST_LOAD, $events);
        self::assertArrayHasKey(DocumentEvents::POST_LOAD, $events);
        self::assertCount(3, $events);

        self::assertSame(['onDataObjectPostLoad', 0], $events[DataObjectEvents::POST_LOAD]);
        self::assertSame(['onAssetPostLoad', 0], $events[AssetEvents::POST_LOAD]);
        self::assertSame(['onDocumentPostLoad', 0], $events[DocumentEvents::POST_LOAD]);
    }

    public function testOnDataObjectPostLoadForwardsToCollector(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(7);

        $collector = $this->createMock(DependencyCollector::class);
        $collector->expects(self::once())->method('recordObject')->with($object);

        $listener = new ObjectLoadListener($collector);
        $listener->onDataObjectPostLoad(new DataObjectEvent($object));
    }

    public function testOnAssetPostLoadForwardsToCollector(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(8);

        $collector = $this->createMock(DependencyCollector::class);
        $collector->expects(self::once())->method('recordObject')->with($asset);

        $listener = new ObjectLoadListener($collector);
        $listener->onAssetPostLoad(new AssetEvent($asset));
    }

    public function testOnDocumentPostLoadForwardsToCollector(): void
    {
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(9);

        $collector = $this->createMock(DependencyCollector::class);
        $collector->expects(self::once())->method('recordObject')->with($document);

        $listener = new ObjectLoadListener($collector);
        $listener->onDocumentPostLoad(new DocumentEvent($document));
    }

    public function testListenerDoesNotPropagateCollectorExceptions(): void
    {
        $this->expectNotToPerformAssertions();

        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(5);

        $collector = $this->createMock(DependencyCollector::class);
        $collector->method('recordObject')->willThrowException(new \RuntimeException('collector exploded'));

        $listener = new ObjectLoadListener($collector);

        $listener->onDataObjectPostLoad(new DataObjectEvent($object));

        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(6);
        $listener->onAssetPostLoad(new AssetEvent($asset));

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(7);
        $listener->onDocumentPostLoad(new DocumentEvent($document));
    }
}
