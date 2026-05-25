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

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\DocumentEvents;
use Pimcore\Event\Model\ElementEventInterface;
use Pimcore\Logger;
use Pimcore\Model\Element\ElementInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Marks the persistent GraphQL cache as potentially stale when content changes.
 *
 * Per-tag invalidation path: each changed element yields a per-object tag
 * (`TAG_OBJECT_PREFIX . <sanitized-class>_<id>`) and a per-class tag
 * (`TAG_CLASS_PREFIX . <sanitized-class>`). The reverse index
 * (`REVERSE_INDEX_PREFIX . <tag>`) is consulted to find the
 * `<payloadKey, metaKey>` pairs that depend on the changed tag; those are
 * either dispatched to the refresh queue (when enabled) or fall through to
 * the global watermark bump as the safety floor that preserves the
 * `lastInvalidation > refreshedAt` stale-detection in preHandle.
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

    public function mark(Event $event): void
    {
        try {
            $cfg = $this->container->get('pimcore_data_hub');
            $graphql = is_array($cfg) ? ($cfg['graphql'] ?? []) : [];
            $queueEnabled = ($graphql['persistent_refresh_queue_enabled'] ?? false) && $this->bus !== null;
            $enqueueTtl = max(1, (int)($graphql['persistent_enqueue_dedupe_ttl'] ?? 60));

            if (!$queueEnabled) {
                // Safety floor: the global watermark bump preserves the
                // `lastInvalidation > refreshedAt` stale-detection in preHandle
                // when the queue path is disabled.
                $this->persistentCache->markOutputInvalidated();

                return;
            }

            $element = $this->extractElement($event);
            if ($element === null) {
                // Non-element event on the queue-enabled path: fall back to the watermark
                // so invalidation is never silently dropped.
                $this->persistentCache->markOutputInvalidated();

                return;
            }

            $tags = $this->tagsForElement($element);
            $dispatched = $this->dispatchForTags($tags, $enqueueTtl);
            if ($dispatched === 0) {
                // Zero bus dispatches: every tag missed the reverse index, all pairs were
                // malformed/deduped, or tagsForElement returned []. Bump the watermark so
                // the invalidation is never silently lost.
                $this->persistentCache->markOutputInvalidated();
            }
        } catch (\Throwable $e) {
            Logger::error('persistent_cache_invalidation: ' . $e->getMessage(), ['exception' => $e]);

            // Safety floor: bump the watermark so any exception in the queue-dispatch path
            // does not silently drop the invalidation signal.
            try {
                $this->persistentCache->markOutputInvalidated();
            } catch (\Throwable) {
                // best effort
            }
        }
    }

    private function extractElement(Event $event): ?ElementInterface
    {
        if ($event instanceof ElementEventInterface) {
            return $event->getElement();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function tagsForElement(?ElementInterface $element): array
    {
        if ($element === null) {
            return [];
        }
        $id = $element->getId();
        if ($id === null) {
            return [];
        }
        $sanitizedClass = str_replace('\\', '_', ltrim(get_class($element), '\\'));

        return [
            PersistentOutputCacheService::TAG_OBJECT_PREFIX . $sanitizedClass . '_' . $id,
            PersistentOutputCacheService::TAG_CLASS_PREFIX . $sanitizedClass,
        ];
    }

    /**
     * Per-tag bus dispatch. The refresh-priority score on each dispatched
     * message is `time()` because no per-entry refreshedAt context is
     * available in the invalidation path — every entry is freshly stale, so
     * the queue treats them as roughly equivalent and ZPOPMIN orders by
     * insertion time within the same-second bucket.
     *
     * @param list<string> $tags
     *
     * @return int number of bus dispatches that fired
     */
    private function dispatchForTags(array $tags, int $enqueueTtl): int
    {
        if ($tags === []) {
            return 0;
        }
        $seenPayloadKeys = [];
        $dispatchCount = 0;
        foreach ($tags as $tag) {
            $list = $this->cacheLoad(PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $tag);
            if (!is_array($list) || $list === []) {
                if (str_starts_with($tag, PersistentOutputCacheService::TAG_OBJECT_PREFIX)) {
                    Logger::debug('persistent_cache_invalidation: reverse-index empty for per-object tag', ['tag' => $tag]);
                }

                continue;
            }
            foreach ($list as $pair) {
                if (!is_array($pair) || count($pair) < 2) {
                    continue;
                }
                if (!is_string($pair[0]) || !is_string($pair[1])) {
                    Logger::warning('persistent_cache_invalidation: reverse-index entry not a string pair', ['tag' => $tag]);

                    continue;
                }
                $payloadKey = $pair[0];
                $metaKey = $pair[1];
                if ($payloadKey === '' || $metaKey === '' || isset($seenPayloadKeys[$payloadKey])) {
                    continue;
                }
                if (!str_starts_with($payloadKey, PersistentOutputCacheService::PAYLOAD_KEY_PREFIX)) {
                    Logger::warning('persistent_cache_invalidation: payload key missing expected prefix', ['tag' => $tag]);

                    continue;
                }
                $seenPayloadKeys[$payloadKey] = true;

                $meta = $this->cacheLoad($metaKey);
                if (!is_array($meta)) {
                    Logger::debug('persistent_cache_invalidation: meta key evicted or never written, skipping pair', ['tag' => $tag, 'metaKey' => $metaKey]);

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
                $existing = $this->cacheLoad($dedupeKey);
                if ($existing !== false && $existing !== null) {
                    continue;
                }
                $this->cacheSave(
                    1,
                    $dedupeKey,
                    [PersistentOutputCacheService::TAG_COMMON],
                    $enqueueTtl
                );
                $this->bus->dispatch(new PersistentRefreshMessage(
                    $client,
                    $canonical,
                    $operation !== '' ? $operation : null,
                    time()
                ));
                ++$dispatchCount;
            }
        }

        return $dispatchCount;
    }

    /**
     * Read helper – separated for testability so the listener can be
     * exercised without booting the Pimcore kernel.
     *
     * @return mixed
     */
    protected function cacheLoad(string $key)
    {
        return \Pimcore\Cache::load($key);
    }

    /**
     * @param mixed $value
     */
    protected function cacheSave($value, string $key, array $tags, int $ttl): void
    {
        \Pimcore\Cache::save($value, $key, $tags, $ttl, 1, true);
    }
}
