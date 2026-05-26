<?php

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

namespace Pimcore\Bundle\DataHubBundle\DependencyInjection;

use Pimcore\Bundle\CoreBundle\DependencyInjection\ConfigurationHelper;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * Maps each deprecated in_progress_* scalar to its canonical herd_guard_* replacement.
     * Consumed by the validator closure (BC fold) and by boot() (deprecation warning).
     *
     * @var array<string, string>
     */
    public const HERD_GUARD_ALIASES = [
        'in_progress_protection_enabled' => 'herd_guard_enabled',
        'in_progress_ttl'                => 'herd_guard_ttl',
        'in_progress_refresh_interval'   => 'herd_guard_refresh_interval',
        'in_progress_http_status'        => 'herd_guard_http_status',
        'in_progress_retry_after'        => 'herd_guard_retry_after',
        'in_progress_key_strategy'       => 'herd_guard_key_strategy',
    ];

    /**
     * Generates the configuration tree builder.
     *
     * @return \Symfony\Component\Config\Definition\Builder\TreeBuilder The tree builder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('pimcore_data_hub');
        $rootNode = $treeBuilder->getRootNode();
        $rootNode
            ->children()
                ->arrayNode('graphql')
                    ->children()
                        ->scalarNode('not_allowed_policy')->info('throw exception = 1, return null = 2')->defaultValue(2)->end()
                        ->booleanNode('output_cache_enabled')->info('enables output cache for graphql responses. It is disabled by default')->defaultValue(false)->end()
                        ->integerNode('output_cache_lifetime')->info('output cache in seconds. Default is 30 seconds')->defaultValue(30)->end()
                        ->booleanNode('persistent_output_cache_enabled')->info('enables persistent output cache for graphql responses (separate from Pimcore \"output\" tag).')->defaultValue(false)->end()
                        ->integerNode('persistent_output_cache_lifetime')->info('persistent output cache TTL in seconds. Defaults to output_cache_lifetime when not set')->defaultNull()->end()
                        ->integerNode('persistent_output_cache_payload_ttl')->info('TTL in seconds for the large payload entry; use a longer TTL to avoid frequent rewrites')->defaultValue(86400)->end()
                        ->arrayNode('persistent_output_cache_payload_ttl_by_granularity')
                            ->info('per-granularity payload TTL defaults; layered on top of the legacy persistent_output_cache_payload_ttl scalar and resolved per classified operation via OperationClassifier')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('single')->defaultValue(86400)->end()
                                ->integerNode('list')->defaultValue(1209600)->end()
                            ->end()
                        ->end()
                        ->booleanNode('persistent_output_cache_guard_only')->info('[removed] key accepted for BC but has no effect; the single-surface classifier gate is now always active')->defaultNull()->end()
                        ->booleanNode('persistent_disable_output_cache_for_guarded')->info('when true, bypass the standard output cache layer (both read and write) for requests where the persistent (SWR) cache applies. Recommended when the persistent layer is enabled: eliminates duplicate storage and double-write traffic in Redis, since the same response would otherwise be stored in two cache layers with different keys. Has no effect while persistent_output_cache_enabled is false.')->defaultValue(false)->end()
                        ->booleanNode('herd_guard_enabled')->info('reject duplicate parallel requests for HERD_GUARDED operations while the first one is in progress')->defaultNull()->end()
                        ->booleanNode('in_progress_protection_enabled')->info('[deprecated] use herd_guard_enabled instead')->defaultNull()->end()
                        ->integerNode('herd_guard_ttl')->info('TTL in seconds for the herd-guard marker/lock — bounds the leak window when a request dies without releasing (SIGKILL, OOM). With refresh enabled this can be set much smaller than the slowest legitimate request.')->defaultNull()->end()
                        ->integerNode('in_progress_ttl')->info('[deprecated] use herd_guard_ttl instead')->defaultNull()->end()
                        ->integerNode('herd_guard_refresh_interval')
                            ->info('Seconds between background SIGALRM refresh ticks for the herd-guard lock/marker. Must be < herd_guard_ttl. 0 = auto (floor(herd_guard_ttl / 2)). Requires the pcntl extension; silently disabled otherwise.')
                            ->defaultNull()
                        ->end()
                        ->integerNode('in_progress_refresh_interval')->info('[deprecated] use herd_guard_refresh_interval instead')->defaultNull()->end()
                        ->integerNode('herd_guard_http_status')
                            ->info('HTTP status code returned when a protected query is already running (e.g. 503)')
                            ->defaultNull()
                        ->end()
                        ->integerNode('in_progress_http_status')->info('[deprecated] use herd_guard_http_status instead')->defaultNull()->end()
                        ->integerNode('herd_guard_retry_after')
                            ->info('Optional Retry-After header value in seconds for herd-guard responses')
                            ->defaultNull()
                        ->end()
                        ->integerNode('in_progress_retry_after')->info('[deprecated] use herd_guard_retry_after instead')->defaultNull()->end()
                        ->scalarNode('herd_guard_key_strategy')
                            ->info("how to build the herd-guard key: 'request' (query+variables) or 'operation' (operationName only)")
                            ->defaultNull()
                        ->end()
                        ->scalarNode('in_progress_key_strategy')->info('[deprecated] use herd_guard_key_strategy instead')->defaultNull()->end()
                        ->arrayNode('in_progress_queries')
                            ->info('list of GraphQL operation names to protect (thundering herd protection); permanent BC alias — each member folds into operations as { tier: herd_guarded, granularity: list }')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('operations')
                            ->info('per-operation tier classification driving the two-tier SWR layer; entries are closed-shape and explicit tier+granularity is required')
                            ->useAttributeAsKey('operationName')
                            ->normalizeKeys(false)
                            ->arrayPrototype()
                                ->children()
                                    ->enumNode('tier')
                                        ->values(['herd_guarded', 'swr_only'])
                                        ->isRequired()
                                        ->cannotBeEmpty()
                                    ->end()
                                    ->enumNode('granularity')
                                        ->values(['single', 'list'])
                                        ->isRequired()
                                        ->cannotBeEmpty()
                                    ->end()
                                    ->integerNode('ttl_override')->min(1)->defaultNull()->end()
                                    ->integerNode('enqueue_dedup_ttl_override')->min(1)->defaultNull()->end()
                                    ->integerNode('priority_weight')->defaultValue(1)->end()
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                        ->booleanNode('allow_introspection')->info('enables introspection for graphql. It is enabled by default')->defaultValue(true)->end()
                        ->booleanNode('persistent_refresh_lock_enabled')->info('enable a lightweight refresh lock for background refresh when operation is not guarded by herd protection')->defaultValue(true)->end()
                        ->integerNode('persistent_refresh_lock_ttl')->info('TTL in seconds for the background refresh lock marker')->defaultValue(120)->end()
                        ->booleanNode('persistent_refresh_queue_enabled')->info('enqueue background refresh jobs to Symfony Messenger instead of running them in kernel.terminate')->defaultValue(false)->end()
                        ->integerNode('persistent_refresh_operation_lock_ttl')->info('TTL (seconds) for per-operation lock in the worker when herd guard uses operation-name; set slightly above p99 refresh time')->defaultValue(120)->end()
                        ->integerNode('persistent_enqueue_dedupe_ttl')->info('TTL (seconds) for enqueue dedupe marker to avoid flooding the queue with identical refresh jobs')->defaultValue(60)->end()
                        ->enumNode('persistent_refresh_priority_strategy')
                            ->info('refresh queue ordering strategy. `oldest_refreshed_at_first` threads the per-entry refreshedAt into the message and the priority transport pops the longest-stale first; `oldest_refreshed_at_first_with_weight_bands` subtracts `priority_weight * persistent_refresh_priority_weight_band_seconds` from the score so higher-weight operations pop earlier among same-aged peers; `disabled` threads no score (insertion-time-ordered, FIFO-equivalent)')
                            ->values(['oldest_refreshed_at_first', 'oldest_refreshed_at_first_with_weight_bands', 'disabled'])
                            ->defaultValue('oldest_refreshed_at_first')
                        ->end()
                        ->integerNode('persistent_refresh_priority_weight_band_seconds')
                            ->info('band width in seconds applied per unit of `priority_weight` when `persistent_refresh_priority_strategy` is `oldest_refreshed_at_first_with_weight_bands`. 0 disables banding (strategy stays selected, but score reduces to `oldest_refreshed_at_first` shape) — useful for ops bisection.')
                            ->min(0)
                            ->defaultValue(60)
                        ->end()
                        ->integerNode('persistent_refresh_priority_visibility_timeout')
                            ->info('seconds after which an in-flight refresh message is considered stuck and re-queued by the priority transport reaper')
                            ->min(1)
                            ->defaultValue(600)
                        ->end()
                        ->integerNode('persistent_refresh_priority_requeue_score_bump')
                            ->info('seconds added to a message score when re-sent while still in the priority transport in-flight set; demotes recently-contended messages so freshly-stale ones drain first')
                            ->min(0)
                            ->defaultValue(5)
                        ->end()
                        ->integerNode('swr_cold_miss_lock_wait_ms')
                            ->info('milliseconds a losing SWR_ONLY cold-miss request waits for the winner to publish a cache entry before falling through to its own inline resolver. 0 disables bounded-wait (immediate defensive fallback).')
                            ->min(0)
                            ->defaultValue(5000)
                        ->end()
                        ->integerNode('swr_cold_miss_lock_ttl')
                            ->info('TTL in seconds for the SWR_ONLY cold-miss lock itself. Distinct from swr_cold_miss_lock_wait_ms — the wait knob bounds the loser, the TTL bounds the winner.')
                            ->min(1)
                            ->defaultValue(30)
                        ->end()
                        ->booleanNode('allow_sqlObjectCondition')
                            ->setDeprecated(
                                'pimcore/data-hub',
                                '2.0.0'
                            )
                            ->info('enables SQL Condition for graphql. It is enabled by default')
                            ->defaultValue(true)
                        ->end()
                    ->end()
                    ->validate()
                        ->always(fn (array $graphql): array => $this->normalizeGraphqlNode($graphql))
                    ->end()
                ->end()
            ->end()
        ->end();

        $this->addConfigurationsNode($rootNode);
        $this->addSupportedTypes($rootNode);

        /** @var ArrayNodeDefinition $rootNode */
        ConfigurationHelper::addConfigLocationWithWriteTargetNodes(
            $rootNode,
            ['data_hub' => PIMCORE_CONFIGURATION_DIRECTORY . '/data_hub']
        );

        return $treeBuilder;
    }

    /**
     * @param array<string, mixed> $graphql
     *
     * @return array<string, mixed>
     */
    private function normalizeGraphqlNode(array $graphql): array
    {
        // Accept but discard; boot() emits the deprecation warning when this key is present.
        if (array_key_exists('persistent_output_cache_guard_only', $graphql)) {
            $graphql['_persistent_output_cache_guard_only_set'] = true;
            unset($graphql['persistent_output_cache_guard_only']);
        }

        // BC alias fold: in_progress_* → herd_guard_* (canonical wins when both non-null).
        // When both are non-null the canonical wins silently; stash conflicts so boot() can warn.
        // Empty-string is treated as absent for fold purposes (unresolved envvar pattern).
        $aliasConflicts = [];
        foreach (self::HERD_GUARD_ALIASES as $old => $new) {
            $oldVal = $graphql[$old] ?? null;
            $newVal = $graphql[$new] ?? null;
            $oldEffective = ($oldVal === null || $oldVal === '') ? null : $oldVal;
            $newEffective = ($newVal === null || $newVal === '') ? null : $newVal;
            if ($oldEffective !== null && $newEffective === null) {
                $graphql[$new] = $oldEffective;
            } elseif ($oldEffective !== null && $newEffective !== null) {
                $aliasConflicts[] = sprintf('%s=%s overridden by %s=%s', $old, json_encode($oldEffective), $new, json_encode($newEffective));
            }
        }
        if ($aliasConflicts !== []) {
            $graphql['_herd_guard_alias_conflicts'] = $aliasConflicts;
        }

        $inProgress = $graphql['in_progress_queries'] ?? [];
        $operations = $graphql['operations'] ?? [];
        if (!is_array($inProgress) || !is_array($operations)) {
            return $graphql;
        }
        $conflicts = [];
        foreach ($inProgress as $opName) {
            if (!is_string($opName) || $opName === '') {
                continue;
            }
            if (isset($operations[$opName])) {
                $conflicts[] = $opName;

                continue;
            }
            $operations[$opName] = [
                'tier' => 'herd_guarded',
                'granularity' => 'list',
                'ttl_override' => null,
                'enqueue_dedup_ttl_override' => null,
                'priority_weight' => 1,
            ];
        }
        $graphql['operations'] = $operations;
        if ($conflicts !== []) {
            $graphql['_in_progress_operations_conflicts'] = $conflicts;
        }

        return $graphql;
    }

    private function addConfigurationsNode(ArrayNodeDefinition | NodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('configurations')
                    ->normalizeKeys(false)
                    ->variablePrototype()->end()
                ->end()
            ->end();
    }

    private function addSupportedTypes(ArrayNodeDefinition | NodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('supported_types')
                    ->variablePrototype()->end()
                ->end()
            ->end();
    }
}
