<?php

declare(strict_types=1);

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
        self::assertSame(1, $graphql['operations']['testOpListGuarded']['read_priority_weight']);
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

    public function testPersistentRefreshPriorityStrategyDefaultsToOldestFirst(): void
    {
        $graphql = $this->process([]);
        self::assertSame('oldest_refreshed_at_first', $graphql['persistent_refresh_priority_strategy']);
        self::assertSame(600, $graphql['persistent_refresh_priority_visibility_timeout']);
        self::assertSame(5, $graphql['persistent_refresh_priority_requeue_score_bump']);
        self::assertSame(60, $graphql['persistent_refresh_priority_weight_band_seconds']);
    }

    public function testPersistentRefreshPriorityStrategyDisabledAccepted(): void
    {
        $graphql = $this->process([
            'persistent_refresh_priority_strategy' => 'disabled',
        ]);
        self::assertSame('disabled', $graphql['persistent_refresh_priority_strategy']);
    }

    public function testPersistentRefreshPriorityStrategyWithWeightBandsAccepted(): void
    {
        $graphql = $this->process([
            'persistent_refresh_priority_strategy' => 'oldest_refreshed_at_first_with_weight_bands',
            'persistent_refresh_priority_weight_band_seconds' => 120,
        ]);
        self::assertSame('oldest_refreshed_at_first_with_weight_bands', $graphql['persistent_refresh_priority_strategy']);
        self::assertSame(120, $graphql['persistent_refresh_priority_weight_band_seconds']);
    }

    public function testPersistentRefreshPriorityWeightBandSecondsZeroAccepted(): void
    {
        $graphql = $this->process([
            'persistent_refresh_priority_weight_band_seconds' => 0,
        ]);
        self::assertSame(0, $graphql['persistent_refresh_priority_weight_band_seconds']);
    }

    public function testPersistentRefreshPriorityWeightBandSecondsNegativeRejects(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process([
            'persistent_refresh_priority_weight_band_seconds' => -1,
        ]);
    }

    public function testPersistentRefreshPriorityStrategyUnknownRejects(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process([
            'persistent_refresh_priority_strategy' => 'bogus_strategy',
        ]);
    }

    public function testHerdGuardEnabledCanonicalKey(): void
    {
        $graphql = $this->process(['herd_guard_enabled' => true]);
        self::assertTrue($graphql['herd_guard_enabled']);
    }

    public function testInProgressProtectionEnabledFoldsToHerdGuardEnabled(): void
    {
        $graphql = $this->process(['in_progress_protection_enabled' => true]);
        self::assertTrue($graphql['herd_guard_enabled']);
    }

    public function testInProgressTtlFoldsToHerdGuardTtl(): void
    {
        $graphql = $this->process(['in_progress_ttl' => 45]);
        self::assertSame(45, $graphql['herd_guard_ttl']);
    }

    public function testInProgressRefreshIntervalFoldsToHerdGuardRefreshInterval(): void
    {
        $graphql = $this->process(['in_progress_refresh_interval' => 10]);
        self::assertSame(10, $graphql['herd_guard_refresh_interval']);
    }

    public function testInProgressHttpStatusFoldsToHerdGuardHttpStatus(): void
    {
        $graphql = $this->process(['in_progress_http_status' => 429]);
        self::assertSame(429, $graphql['herd_guard_http_status']);
    }

    public function testInProgressRetryAfterFoldsToHerdGuardRetryAfter(): void
    {
        $graphql = $this->process(['in_progress_retry_after' => 30]);
        self::assertSame(30, $graphql['herd_guard_retry_after']);
    }

    public function testInProgressKeyStrategyFoldsToHerdGuardKeyStrategy(): void
    {
        $graphql = $this->process(['in_progress_key_strategy' => 'operation']);
        self::assertSame('operation', $graphql['herd_guard_key_strategy']);
    }

    public function testCanonicalHerdGuardKeyWinsOverAliasWhenBothSet(): void
    {
        $graphql = $this->process([
            'herd_guard_enabled' => true,
            'in_progress_protection_enabled' => false,
        ]);
        self::assertTrue($graphql['herd_guard_enabled']);
    }

    public function testCanonicalHerdGuardTtlWinsOverAliasWhenBothSet(): void
    {
        $graphql = $this->process([
            'herd_guard_ttl' => 90,
            'in_progress_ttl' => 30,
        ]);
        self::assertSame(90, $graphql['herd_guard_ttl']);
    }

    public function testCanonicalHerdGuardRefreshIntervalWinsOverAliasWhenBothSet(): void
    {
        $graphql = $this->process([
            'herd_guard_refresh_interval' => 20,
            'in_progress_refresh_interval' => 5,
        ]);
        self::assertSame(20, $graphql['herd_guard_refresh_interval']);
    }

    public function testCanonicalHerdGuardHttpStatusWinsOverAliasWhenBothSet(): void
    {
        $graphql = $this->process([
            'herd_guard_http_status' => 429,
            'in_progress_http_status' => 503,
        ]);
        self::assertSame(429, $graphql['herd_guard_http_status']);
    }

    public function testCanonicalHerdGuardRetryAfterWinsOverAliasWhenBothSet(): void
    {
        $graphql = $this->process([
            'herd_guard_retry_after' => 60,
            'in_progress_retry_after' => 10,
        ]);
        self::assertSame(60, $graphql['herd_guard_retry_after']);
    }

    public function testCanonicalHerdGuardKeyStrategyWinsOverAliasWhenBothSet(): void
    {
        $graphql = $this->process([
            'herd_guard_key_strategy' => 'operation',
            'in_progress_key_strategy' => 'request',
        ]);
        self::assertSame('operation', $graphql['herd_guard_key_strategy']);
    }

    public function testAliasConflictSentinelStoredWhenBothCanonicalAndAliasSet(): void
    {
        $graphql = $this->process([
            'herd_guard_ttl' => 90,
            'in_progress_ttl' => 30,
        ]);
        self::assertCount(1, $graphql['_herd_guard_alias_conflicts']);
        self::assertStringContainsString('herd_guard_ttl', $graphql['_herd_guard_alias_conflicts'][0]);
    }

    public function testAliasConflictSentinelAbsentWhenOnlyCanonicalSet(): void
    {
        $graphql = $this->process(['herd_guard_ttl' => 90]);
        self::assertArrayNotHasKey('_herd_guard_alias_conflicts', $graphql);
    }

    public function testAliasConflictSentinelAbsentWhenOnlyAliasSet(): void
    {
        $graphql = $this->process(['in_progress_ttl' => 30]);
        self::assertArrayNotHasKey('_herd_guard_alias_conflicts', $graphql);
    }

    public function testPersistentOutputCacheGuardOnlyRejectedWithWarning(): void
    {
        // The key is accepted (no InvalidConfigurationException) but stripped by the
        // validator closure; a sentinel is stored so boot() can emit a log warning.
        $graphql = $this->process(['persistent_output_cache_guard_only' => true]);
        self::assertArrayNotHasKey('persistent_output_cache_guard_only', $graphql);
        self::assertTrue($graphql['_persistent_output_cache_guard_only_set'] ?? false);
    }

    public function testNormalizeGraphqlNodeHandlesPartialConfigWithMissingAliasKeys(): void
    {
        // Only the canonical key is set; alias keys default to null via the config tree.
        // The validator must not raise an undefined-index notice on missing alias keys.
        $graphql = $this->process(['herd_guard_ttl' => 120]);
        self::assertSame(120, $graphql['herd_guard_ttl']);
        self::assertArrayNotHasKey('_herd_guard_alias_conflicts', $graphql);
    }

    public function testNormalizeGraphqlNodeHandlesPartialConfigWithMissingCanonicalKeys(): void
    {
        // Only the alias key is set; canonical defaults to null via the config tree.
        $graphql = $this->process(['in_progress_ttl' => 45]);
        self::assertSame(45, $graphql['herd_guard_ttl']);
        self::assertArrayNotHasKey('_herd_guard_alias_conflicts', $graphql);
    }

    public function testEmptyStringCanonicalDoesNotBypassAliasFold(): void
    {
        // An unresolved envvar can produce an empty-string canonical; it must be
        // treated as absent so the alias value folds in instead of being silently
        // overridden by the empty string.
        $graphql = $this->process([
            'herd_guard_key_strategy' => '',
            'in_progress_key_strategy' => 'operation',
        ]);
        self::assertSame('operation', $graphql['herd_guard_key_strategy']);
        self::assertArrayNotHasKey('_herd_guard_alias_conflicts', $graphql);
    }

    public function testReadTriggerOffsetDefaultDominatesWarmBandSpan(): void
    {
        $graphql = $this->process([]);
        $offset = $graphql['persistent_refresh_priority_read_trigger_offset_seconds'];
        $band = $graphql['persistent_refresh_priority_weight_band_seconds'];
        $maxWeight = $graphql['persistent_refresh_priority_max_weight'];

        self::assertSame(86400, $offset);
        self::assertSame(60, $band);
        self::assertGreaterThan($maxWeight * $band, $offset, 'default offset must dominate the max plausible warm-band span');
    }

    public function testMaxWeightDefaultsTo100(): void
    {
        $graphql = $this->process([]);
        self::assertSame(100, $graphql['persistent_refresh_priority_max_weight']);
    }

    public function testReadPriorityWeightDefaultsToOne(): void
    {
        $graphql = $this->process([
            'operations' => [
                'testOpListSwr' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ],
        ]);
        self::assertSame(1, $graphql['operations']['testOpListSwr']['read_priority_weight']);
    }

    public function testReadPriorityWeightAccepted(): void
    {
        $graphql = $this->process([
            'operations' => [
                'testOpListSwr' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'read_priority_weight' => 7,
                ],
            ],
        ]);
        self::assertSame(7, $graphql['operations']['testOpListSwr']['read_priority_weight']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function processRoot(array $config): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), [$config]);
    }

    public function testRequestValidationDefaultsAreNoOp(): void
    {
        $config = $this->processRoot([]);
        self::assertSame('', $config['request_validation']['rules_file']);
        self::assertSame([], $config['request_validation']['enforced_clients']);
    }

    public function testRequestValidationAcceptsRulesFileAndClients(): void
    {
        $config = $this->processRoot([
            'request_validation' => [
                'rules_file' => '/etc/datahub/rules.json',
                'enforced_clients' => ['public-content'],
            ],
        ]);
        self::assertSame('/etc/datahub/rules.json', $config['request_validation']['rules_file']);
        self::assertSame(['public-content'], $config['request_validation']['enforced_clients']);
    }

    public function testRequestValidationUnknownKeyRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->processRoot([
            'request_validation' => [
                'rules_file' => '/etc/datahub/rules.json',
                'typo_key' => true,
            ],
        ]);
    }
}
