<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\DependencyCollector;
use Pimcore\Bundle\DataHubBundle\Service\Granularity;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\ElementInterface;

final class DependencyCollectorTest extends TestCase
{
    private function fakeElement(string $class, int $id): ElementInterface
    {
        $mock = $this->createMock($class);
        $mock->method('getId')->willReturn($id);

        return $mock;
    }

    public function testEmptyStateHasRecordedAnyFalse(): void
    {
        $collector = new DependencyCollector();
        self::assertFalse($collector->hasRecordedAny());
        self::assertSame([], $collector->tagsForGranularity(Granularity::SINGLE));
        self::assertSame([], $collector->tagsForGranularity(Granularity::LIST));
    }

    public function testRecordObjectDeduplicatesByClassAndId(): void
    {
        $collector = new DependencyCollector();
        $element = $this->fakeElement(AbstractObject::class, 42);
        $collector->recordObject($element);
        $collector->recordObject($element);
        $collector->recordObject($this->fakeElement(AbstractObject::class, 42));

        $tags = $collector->tagsForGranularity(Granularity::SINGLE);
        self::assertCount(1, $tags);
        $expected = 'datahub_graphql_obj_' . str_replace('\\', '_', ltrim(get_class($element), '\\')) . '_42';
        self::assertSame($expected, $tags[0]);
    }

    public function testTagsForGranularitySingleEmitsPerObjectTags(): void
    {
        $collector = new DependencyCollector();
        $e1 = $this->fakeElement(AbstractObject::class, 1);
        $e2 = $this->fakeElement(AbstractObject::class, 2);
        $e3 = $this->fakeElement(AbstractObject::class, 3);
        $collector->recordObject($e1);
        $collector->recordObject($e2);
        $collector->recordObject($e3);

        $tags = $collector->tagsForGranularity(Granularity::SINGLE);
        self::assertCount(3, $tags);

        $build = static fn (object $e, int $id): string => 'datahub_graphql_obj_'
            . str_replace('\\', '_', ltrim(get_class($e), '\\')) . '_' . $id;
        self::assertContains($build($e1, 1), $tags);
        self::assertContains($build($e2, 2), $tags);
        self::assertContains($build($e3, 3), $tags);

        foreach ($tags as $tag) {
            self::assertSame(
                0,
                preg_match('/[:{}()\/\\\\@]/', $tag),
                'tag contains a PSR-6 reserved character: ' . $tag
            );
        }
    }

    public function testTagsForGranularityListEmitsClassTagsOnly(): void
    {
        $collector = new DependencyCollector();
        $obj1 = $this->fakeElement(AbstractObject::class, 1);
        $obj2 = $this->fakeElement(AbstractObject::class, 2);
        $asset = $this->fakeElement(\Pimcore\Model\Asset::class, 99);

        $collector->recordObject($obj1);
        $collector->recordObject($obj2);
        $collector->recordObject($asset);

        $tags = $collector->tagsForGranularity(Granularity::LIST);
        // PHPUnit reuses the same mock subclass per source class, so
        // get_class($obj1) === get_class($obj2). Two distinct source
        // classes (AbstractObject, Asset) → two tags.
        self::assertCount(2, $tags);
        $expectedObj = 'datahub_graphql_class_' . str_replace('\\', '_', ltrim(get_class($obj1), '\\'));
        $expectedAsset = 'datahub_graphql_class_' . str_replace('\\', '_', ltrim(get_class($asset), '\\'));
        self::assertContains($expectedObj, $tags);
        self::assertContains($expectedAsset, $tags);
        self::assertNotSame($expectedObj, $expectedAsset);
    }

    public function testResetClearsState(): void
    {
        $collector = new DependencyCollector();
        $collector->recordObject($this->fakeElement(AbstractObject::class, 1));
        self::assertTrue($collector->hasRecordedAny());

        $collector->reset();
        self::assertFalse($collector->hasRecordedAny());
        self::assertSame([], $collector->tagsForGranularity(Granularity::SINGLE));
        self::assertSame([], $collector->tagsForGranularity(Granularity::LIST));
    }

    public function testRecordObjectSkipsElementWithNullId(): void
    {
        $collector = new DependencyCollector();
        $mock = $this->createMock(AbstractObject::class);
        $mock->method('getId')->willReturn(null);

        $collector->recordObject($mock);
        self::assertFalse($collector->hasRecordedAny());
    }
}
