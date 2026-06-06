<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Messenger;

use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Transport factory for the priority-ordered Redis refresh queue.
 *
 * Strictly matches the DSN scheme `datahub-priority-redis://` and only that
 * scheme. `redis://`, `doctrine://`, etc. all return false from `supports()`
 * so the framework's standard transport factories own them.
 *
 * Operator-tunable knobs (`visibility_timeout_seconds`,
 * `requeue_score_bump_seconds`) are read from
 * `pimcore_data_hub.graphql.persistent_refresh_priority_*` rather than from
 * the DSN itself, keeping the messenger.yaml options block focused on
 * key-space configuration (`zset_key`, `messages_key`, `inflight_key`).
 */
class PriorityRedisTransportFactory implements TransportFactoryInterface
{
    public const DSN_SCHEME = 'datahub-priority-redis://';

    public function __construct(private ContainerBagInterface $container)
    {
    }

    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        if (!$this->supports($dsn, $options)) {
            throw new \InvalidArgumentException(sprintf(
                'datahub.priority_transport: factory cannot create a transport for DSN scheme "%s"; expected "%s"',
                $dsn,
                self::DSN_SCHEME
            ));
        }

        $parsed = parse_url($dsn);
        if (!is_array($parsed) || !isset($parsed['host']) || $parsed['host'] === '') {
            throw new \InvalidArgumentException('datahub.priority_transport: DSN missing host: ' . $dsn);
        }

        $host = (string)$parsed['host'];
        $port = isset($parsed['port']) ? (int)$parsed['port'] : 6379;
        $db = 0;
        if (isset($parsed['path'])) {
            $trimmed = ltrim((string)$parsed['path'], '/');
            if ($trimmed !== '' && ctype_digit($trimmed)) {
                $db = (int)$trimmed;
            }
        }

        $auth = null;
        if (isset($parsed['pass']) && $parsed['pass'] !== '') {
            $auth = (string)$parsed['pass'];
        } elseif (isset($parsed['user']) && $parsed['user'] !== '') {
            $auth = (string)$parsed['user'];
        }
        if ($auth === null) {
            // Pimcore-native transports get the password through REDIS_DSN's
            // url-encoded userinfo segment; this transport assembles its own
            // DSN, so consult REDIS_PASSWORD directly when no userinfo was
            // supplied. Encoding REDIS_PASSWORD into the DSN at YAML level
            // would require a urlencode env processor Symfony doesn't ship.
            $envPass = getenv('REDIS_PASSWORD');
            if (is_string($envPass) && $envPass !== '') {
                $auth = $envPass;
            }
        }

        $zsetKey = (string)($options['zset_key'] ?? 'datahub_refresh_priority_queue');
        $messagesKey = (string)($options['messages_key'] ?? 'datahub_refresh_priority_messages');
        $inflightKey = (string)($options['inflight_key'] ?? 'datahub_refresh_priority_inflight');

        $cfg = $this->container->get('pimcore_data_hub');
        $graphql = is_array($cfg) ? ($cfg['graphql'] ?? []) : [];
        $visibilityTimeout = max(1, (int)($graphql['persistent_refresh_priority_visibility_timeout'] ?? 600));
        $requeueScoreBump = max(0, (int)($graphql['persistent_refresh_priority_requeue_score_bump'] ?? 5));
        $priorityStrategy = (string)($graphql['persistent_refresh_priority_strategy'] ?? 'oldest_refreshed_at_first');
        $validStrategies = ['oldest_refreshed_at_first', 'oldest_refreshed_at_first_with_weight_bands', 'disabled'];
        if (!in_array($priorityStrategy, $validStrategies, true)) {
            throw new \InvalidArgumentException(sprintf(
                'datahub.priority_transport: invalid persistent_refresh_priority_strategy "%s"; valid values: %s',
                $priorityStrategy,
                implode(', ', $validStrategies)
            ));
        }
        $weightBandSeconds = max(0, (int)($graphql['persistent_refresh_priority_weight_band_seconds'] ?? 60));
        $readTriggerOffsetSeconds = max(0, (int)($graphql['persistent_refresh_priority_read_trigger_offset_seconds'] ?? 86400));
        $maxWeight = max(1, (int)($graphql['persistent_refresh_priority_max_weight'] ?? 100));

        if ($priorityStrategy === 'oldest_refreshed_at_first_with_weight_bands'
            && $readTriggerOffsetSeconds <= $maxWeight * $weightBandSeconds
        ) {
            throw new \InvalidArgumentException(sprintf(
                'datahub.priority_transport: persistent_refresh_priority_read_trigger_offset_seconds (%d) must exceed'
                . ' persistent_refresh_priority_max_weight × persistent_refresh_priority_weight_band_seconds (%d × %d = %d)'
                . ' so the read class sorts strictly below the warm class under the weighted-bands strategy',
                $readTriggerOffsetSeconds,
                $maxWeight,
                $weightBandSeconds,
                $maxWeight * $weightBandSeconds
            ));
        }

        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('datahub.priority_transport: phpredis extension not installed');
        }
        $redis = new \Redis();
        $redis->connect($host, $port);
        if ($auth !== null) {
            $redis->auth($auth);
        }
        if ($db > 0) {
            $redis->select($db);
        }

        return new PriorityRedisTransport(
            $redis,
            $serializer,
            $zsetKey,
            $messagesKey,
            $inflightKey,
            $visibilityTimeout,
            $requeueScoreBump,
            $priorityStrategy,
            $weightBandSeconds,
            $readTriggerOffsetSeconds
        );
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, self::DSN_SCHEME);
    }
}
