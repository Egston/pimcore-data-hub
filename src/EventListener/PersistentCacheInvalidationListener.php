<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Service\CooldownInvalidationDecisionKind;
use Pimcore\Bundle\DataHubBundle\Service\CooldownRefreshPolicy;
use Pimcore\Bundle\DataHubBundle\Service\CooldownWindowDispatcher;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
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
 * `fallbackWatermark > refreshedAt` stale-detection in preHandle.
 */
class PersistentCacheInvalidationListener implements EventSubscriberInterface
{
    private CooldownRefreshPolicy $policy;

    public function __construct(
        private PersistentOutputCacheService $persistentCache,
        private ContainerBagInterface $container,
        private ?MessageBusInterface $bus = null,
        private ?OperationClassifier $classifier = null,
        private ?CooldownWindowDispatcher $cooldownDispatcher = null,
        ?CooldownRefreshPolicy $policy = null,
    ) {
        $this->policy = $policy ?? new CooldownRefreshPolicy($persistentCache);
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
            // saveVersion-only events (autoSave, save-as-draft) create a new
            // version row without advancing the published-version pointer the
            // resolver reads from, so the cache stays correct without any
            // refresh. Publish / unpublish / first-save-of-unpublished go
            // through the full save path and arrive without this argument.
            if (self::isVersionOnlySave($event)) {
                return;
            }

            $cfg = $this->container->get('pimcore_data_hub');
            $graphql = is_array($cfg) ? ($cfg['graphql'] ?? []) : [];
            $queueEnabled = ($graphql['persistent_refresh_queue_enabled'] ?? false) && $this->bus !== null;
            $enqueueTtl = max(1, (int)($graphql['persistent_enqueue_dedupe_ttl'] ?? 60));

            if (!$queueEnabled) {
                // Safety floor: the global watermark bump preserves the
                // `fallbackWatermark > refreshedAt` stale-detection in preHandle
                // when the queue path is disabled.
                $this->persistentCache->bumpFallbackWatermark();
                Logger::info('persistent_cache_invalidation: watermark bumped (queue disabled)');

                return;
            }

            $element = $this->extractElement($event);
            if ($element === null) {
                // Non-element event on the queue-enabled path: fall back to the watermark
                // so invalidation is never silently dropped.
                $this->persistentCache->bumpFallbackWatermark();
                Logger::info(sprintf(
                    'persistent_cache_invalidation: watermark bumped (non-element event, type=%s)',
                    get_class($event)
                ));

                return;
            }

            $tags = $this->tagsForElement($element);
            $result = $this->dispatchForTags($tags, $enqueueTtl);

            if ($result['dispatched'] !== []) {
                Logger::info(sprintf(
                    'persistent_cache_invalidation: dispatched %d refresh message(s) for %s id=%s | %s',
                    count($result['dispatched']),
                    get_class($element),
                    (string)$element->getId(),
                    self::summariseDispatched($result['dispatched'])
                ));
            } elseif ($result['coalesced'] > 0) {
                // Every matching entry was already covered by an in-flight
                // dedupe sentinel from a prior dispatch (typically Pimcore
                // firing POST_UPDATE twice for one save). Bumping the global
                // watermark here would mark every persistent-cache entry
                // STALE and cascade unrelated refreshes via the
                // kernel.terminate dispatcher; the pending-flag set inside
                // dispatchForTags covers the late-arrival case instead.
                Logger::info(sprintf(
                    'persistent_cache_invalidation: %d entries coalesced into in-flight dispatches for %s id=%s (no new dispatch, no watermark bump)',
                    $result['coalesced'],
                    get_class($element),
                    (string)$element->getId()
                ));
            } elseif (!$result['hadReverseIndexHits']) {
                // Nothing in cache depends on this element's tags — watermark
                // safety floor is appropriate for the genuinely-unknown case.
                $this->persistentCache->bumpFallbackWatermark();
                Logger::info(sprintf(
                    'persistent_cache_invalidation: watermark bumped (no cached entries depend on tags) for %s id=%s tags=[%s]',
                    get_class($element),
                    (string)$element->getId(),
                    implode(', ', $tags)
                ));
            } else {
                // All reverse-index pairs were malformed (warned per-entry
                // above). Don't amplify a data-shape bug into a global bump.
                Logger::warning(sprintf(
                    'persistent_cache_invalidation: all reverse-index entries malformed for %s id=%s tags=[%s]',
                    get_class($element),
                    (string)$element->getId(),
                    implode(', ', $tags)
                ));
            }
        } catch (\Throwable $e) {
            Logger::error('persistent_cache_invalidation: ' . $e->getMessage(), ['exception' => $e]);

            // Safety floor: bump the watermark so any exception in the queue-dispatch path
            // does not silently drop the invalidation signal. If the bump itself fails,
            // log it distinctly — that's the actual "we lost the invalidation" event.
            try {
                $this->persistentCache->bumpFallbackWatermark();
            } catch (\Throwable $bumpErr) {
                Logger::error(
                    'persistent_cache_invalidation: watermark-bump fallback failed',
                    ['exception' => $bumpErr]
                );
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
     * Duck-typed so the listener doesn't depend on Pimcore's per-element
     * event subclasses where hasArgument / getArgument actually live.
     */
    private static function isVersionOnlySave(Event $event): bool
    {
        if (!method_exists($event, 'hasArgument') || !method_exists($event, 'getArgument')) {
            return false;
        }
        if (!$event->hasArgument('saveVersionOnly')) {
            return false;
        }

        return $event->getArgument('saveVersionOnly') === true;
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
     * the queue treats them as roughly equivalent and ZRANGEBYSCORE orders by
     * insertion time within the same-second bucket.
     *
     * The richer return shape lets the caller distinguish three
     * dispatched=0 outcomes: empty reverse-index (watermark fallback),
     * coalesced into an in-flight dispatch (no-op), or all pairs malformed
     * (already warned per-entry).
     *
     * @param list<string> $tags
     *
     * @return array{dispatched: list<array{op: string, vars: string}>, coalesced: int, hadReverseIndexHits: bool}
     */
    private function dispatchForTags(array $tags, int $enqueueTtl): array
    {
        $result = ['dispatched' => [], 'coalesced' => 0, 'hadReverseIndexHits' => false];
        if ($tags === []) {
            return $result;
        }
        $seenPayloadKeys = [];
        foreach ($tags as $tag) {
            $list = $this->cacheLoad(PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $tag);
            if (!is_array($list) || $list === []) {
                if (str_starts_with($tag, PersistentOutputCacheService::TAG_OBJECT_PREFIX)) {
                    Logger::debug('persistent_cache_invalidation: reverse-index empty for per-object tag', ['tag' => $tag]);
                }

                continue;
            }
            $result['hadReverseIndexHits'] = true;
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

                $hash = PersistentOutputCacheService::entryHash($client, $canonical);
                // Single `$now` per entry: stamp + dispatch score baseline share it
                // so the refresh score never predates the invalidation it answers.
                $now = time();

                // Worker's freshness guard reads `invalidatedAt` regardless of
                // dispatch arm; coalesced arms (no new dispatch) rely on the
                // stamp to drive re-fire via meta re-read at pop time. Must
                // precede the dispatch-arm fork.
                $this->persistentCache->stampInvalidatedAt($metaKey, $meta, $now);

                $cooldown = $operation !== '' && $this->classifier !== null
                    ? $this->classifier->getInvalidationCooldown($operation)
                    : null;
                if ($cooldown !== null) {
                    $priorityWeight = $this->classifier?->bandWeightFor($operation, false);

                    $template = new PersistentRefreshMessage(
                        client: $client,
                        bodyJson: $canonical,
                        operationName: $operation,
                        scoreBaseline: $now,
                        priorityWeight: $priorityWeight,
                    );

                    $decision = $this->policy->decideOnInvalidation($meta, $cooldown, $hash, $now);

                    switch ($decision->kind) {
                        case CooldownInvalidationDecisionKind::LEADING_EDGE:
                            $this->bus->dispatch($template);
                            $this->cooldownDispatcher?->open($hash, $cooldown, (int) $decision->deliverAt, $template);
                            Logger::info(sprintf(
                                'persistent_cache_invalidation: leading-edge refresh dispatched for op=%s',
                                $operation
                            ));
                            $result['dispatched'][] = [
                                'op' => $operation,
                                'vars' => PersistentOutputCacheService::summariseVariables($canonical),
                            ];

                            break;

                        case CooldownInvalidationDecisionKind::COALESCE_ARMED:
                            $pendingKey = PersistentOutputCacheService::PENDING_REFRESH_PREFIX . $hash;
                            $this->cacheSave(
                                $pendingKey,
                                1,
                                [PersistentOutputCacheService::TAG_COMMON],
                                max($enqueueTtl * 10, 600)
                            );
                            Logger::info(sprintf(
                                'persistent_cache_invalidation: cooldown active for op=%s; trailing refresh already queued',
                                $operation
                            ));
                            ++$result['coalesced'];

                            break;

                        case CooldownInvalidationDecisionKind::OPEN_TRAILING:
                            $this->cooldownDispatcher?->open(
                                $hash,
                                $cooldown,
                                (int) $decision->deliverAt,
                                $template,
                            );
                            Logger::info(sprintf(
                                'persistent_cache_invalidation: trailing refresh scheduled for op=%s at window end',
                                $operation
                            ));
                            $result['dispatched'][] = [
                                'op' => $operation,
                                'vars' => PersistentOutputCacheService::summariseVariables($canonical),
                            ];

                            break;

                        default:
                            throw new \LogicException('unreachable: unhandled CooldownInvalidationDecisionKind ' . $decision->kind->value . ' for op=' . $operation);
                    }

                    continue;
                }

                $dedupeKey = PersistentOutputCacheService::ENQUEUE_DEDUPE_PREFIX . $hash;
                $existing = $this->cacheLoad($dedupeKey);
                if ($existing !== false && $existing !== null) {
                    // Prior dispatch still in flight. Record a pending flag
                    // (TTL deliberately longer than the dedupe sentinel) so
                    // the worker fires a trailing refresh on completion and
                    // a late event isn't lost to the worker's pre-commit read.
                    $pendingKey = PersistentOutputCacheService::PENDING_REFRESH_PREFIX . $hash;
                    $this->cacheSave(
                        $pendingKey,
                        1,
                        [PersistentOutputCacheService::TAG_COMMON],
                        max($enqueueTtl * 10, 600)
                    );
                    ++$result['coalesced'];

                    continue;
                }
                $this->cacheSave(
                    $dedupeKey,
                    1,
                    [PersistentOutputCacheService::TAG_COMMON],
                    $enqueueTtl
                );
                $this->bus->dispatch(new PersistentRefreshMessage(
                    $client,
                    $canonical,
                    $operation !== '' ? $operation : null,
                    $now
                ));
                $result['dispatched'][] = [
                    'op' => $operation !== '' ? $operation : '?',
                    'vars' => PersistentOutputCacheService::summariseVariables($canonical),
                ];
            }
        }

        return $result;
    }

    /**
     * Aggregate the per-dispatch list as `op×N{vars,vars,...}` so a
     * high-fanout save produces one readable line, with arg variants
     * (e.g. language) listed explicitly for ops dispatched multiple times.
     *
     * @param list<array{op: string, vars: string}> $dispatched
     */
    private static function summariseDispatched(array $dispatched): string
    {
        $byOp = [];
        foreach ($dispatched as $entry) {
            $byOp[$entry['op']][] = $entry['vars'];
        }
        $parts = [];
        foreach ($byOp as $op => $varsList) {
            $parts[] = $op . '×' . count($varsList) . '{' . implode(', ', $varsList) . '}';
        }

        return implode('; ', $parts);
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
    protected function cacheSave(string $key, $value, array $tags, int $ttl): void
    {
        \Pimcore\Cache::save($value, $key, $tags, $ttl, 1, true);
    }
}
