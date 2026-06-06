<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

/**
 * Read-only classifier for GraphQL operation tiering.
 *
 * The `pimcore_data_hub.graphql.operations` config tree is the single source
 * of truth. Members of the legacy `in_progress_queries` list are folded by
 * the Configuration validator into synthetic `operations` entries
 * (tier: herd_guarded, granularity: list), so this classifier observes the
 * post-fold shape uniformly.
 */
class OperationClassifier
{
    /** @var array<string, Tier> */
    private array $tiers = [];

    /** @var array<string, Granularity> */
    private array $granularities = [];

    /** @var array<string, int|null> */
    private array $ttlOverrides = [];

    /** @var array<string, int|null> */
    private array $enqueueDedupTtlOverrides = [];

    /** @var array<string, int> */
    private array $priorityWeights = [];

    /** @var array<string, int> */
    private array $readPriorityWeights = [];

    /** @var array<string, int|null> */
    private array $invalidationCooldowns = [];

    /** @var array{single: int, list: int} */
    private array $payloadTtlByGranularity;

    public function __construct(ContainerBagInterface $container)
    {
        $cfg = $container->get('pimcore_data_hub');
        $graphql = is_array($cfg) ? ($cfg['graphql'] ?? []) : [];

        $operations = is_array($graphql['operations'] ?? null) ? $graphql['operations'] : [];
        foreach ($operations as $name => $entry) {
            if (!is_string($name) || $name === '' || !is_array($entry)) {
                continue;
            }
            $tier = Tier::tryFrom((string)($entry['tier'] ?? ''));
            $granularity = Granularity::tryFrom((string)($entry['granularity'] ?? ''));
            if ($tier === null || $tier === Tier::NEITHER || $granularity === null) {
                continue;
            }
            $this->tiers[$name] = $tier;
            $this->granularities[$name] = $granularity;
            $this->ttlOverrides[$name] = isset($entry['ttl_override']) ? (int)$entry['ttl_override'] : null;
            $this->enqueueDedupTtlOverrides[$name] = isset($entry['enqueue_dedup_ttl_override'])
                ? (int)$entry['enqueue_dedup_ttl_override']
                : null;
            $this->priorityWeights[$name] = isset($entry['priority_weight']) ? (int)$entry['priority_weight'] : 1;
            $this->readPriorityWeights[$name] = isset($entry['read_priority_weight']) ? (int)$entry['read_priority_weight'] : 1;
            $this->invalidationCooldowns[$name] = isset($entry['invalidation_cooldown_ttl'])
                ? (int)$entry['invalidation_cooldown_ttl']
                : null;
        }

        $byGranularity = is_array($graphql['persistent_output_cache_payload_ttl_by_granularity'] ?? null)
            ? $graphql['persistent_output_cache_payload_ttl_by_granularity']
            : [];
        $this->payloadTtlByGranularity = [
            'single' => isset($byGranularity['single']) ? (int)$byGranularity['single'] : 86400,
            'list' => isset($byGranularity['list']) ? (int)$byGranularity['list'] : 1209600,
        ];
    }

    public function hasOperation(string $operationName): bool
    {
        return isset($this->tiers[$operationName]);
    }

    public function hasAnyOperations(): bool
    {
        return $this->priorityWeights !== [];
    }

    public function getTier(string $operationName): Tier
    {
        return $this->tiers[$operationName] ?? Tier::NEITHER;
    }

    public function getGranularity(string $operationName): ?Granularity
    {
        return $this->granularities[$operationName] ?? null;
    }

    /**
     * Returns null when the operation is not classified — callers in the SWR
     * layer must not invoke this for unclassified operations. Returning the
     * legacy global `persistent_output_cache_payload_ttl` here would silently
     * extend the SWR contract to operations that have not opted in.
     */
    public function getTtl(string $operationName): ?int
    {
        if (!isset($this->tiers[$operationName])) {
            return null;
        }
        $override = $this->ttlOverrides[$operationName] ?? null;
        if ($override !== null) {
            return $override;
        }
        $granularity = $this->granularities[$operationName];

        return $this->payloadTtlByGranularity[$granularity->value];
    }

    public function getEnqueueDedupeTtl(string $operationName): ?int
    {
        if (!isset($this->tiers[$operationName])) {
            return null;
        }

        return $this->enqueueDedupTtlOverrides[$operationName] ?? null;
    }

    public function getPriorityWeight(string $operationName): ?int
    {
        if (!isset($this->tiers[$operationName])) {
            return null;
        }

        return $this->priorityWeights[$operationName] ?? 1;
    }

    public function getReadPriorityWeight(string $operationName): ?int
    {
        if (!isset($this->tiers[$operationName])) {
            return null;
        }

        return $this->readPriorityWeights[$operationName] ?? 1;
    }

    public function bandWeightFor(string $operationName, bool $readTriggered): ?int
    {
        return $readTriggered
            ? $this->getReadPriorityWeight($operationName)
            : $this->getPriorityWeight($operationName);
    }

    /**
     * Returns the per-operation invalidation-cooldown window in seconds, or
     * null when the operation has no cooldown configured (or is unclassified).
     * A non-null result opts the operation into the trailing-edge throttle on
     * the invalidation path; null preserves the immediate per-edit refresh.
     */
    public function getInvalidationCooldown(string $operationName): ?int
    {
        if (!isset($this->tiers[$operationName])) {
            return null;
        }

        return $this->invalidationCooldowns[$operationName] ?? null;
    }
}
