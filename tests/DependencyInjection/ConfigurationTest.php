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

namespace Pimcore\Bundle\DataHubBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('PIMCORE_PROJECT_ROOT')) {
            define('PIMCORE_PROJECT_ROOT', sys_get_temp_dir());
        }
        if (!defined('PIMCORE_CONFIGURATION_DIRECTORY')) {
            define('PIMCORE_CONFIGURATION_DIRECTORY', sys_get_temp_dir() . '/pimcore-configuration');
        }
    }

    /**
     * @param array<string, mixed> $graphql
     *
     * @return array<string, mixed>
     */
    private function process(array $graphql): array
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(
            new Configuration(),
            [['graphql' => $graphql]]
        );

        return $config['graphql'];
    }

    public function testEmptyConfigProducesNoOperationsAndEmptyInProgressQueries(): void
    {
        $graphql = $this->process([]);
        self::assertSame([], $graphql['in_progress_queries']);
        self::assertSame([], $graphql['operations']);
    }

    public function testOperationsEntryWithValidTierAndGranularityPassesThrough(): void
    {
        $graphql = $this->process([
            'operations' => [
                'testOpListSwr' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ],
        ]);
        self::assertArrayHasKey('testOpListSwr', $graphql['operations']);
        self::assertSame('swr_only', $graphql['operations']['testOpListSwr']['tier']);
        self::assertSame('list', $graphql['operations']['testOpListSwr']['granularity']);
        self::assertNull($graphql['operations']['testOpListSwr']['ttl_override']);
        self::assertSame(1, $graphql['operations']['testOpListSwr']['priority_weight']);
    }

    public function testOperationsEntryMissingTierRejectsAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process([
            'operations' => [
                'testOpListSwr' => ['granularity' => 'list'],
            ],
        ]);
    }

    public function testOperationsEntryMissingGranularityRejectsAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process([
            'operations' => [
                'testOpListSwr' => ['tier' => 'swr_only'],
            ],
        ]);
    }

    public function testOperationsEntryWithUnknownAttributeKeyRejectsAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process([
            'operations' => [
                'testOpListSwr' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'typo_key' => 1,
                ],
            ],
        ]);
    }

    public function testOperationsEntryWithInvalidTierEnumValueRejectsAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process([
            'operations' => [
                'testOpListSwr' => ['tier' => 'unknown', 'granularity' => 'list'],
            ],
        ]);
    }

    public function testOperationsEntryWithInvalidGranularityEnumValueRejectsAtBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process([
            'operations' => [
                'testOpListSwr' => ['tier' => 'swr_only', 'granularity' => 'bogus'],
            ],
        ]);
    }

    public function testInProgressQueriesNonEmptyFoldsIntoOperationsAsHerdGuardedList(): void
    {
        $graphql = $this->process([
            'in_progress_queries' => ['testOpListGuarded'],
        ]);
        self::assertSame(['testOpListGuarded'], $graphql['in_progress_queries']);
        self::assertArrayHasKey('testOpListGuarded', $graphql['operations']);
        self::assertSame('herd_guarded', $graphql['operations']['testOpListGuarded']['tier']);
        self::assertSame('list', $graphql['operations']['testOpListGuarded']['granularity']);
        self::assertNull($graphql['operations']['testOpListGuarded']['ttl_override']);
        self::assertNull($graphql['operations']['testOpListGuarded']['enqueue_dedup_ttl_override']);
        self::assertSame(1, $graphql['operations']['testOpListGuarded']['priority_weight']);
    }

    public function testInProgressQueriesMemberAlsoInOperationsExplicitEntryWins(): void
    {
        $graphql = $this->process([
            'in_progress_queries' => ['testOpListGuarded'],
            'operations' => [
                'testOpListGuarded' => [
                    'tier' => 'swr_only',
                    'granularity' => 'single',
                    'ttl_override' => 600,
                ],
            ],
        ]);
        self::assertSame('swr_only', $graphql['operations']['testOpListGuarded']['tier']);
        self::assertSame('single', $graphql['operations']['testOpListGuarded']['granularity']);
        self::assertSame(600, $graphql['operations']['testOpListGuarded']['ttl_override']);
        self::assertSame(['testOpListGuarded'], $graphql['_in_progress_operations_conflicts']);
    }

    public function testInProgressQueriesMemberAbsentFromOperationsProducesNoConflictSentinel(): void
    {
        $graphql = $this->process([
            'in_progress_queries' => ['testOpListGuarded'],
        ]);
        self::assertArrayNotHasKey('_in_progress_operations_conflicts', $graphql);
    }

    public function testPersistentOutputCachePayloadTtlByGranularityDefaults(): void
    {
        $graphql = $this->process([]);
        self::assertSame(
            ['single' => 86400, 'list' => 1209600],
            $graphql['persistent_output_cache_payload_ttl_by_granularity']
        );
    }

    public function testPersistentOutputCachePayloadTtlByGranularityOverridesAccepted(): void
    {
        $graphql = $this->process([
            'persistent_output_cache_payload_ttl_by_granularity' => [
                'single' => 3600,
                'list' => 7200,
            ],
        ]);
        self::assertSame(
            ['single' => 3600, 'list' => 7200],
            $graphql['persistent_output_cache_payload_ttl_by_granularity']
        );
    }

    public function testTtlOverrideNegativeIntegerRejects(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process([
            'operations' => [
                'testOpListSwr' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'ttl_override' => -5,
                ],
            ],
        ]);
    }

    public function testPriorityWeightDefaultsToOne(): void
    {
        $graphql = $this->process([
            'operations' => [
                'testOpListSwr' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ],
        ]);
        self::assertSame(1, $graphql['operations']['testOpListSwr']['priority_weight']);
    }

    public function testCamelCaseOperationNamesPreservedNotNormalized(): void
    {
        $graphql = $this->process([
            'operations' => [
                'testOpSingleSwr' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ],
        ]);
        self::assertArrayHasKey('testOpSingleSwr', $graphql['operations']);
        self::assertArrayNotHasKey('test_op_single_swr', $graphql['operations']);
    }
}
