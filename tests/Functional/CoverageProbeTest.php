<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Pimcore\Bundle\DataHubBundle\Service\DependencyCollector;
use Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures\KernelTestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Listing as DataObjectListing;
use Pimcore\Model\Document;

/**
 * Kernel-booted POST_LOAD coverage probe.
 *
 * Walks every Pimcore element loader path the DataHub resolver layer touches
 * — DataObject listing, Asset listing, Document listing, and a raw-SQL
 * hydrator path — and asserts that {@see DependencyCollector::hasRecordedAny()}
 * reports the loaded elements. The probe is the load-bearing pin for the
 * POST_LOAD coverage invariant: if Pimcore upstream changes how a loader
 * surfaces its loaded element, the DependencyCollector silently drops it and
 * downstream cache tags become incomplete.
 */
final class CoverageProbeTest extends KernelTestCase
{
    public function testDataObjectListingPathRecordsLoadedObjects(): void
    {
        $collector = $this->collector();
        $collector->reset();

        $listing = new DataObjectListing();
        $listing->setClassId($this->dataObjectClassId('TestSwrGuardedItem'));
        $listing->setLimit(3);
        $loaded = $listing->load();

        self::assertNotSame([], $loaded, 'fixture-loaded TestSwrGuardedItem objects must be visible to the listing');
        self::assertTrue(
            $collector->hasRecordedAny(),
            'DataObject\\Listing must fire POST_LOAD into the DependencyCollector'
        );
    }

    public function testIndividualGetByIdRecordsObject(): void
    {
        $collector = $this->collector();
        $collector->reset();

        self::assertArrayHasKey('TestSwrOnlyItem', $this->fixtureIds(), 'TestSwrOnlyItem fixtures must be loaded');
        $ids = $this->fixtureIds()['TestSwrOnlyItem'];
        $object = Concrete::getById($ids[0]);
        self::assertNotNull($object);
        self::assertTrue($collector->hasRecordedAny(), 'AbstractObject::getById must fire POST_LOAD');
    }

    public function testAssetListingPathRecordsLoadedAssets(): void
    {
        $collector = $this->collector();
        $collector->reset();

        $listing = new Asset\Listing();
        $listing->setLimit(1);
        $assets = $listing->load();
        if ($assets === []) {
            self::fail('no Asset present in the L3 test namespace — extend the fixture loader to seed a minimal Asset so this loader path is covered');
        }

        self::assertTrue($collector->hasRecordedAny(), 'Asset\\Listing must fire POST_LOAD into the DependencyCollector');
    }

    public function testDocumentListingPathRecordsLoadedDocuments(): void
    {
        $collector = $this->collector();
        $collector->reset();

        $listing = new Document\Listing();
        $listing->setLimit(1);
        $documents = $listing->load();
        if ($documents === []) {
            self::fail('no Document present in the L3 test namespace — extend the fixture loader to seed a minimal Document so this loader path is covered');
        }

        self::assertTrue(
            $collector->hasRecordedAny(),
            'Document\\Listing must fire POST_LOAD into the DependencyCollector'
        );
    }

    public function testRawSqlHydratorPathRecordsLoadedObjects(): void
    {
        $collector = $this->collector();
        $collector->reset();

        self::assertArrayHasKey('TestSwrGuardedItem', $this->fixtureIds(), 'TestSwrGuardedItem fixtures must be loaded');
        $ids = $this->fixtureIds()['TestSwrGuardedItem'];

        /** @var Connection $db */
        $db = \Pimcore::getContainer()->get('database_connection');
        $rows = $db->fetchAllAssociative('SELECT id FROM objects WHERE id IN (?) LIMIT 3', [$ids], [Connection::PARAM_INT_ARRAY]);
        self::assertNotSame([], $rows);

        foreach ($rows as $row) {
            $obj = AbstractObject::getById((int)$row['id']);
            self::assertNotNull($obj);
        }

        self::assertTrue(
            $collector->hasRecordedAny(),
            'raw-SQL id list re-hydrated via AbstractObject::getById must fire POST_LOAD'
        );
    }

    private function collector(): DependencyCollector
    {
        $collector = \Pimcore::getContainer()->get(DependencyCollector::class);
        self::assertInstanceOf(DependencyCollector::class, $collector);

        return $collector;
    }

    private function dataObjectClassId(string $className): string
    {
        $class = \Pimcore\Model\DataObject\ClassDefinition::getByName($className);
        self::assertNotNull($class, sprintf('class %s must be installed before the probe runs', $className));

        return (string)$class->getId();
    }
}
