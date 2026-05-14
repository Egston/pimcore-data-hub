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
                        ->booleanNode('persistent_output_cache_guard_only')->info('apply persistent cache only to queries listed in in_progress_queries')->defaultValue(true)->end()
                        ->booleanNode('persistent_disable_output_cache_for_guarded')->info('when true, skip the standard output cache layer for requests where the persistent cache applies (recommended to reduce duplicate work)')->defaultValue(false)->end()
                        ->booleanNode('in_progress_protection_enabled')->info('reject duplicate parallel requests for selected queries while the first one is in progress')->defaultValue(false)->end()
                        ->arrayNode('in_progress_queries')
                            ->info('list of GraphQL operation names to protect (thundering herd protection)')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->integerNode('in_progress_ttl')->info('TTL in seconds for the in-progress marker/lock — bounds the leak window when a request dies without releasing (SIGKILL, OOM). With refresh enabled this can be set much smaller than the slowest legitimate request.')->defaultValue(60)->end()
                        ->integerNode('in_progress_refresh_interval')
                            ->info('Seconds between background SIGALRM refresh ticks for the in-progress lock/marker. Must be < in_progress_ttl. 0 = auto (floor(in_progress_ttl / 2)). Requires the pcntl extension; silently disabled otherwise.')
                            ->defaultValue(0)
                        ->end()
                        ->integerNode('in_progress_http_status')
                            ->info('HTTP status code returned when a protected query is already running (e.g. 503)')
                            ->defaultValue(503)
                        ->end()
                        ->integerNode('in_progress_retry_after')
                            ->info('Optional Retry-After header value in seconds for in-progress responses')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('in_progress_key_strategy')
                            ->info("how to build the in-progress key: 'request' (query+variables) or 'operation' (operationName only)")
                            ->defaultValue('request')
                        ->end()
                        ->booleanNode('allow_introspection')->info('enables introspection for graphql. It is enabled by default')->defaultValue(true)->end()
                        ->booleanNode('persistent_refresh_lock_enabled')->info('enable a lightweight refresh lock for background refresh when operation is not guarded by herd protection')->defaultValue(true)->end()
                        ->integerNode('persistent_refresh_lock_ttl')->info('TTL in seconds for the background refresh lock marker')->defaultValue(120)->end()
                        ->booleanNode('persistent_refresh_queue_enabled')->info('enqueue background refresh jobs to Symfony Messenger instead of running them in kernel.terminate')->defaultValue(false)->end()
                        ->integerNode('persistent_refresh_operation_lock_ttl')->info('TTL (seconds) for per-operation lock in the worker when herd guard uses operation-name; set slightly above p99 refresh time')->defaultValue(120)->end()
                        ->integerNode('persistent_enqueue_dedupe_ttl')->info('TTL (seconds) for enqueue dedupe marker to avoid flooding the queue with identical refresh jobs')->defaultValue(60)->end()
                        ->booleanNode('allow_sqlObjectCondition')
                            ->setDeprecated(
                                'pimcore/data-hub',
                                '2.0.0'
                            )
                            ->info('enables SQL Condition for graphql. It is enabled by default')
                            ->defaultValue(true)
                        ->end()
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
