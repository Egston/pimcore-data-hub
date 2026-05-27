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

namespace Pimcore\Bundle\DataHubBundle\MessageHandler;

use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQLService;
use Pimcore\Bundle\DataHubBundle\Lock\LockFactoryResolver;
use Pimcore\Bundle\DataHubBundle\Lock\LockSignalRefresher;
use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Service\DependencyCollector;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\OutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Bundle\DataHubBundle\Service\Tier;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Logger;
use Pimcore\Model\Factory;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Refresh-queue consumer for the persistent (SWR) GraphQL cache.
 *
 * Two lock spaces, picked by tier:
 *
 * - HERD_GUARDED: lock keyed by operationName via
 *   {@see OutputCacheService::computeOperationLockKey()} — must be byte-equal
 *   to the marker key shape the controller's early herd guard uses, so a
 *   per-op-name refresh in flight is observable to a same-op caller path.
 * - SWR_ONLY: lock keyed by the meta+payload sidecar pair via
 *   {@see PersistentOutputCacheService::computeSwrRefreshLockKey()} — same
 *   query-hash granularity as the SWR cold-miss lock so two distinct queries
 *   sharing an operationName don't serialise on each other.
 *
 * Contention is non-blocking: a lost acquisition throws
 * {@see RecoverableMessageHandlingException} so Messenger requeues under the
 * transport's retry strategy. The queue handler must never run two refreshes
 * of the same op-name (HERD_GUARDED) or the same query-hash (SWR_ONLY)
 * concurrently — that is the entire reason the queue exists.
 */
#[AsMessageHandler]
final class PersistentRefreshMessageHandler
{
    public function __construct(
        private OperationClassifier $classifier,
        private LockFactoryResolver $lockResolver,
        private WebserviceController $controller,
        private GraphQLService $graphQlService,
        private LocaleServiceInterface $localeService,
        private Factory $modelFactory,
        private LongRunningHelper $longRunningHelper,
        private ResponseServiceInterface $responseService,
        private ContainerBagInterface $container,
        private ?DependencyCollector $dependencyCollector = null,
        private ?MessageBusInterface $bus = null,
        private ?PersistentOutputCacheService $persistentCache = null
    ) {
    }

    public function __invoke(PersistentRefreshMessage $message): void
    {
        if ($this->dependencyCollector !== null) {
            $this->dependencyCollector->reset();
        }

        $operationName = (string)($message->operationName ?? '');
        if ($operationName === '') {
            Logger::debug('datahub.refresh_handler: missing operation name; dropping message');

            return;
        }

        $tier = $this->classifier->getTier($operationName);
        if ($tier === Tier::NEITHER) {
            Logger::debug(sprintf(
                'datahub.refresh_handler: unclassified operation %s; dropping message',
                $operationName
            ));

            return;
        }

        $factory = $this->lockResolver->resolve();
        if ($factory === null) {
            Logger::warning(
                'datahub.refresh_handler: lock factory unavailable; cannot serialise refresh, dropping message'
            );

            return;
        }

        $graphql = $this->graphqlConfig();
        $ttl = max(1, (int)($graphql['persistent_refresh_lock_ttl'] ?? 120));

        $lockResource = $tier === Tier::HERD_GUARDED
            ? OutputCacheService::computeOperationLockKey($operationName)
            : PersistentOutputCacheService::computeSwrRefreshLockKey($message->client, $message->bodyJson);

        $lock = $factory->createLock($lockResource, $ttl, false);
        if (!$lock->acquire(false)) {
            throw new RecoverableMessageHandlingException(sprintf(
                'datahub.refresh_handler: lock contended for %s tier=%s; requeue',
                $operationName,
                $tier->value
            ));
        }

        LockSignalRefresher::arm($lock, $ttl, max(1, (int) floor($ttl / 2)));

        $startedAt = microtime(true);
        // Reset the process-wide peak so the log line below reflects only
        // this invocation. The worker is long-lived (multiple messages share
        // one PHP process) so without this the peak would be cumulative.
        memory_reset_peak_usage();
        $memBefore = memory_get_usage(true);
        $varsSummary = self::summariseVariables($message->bodyJson);
        Logger::info(sprintf(
            'datahub.refresh_handler: started op=%s client=%s tier=%s vars=%s mem_before_mb=%.1f',
            $operationName,
            $message->client,
            $tier->value,
            $varsSummary,
            $memBefore / 1048576
        ));

        $controllerSucceeded = false;

        try {
            $request = Request::create(
                '/datahub/graphql',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                $message->bodyJson
            );
            $request->attributes->set('clientname', $message->client);
            $request->attributes->set('_datahub_persistent_refresh', true);
            $request->attributes->set('_datahub_bypass_in_progress_guard', true);

            try {
                $this->controller->webonyxAction(
                    $this->graphQlService,
                    $this->localeService,
                    $this->modelFactory,
                    $request,
                    $this->longRunningHelper,
                    $this->responseService
                );
                $controllerSucceeded = true;
            } catch (\Throwable $e) {
                if ($e instanceof RecoverableMessageHandlingException) {
                    throw $e;
                }
                $this->logError(sprintf(
                    'datahub.refresh_handler: controller invocation failed for %s: %s',
                    $operationName,
                    $e->getMessage()
                ));
            }
        } finally {
            LockSignalRefresher::disarm();

            try {
                $lock->release();
            } catch (\Throwable $e) {
                $this->logWarning(sprintf(
                    'datahub.refresh_handler: lock release failed for %s: %s',
                    $operationName,
                    $e->getMessage()
                ));
            }

            // On success, clear the dedupe sentinel and — if a pending flag
            // was set during processing — dispatch a trailing refresh.
            // Skipped on failure so the retry path doesn't pile dispatches.
            if ($controllerSucceeded) {
                $this->reconcileCoalesceFlags($message, $operationName);
                $this->closeCooldownWindow($message, $operationName);
            }

            Logger::info(sprintf(
                'datahub.refresh_handler: completed op=%s vars=%s duration_ms=%d peak_mb=%.1f delta_mb=%+.1f current_mb=%.1f',
                $operationName,
                $varsSummary,
                (int)((microtime(true) - $startedAt) * 1000),
                memory_get_peak_usage(true) / 1048576,
                (memory_get_peak_usage(true) - $memBefore) / 1048576,
                memory_get_usage(true) / 1048576
            ));
        }
    }

    /**
     * Wrapped in try/catch: a cache failure here is non-fatal — the dedupe
     * sentinel expires on its own TTL, costing a brief coalesce window.
     */
    private function reconcileCoalesceFlags(PersistentRefreshMessage $message, string $operationName): void
    {
        try {
            $hash = PersistentOutputCacheService::entryHashFromBody($message->client, $message->bodyJson);
            $dedupeKey = PersistentOutputCacheService::ENQUEUE_DEDUPE_PREFIX . $hash;
            $pendingKey = PersistentOutputCacheService::PENDING_REFRESH_PREFIX . $hash;

            $pending = \Pimcore\Cache::load($pendingKey);
            $hasPending = $pending !== false && $pending !== null;

            if ($hasPending) {
                \Pimcore\Cache::remove($pendingKey);
            }
            \Pimcore\Cache::remove($dedupeKey);

            if ($hasPending && $this->bus !== null) {
                $this->bus->dispatch(new PersistentRefreshMessage(
                    $message->client,
                    $message->bodyJson,
                    $message->operationName,
                    time(),
                    $message->priorityWeight
                ));
                Logger::info(sprintf(
                    'datahub.refresh_handler: trailing refresh dispatched for op=%s (coalesced invalidations seen during processing)',
                    $operationName
                ));
            }
        } catch (\Throwable $e) {
            $this->logWarning(sprintf(
                'datahub.refresh_handler: coalesce-flag reconcile failed for %s: %s',
                $operationName,
                $e->getMessage()
            ));
        }
    }

    /**
     * A cooldown-scheduled message (non-null deliverAt) carried the op-cooldown
     * sentinel that suppressed per-edit refreshes during its window. Clearing it
     * opens a fresh window so the next edit re-arms and schedules the following
     * dated refresh. Fail-soft: a clear failure is non-fatal — the sentinel
     * TTL-expires near deliverAt anyway.
     */
    private function closeCooldownWindow(PersistentRefreshMessage $message, string $operationName): void
    {
        if ($message->deliverAt === null || $this->persistentCache === null) {
            return;
        }

        try {
            $hash = PersistentOutputCacheService::entryHashFromBody($message->client, $message->bodyJson);
            $this->persistentCache->clearOperationCooldown($hash);
        } catch (\Throwable $e) {
            $this->logWarning(sprintf(
                'datahub.refresh_handler: cooldown-window close failed for %s: %s',
                $operationName,
                $e->getMessage()
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function graphqlConfig(): array
    {
        $cfg = $this->container->get('pimcore_data_hub');
        if (!is_array($cfg)) {
            return [];
        }
        $graphql = $cfg['graphql'] ?? [];

        return is_array($graphql) ? $graphql : [];
    }

    /**
     * Compact representation of the request `variables` for log lines. Returns
     * a short JSON blob trimmed to 200 chars so verbose filter payloads don't
     * blow the line length. Falls back to `?` when the body is unparseable.
     */
    private static function summariseVariables(string $bodyJson): string
    {
        $decoded = json_decode($bodyJson, true);
        if (!is_array($decoded)) {
            return '?';
        }
        $vars = $decoded['variables'] ?? null;
        if (!is_array($vars) || $vars === []) {
            return '{}';
        }
        $json = json_encode($vars, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '?';
        }

        return strlen($json) > 200 ? substr($json, 0, 197) . '...' : $json;
    }

    protected function logWarning(string $message): void
    {
        Logger::warning($message);
    }

    protected function logError(string $message): void
    {
        Logger::error($message);
    }
}
