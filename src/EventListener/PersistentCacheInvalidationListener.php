<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\DocumentEvents;
use Pimcore\Logger;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Marks the persistent GraphQL cache as potentially stale when content changes.
 *
 * This is a best-effort fallback to track a last-invalidation timestamp that
 * we compare against cached responses. It does not depend on Pimcore's 'output'
 * tag invalidation directly, but on relevant content change events instead.
 */
class PersistentCacheInvalidationListener implements EventSubscriberInterface
{
    public function __construct(
        private PersistentOutputCacheService $persistentCache,
        private ContainerBagInterface $container,
        private ?MessageBusInterface $bus = null
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DataObjectEvents::POST_UPDATE => 'mark',
            DataObjectEvents::POST_DELETE => 'mark',
            DocumentEvents::POST_UPDATE => 'mark',
            DocumentEvents::POST_DELETE => 'mark',
            AssetEvents::POST_UPDATE => 'mark',
            AssetEvents::POST_DELETE => 'mark',
        ];
    }

    public function mark(): void
    {
        $this->persistentCache->markOutputInvalidated();
        // Additionally schedule background refreshes for every persisted entry
        // when async refresh is enabled. INDEX_ALL is iterated so non-guarded
        // operations (persistent_output_cache_guard_only=false) are covered too;
        // herd protection and the index are independent concerns.
        try {
            $cfg = $this->container->get('pimcore_data_hub');
            $graphql = $cfg['graphql'] ?? [];
            if (!($graphql['persistent_refresh_queue_enabled'] ?? false) || $this->bus === null) {
                return;
            }

            $enqueueTtl = max(1, (int)($graphql['persistent_enqueue_dedupe_ttl'] ?? 60));

            $list = \Pimcore\Cache::load(PersistentOutputCacheService::INDEX_ALL);
            if (!is_array($list)) {
                return;
            }
            $metaPrefixLen = strlen(PersistentOutputCacheService::PAYLOAD_KEY_PREFIX);
            foreach ($list as $payloadKey) {
                if (!is_string($payloadKey) || !str_starts_with($payloadKey, PersistentOutputCacheService::PAYLOAD_KEY_PREFIX)) {
                    continue;
                }
                $metaKey = PersistentOutputCacheService::META_KEY_PREFIX . substr($payloadKey, $metaPrefixLen);
                $meta = \Pimcore\Cache::load($metaKey);
                if (!is_array($meta)) {
                    continue;
                }
                $client = (string)($meta['client'] ?? '');
                $canonical = (string)($meta['canonical'] ?? '');
                $operation = (string)($meta['operation'] ?? '');
                if ($client === '' || $canonical === '') {
                    continue;
                }
                $dedupeKey = PersistentOutputCacheService::ENQUEUE_DEDUPE_PREFIX
                    . hash('sha256', 'client:' . $client . "\n" . $canonical);
                $existing = \Pimcore\Cache::load($dedupeKey);
                if ($existing !== false && $existing !== null) {
                    continue;
                }
                \Pimcore\Cache::save(
                    1,
                    $dedupeKey,
                    [PersistentOutputCacheService::TAG_COMMON],
                    $enqueueTtl,
                    1,
                    true
                );
                $this->bus->dispatch(new PersistentRefreshMessage($client, $canonical, $operation !== '' ? $operation : null));
            }
        } catch (\Throwable $e) {
            Logger::error('DataHub persistent cache invalidation listener: scheduling failed: ' . $e->getMessage());
        }
    }
}
