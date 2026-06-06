<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional;

use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Service\OutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Pins the enqueue-then-consume contract for the Messenger refresh handler:
 *
 *  - Two enqueued messages (one HERD_GUARDED, one SWR_ONLY) consumed via
 *    `bin/console messenger:consume --limit=N` are dispatched to the resolver
 *    in their popped order.
 *  - DependencyCollector is reset at handler entry between messages — tags
 *    from the first message do not bleed into the second's cache write.
 *  - Per-tier lock-resource shape: HERD_GUARDED locks on operationName via
 *    {@see OutputCacheService::computeOperationLockKey()}; SWR_ONLY locks on
 *    the meta+payload sidecar pair via
 *    {@see PersistentOutputCacheService::computeSwrRefreshLockKey()}.
 */
final class RefreshQueueTest extends KernelTestCase
{
    public function testEnqueueThenConsumeDispatchesEachTierToResolver(): void
    {
        $bus = \Pimcore::getContainer()->get('messenger.bus.default');
        $bus->dispatch(new PersistentRefreshMessage(
            'default',
            $this->buildBody('getTestSwrGuardedItemListing'),
            'getTestSwrGuardedItemListing',
            time() - 60,
            1
        ));
        $bus->dispatch(new PersistentRefreshMessage(
            'default',
            $this->buildBody('getTestSwrOnlyItemListing', ['defaultLanguage' => 'en']),
            'getTestSwrOnlyItemListing',
            time() - 30,
            1
        ));

        $beforeDepth = $this->refreshQueueDepth();
        self::assertSame(2, $beforeDepth, 'exactly two messages should be on the transport before consume');

        $this->runConsumer(2);

        self::assertSame(
            0,
            $this->refreshQueueDepth(),
            'queue depth must drop to zero after the consumer drains both messages'
        );

        $redis = $this->redis();
        $payloadKeys = $redis->keys(PersistentOutputCacheService::PAYLOAD_KEY_PREFIX . '*');
        $metaKeys = $redis->keys(PersistentOutputCacheService::META_KEY_PREFIX . '*');
        self::assertIsArray($payloadKeys);
        self::assertIsArray($metaKeys);
        self::assertGreaterThanOrEqual(2, count($payloadKeys), 'both messages must produce a payload sidecar after consume');
        self::assertSame(count($payloadKeys), count($metaKeys), 'every payload sidecar must have a paired meta sidecar');
    }

    public function testHerdGuardedHandlerReleasesItsOwnLock(): void
    {
        $operation = 'getTestSwrGuardedItem';
        $controllerResource = OutputCacheService::computeOperationLockKey($operation);
        self::assertStringStartsWith('datahub_inprogress:', $controllerResource);

        $bus = \Pimcore::getContainer()->get('messenger.bus.default');
        $bus->dispatch(new PersistentRefreshMessage(
            'default',
            $this->buildBody($operation, ['id' => 1]),
            $operation,
            time() - 30,
            1
        ));

        $this->runConsumer(1);

        $redis = $this->redis();
        $stillHeld = $redis->exists($controllerResource);
        self::assertSame(0, (int)$stillHeld, 'handler must release the operationName-keyed lock');
    }

    public function testDependencyCollectorResetsBetweenMessages(): void
    {
        $guardedBody = $this->buildBody('getTestSwrGuardedItemListing');
        $swrOnlyBody = $this->buildBody('getTestSwrOnlyItem', ['id' => 1]);

        $bus = \Pimcore::getContainer()->get('messenger.bus.default');
        $bus->dispatch(new PersistentRefreshMessage('default', $guardedBody, 'getTestSwrGuardedItemListing', time() - 60, 1));
        $bus->dispatch(new PersistentRefreshMessage('default', $swrOnlyBody, 'getTestSwrOnlyItem', time() - 30, 1));

        $this->runConsumer(2);

        $redis = $this->redis();

        $guardedPayloadKey = PersistentOutputCacheService::keyPayloadFor('default', PersistentOutputCacheService::canonicalizePayloadString($guardedBody));
        $swrOnlyPayloadKey = PersistentOutputCacheService::keyPayloadFor('default', PersistentOutputCacheService::canonicalizePayloadString($swrOnlyBody));
        self::assertNotSame($guardedPayloadKey, $swrOnlyPayloadKey, 'two different operations must produce distinct payload keys');

        $payloadKeys = $redis->keys(PersistentOutputCacheService::PAYLOAD_KEY_PREFIX . '*');
        self::assertIsArray($payloadKeys);
        self::assertCount(2, $payloadKeys, 'each message must write exactly one payload sidecar');
        self::assertContains($guardedPayloadKey, $payloadKeys, 'guarded-listing sidecar must exist after consume');
        self::assertContains($swrOnlyPayloadKey, $payloadKeys, 'swr-only-item sidecar must exist after consume');

        // DependencyCollector reset invariant: message-1 tags must not bleed into message-2 reverse-index entries.
        $guardedClassTag = PersistentOutputCacheService::TAG_CLASS_PREFIX
            . str_replace('\\', '_', ltrim(\Pimcore\Model\DataObject\TestSwrGuardedItem::class, '\\'));
        $swrOnlyClassTag = PersistentOutputCacheService::TAG_CLASS_PREFIX
            . str_replace('\\', '_', ltrim(\Pimcore\Model\DataObject\TestSwrOnlyItem::class, '\\'));

        $guardedPairs = \Pimcore\Cache::load(PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $guardedClassTag);
        $swrOnlyPairs = \Pimcore\Cache::load(PersistentOutputCacheService::REVERSE_INDEX_PREFIX . $swrOnlyClassTag);

        self::assertIsArray($guardedPairs, 'TestSwrGuardedItem class tag must have a reverse-index entry after consume');
        self::assertIsArray($swrOnlyPairs, 'TestSwrOnlyItem class tag must have a reverse-index entry after consume');
        self::assertContains($guardedPayloadKey, $guardedPairs, 'HERD_GUARDED class tag must reverse-index its payload');
        self::assertContains($swrOnlyPayloadKey, $swrOnlyPairs, 'SWR_ONLY class tag must reverse-index its payload');

        $guardedKeys = array_column($guardedPairs, 0);
        $swrOnlyKeys = array_column($swrOnlyPairs, 0);
        self::assertNotContains($swrOnlyPayloadKey, $guardedKeys, 'TestSwrGuardedItem class-tag must not reference the swr-only payload key');
        self::assertNotContains($guardedPayloadKey, $swrOnlyKeys, 'TestSwrOnlyItem class-tag must not reference the guarded payload key');
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function buildBody(string $operationName, array $variables = []): string
    {
        $query = sprintf('query %s { %s { __typename } }', $operationName, $operationName);
        $payload = [
            'operationName' => $operationName,
            'query' => $query,
            'variables' => $variables,
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function runConsumer(int $limit): void
    {
        $consoleBin = PIMCORE_PROJECT_ROOT . '/bin/console';
        $process = new Process([
            'php',
            $consoleBin,
            'messenger:consume',
            'datahub_graphql_refresh',
            '--limit=' . $limit,
            '--time-limit=30',
            '-q',
        ]);
        $process->setTimeout(60.0);
        $process->setEnv(['APP_ENV' => 'test']);
        $process->run();
        if (!$process->isSuccessful()) {
            self::fail(sprintf(
                'messenger:consume failed (exit %d): %s',
                (int)$process->getExitCode(),
                $process->getErrorOutput()
            ));
        }
    }
}
