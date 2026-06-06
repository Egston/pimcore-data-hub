<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;

/**
 * Host-runnable sentinel for the bundle Redis-prefix enumeration. Pins the
 * shape of {@see BundleRedisPrefixes::ALL} so a future flush-helper change
 * cannot silently drop a prefix family; the L3 suite relies on this list to
 * bound its inter-test cleanup, and a missed prefix would leak state across
 * tests with no symptom inside the test runner.
 *
 * Kernel-free: reads the constant array on the value class so no Pimcore
 * bootstrap is required to run this sentinel under the Unit suite.
 */
final class KernelTestCaseSentinelTest extends TestCase
{
    public function testBundlePrefixListCoversTheCanonicalFamilies(): void
    {
        $prefixes = BundleRedisPrefixes::ALL;

        self::assertContains('datahub_inprogress_', $prefixes, 'underscore-separated herd-guard marker prefix must be present');
        self::assertContains('datahub_inprogress:', $prefixes, 'colon-separated Symfony Lock resource prefix must be present');
        self::assertContains('datahub_refresh_priority_', $prefixes, 'priority transport ZSET/HASH prefix must be present');
        self::assertContains('datahub_persistent_refresh_lock_', $prefixes, 'SWR refresh lock prefix must be present');
        self::assertContains(PersistentOutputCacheService::PAYLOAD_KEY_PREFIX, $prefixes, 'persistent payload key prefix must be present');
        self::assertContains(PersistentOutputCacheService::META_KEY_PREFIX, $prefixes, 'persistent meta key prefix must be present');
        self::assertContains('taginx_', $prefixes, 'reverse-index prefix must be present');
        self::assertContains(PersistentOutputCacheService::ENQUEUE_DEDUPE_PREFIX, $prefixes, 'enqueue-dedup prefix must be present');
        self::assertContains(PersistentOutputCacheService::TAG_OBJECT_PREFIX, $prefixes, 'per-object cache-tag prefix must be present');
        self::assertContains(PersistentOutputCacheService::TAG_CLASS_PREFIX, $prefixes, 'per-class cache-tag prefix must be present');
    }

    public function testBundlePrefixListHasNoDuplicates(): void
    {
        $prefixes = BundleRedisPrefixes::ALL;
        self::assertSame(
            count($prefixes),
            count(array_unique($prefixes)),
            'BundleRedisPrefixes::ALL must contain no duplicate entries'
        );
    }

    public function testEveryPrefixEndsInTheBoundaryCharacter(): void
    {
        foreach (BundleRedisPrefixes::ALL as $prefix) {
            self::assertNotEmpty($prefix);
            $last = $prefix[strlen($prefix) - 1];
            self::assertTrue(
                $last === '_' || $last === ':',
                sprintf('prefix %s must end in _ or : so scan(<prefix>*) bounds correctly', $prefix)
            );
        }
    }
}
