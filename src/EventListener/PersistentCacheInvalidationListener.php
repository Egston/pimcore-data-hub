<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\DocumentEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Marks the persistent GraphQL cache as potentially stale when content changes.
 *
 * This is a best-effort fallback to track a last-invalidation timestamp that
 * we compare against cached responses. It does not depend on Pimcore's 'output'
 * tag invalidation directly, but on relevant content change events instead.
 */
class PersistentCacheInvalidationListener implements EventSubscriberInterface
{
    public function __construct(private PersistentOutputCacheService $persistentCache)
    {
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
        // Additionally schedule refreshes for guarded queries if queueing is enabled
        try {
            $cfg = \Pimcore::getContainer()->get('pimcore_data_hub');
            $graphql = $cfg['graphql'] ?? [];
            if (!($graphql['persistent_refresh_queue_enabled'] ?? false)) {
                return;
            }

            $herdEnabled = (bool)($graphql['in_progress_protection_enabled'] ?? false);
            $ops = (array)($graphql['in_progress_queries'] ?? []);
            if (!$herdEnabled || empty($ops)) {
                return;
            }

            $bus = \Pimcore::getContainer()->get('messenger.default_bus');
            $enqueueTtl = max(1, (int)($graphql['persistent_enqueue_dedupe_ttl'] ?? 60));

            foreach ($ops as $op) {
                if (!is_string($op) || $op === '') { continue; }
                $indexKey = 'datahub_graphql_persistent_index_op_' . $op;
                $list = \Pimcore\Cache::load($indexKey);
                if (!is_array($list)) { continue; }
                foreach ($list as $payloadKey) {
                    if (!is_string($payloadKey)) { continue; }
                    if (!str_starts_with($payloadKey, 'persistent_output_payload_')) { continue; }
                    $suffix = substr($payloadKey, strlen('persistent_output_payload_'));
                    $metaKey = 'persistent_output_meta_' . $suffix;
                    $meta = \Pimcore\Cache::load($metaKey);
                    if (!is_array($meta)) { continue; }
                    $client = (string)($meta['client'] ?? '');
                    $canonical = (string)($meta['canonical'] ?? '');
                    if ($client === '' || $canonical === '') { continue; }
                    $dedupeKey = 'datahub_enqueue_req_' . hash('sha256', 'client:' . $client . "\n" . $canonical);
                    $existing = \Pimcore\Cache::load($dedupeKey);
                    if ($existing !== false && $existing !== null) { continue; }
                    \Pimcore\Cache::save(1, $dedupeKey, ['datahub_graphql_persistent'], $enqueueTtl, 1, true);

                    $messageClass = 'Pimcore\\Bundle\\DataHubBundle\\Message\\PersistentRefreshMessage';
                    if (class_exists($messageClass)) {
                        $msg = new $messageClass($client, $canonical, $op);
                        $bus->dispatch($msg);
                    }
                }
            }
        } catch (\Throwable $e) {
            // best-effort scheduling
        }
    }
}
