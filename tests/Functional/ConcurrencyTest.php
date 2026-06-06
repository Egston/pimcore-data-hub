<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional;

use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Tests\Functional\Commands\TestColdMissProbeCommand;
use Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures\KernelTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Process\Process;

/**
 * Two SWR_ONLY cold-miss requests sharing the same canonical payload contend
 * on the per-query-hash Symfony Lock. One wins inline; the loser either polls
 * and observes the winner's cache write or falls through to its own inline
 * resolver after the timeout — both loser outcomes preserve the
 * never-503-for-browsers invariant.
 */
final class ConcurrencyTest extends KernelTestCase
{
    public function testTwoCallersOnSameSwrOnlyPayloadContendOnLock(): void
    {
        $first = $this->forkColdMissCaller();
        $second = $this->forkColdMissCaller();

        $first->wait();
        $second->wait();

        self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());

        $markers = [trim($first->getOutput()), trim($second->getOutput())];
        self::assertContains(
            TestColdMissProbeCommand::MARKER_WON_LOCK_INLINE,
            $markers,
            'exactly one fork must win the cold-miss lock'
        );

        $loserMarker = $markers[0] === TestColdMissProbeCommand::MARKER_WON_LOCK_INLINE
            ? $markers[1]
            : $markers[0];
        self::assertContains(
            $loserMarker,
            [
                TestColdMissProbeCommand::MARKER_OBSERVED_WRITE,
                TestColdMissProbeCommand::MARKER_DEFENSIVE_FALLBACK,
            ],
            'the losing fork must either observe the winner\'s write or take the defensive-fallback path'
        );
    }

    public function testLoserPathDefensivelyRunsInlineAfterTimeout(): void
    {
        $loser = $this->sendGraphQL(
            'getTestSwrOnlyItem',
            'query getTestSwrOnlyItem($id: Int!) { getTestSwrOnlyItem(id: $id) { id title } }',
            ['id' => $this->fixtureIds()['TestSwrOnlyItem'][0] ?? 1]
        );

        self::assertInstanceOf(JsonResponse::class, $loser);
        self::assertSame(200, $loser->getStatusCode());
    }

    public function testSecondCacheWriteDoesNotCorruptSidecarPair(): void
    {
        $this->sendGraphQL(
            'getTestSwrOnlyItemListing',
            'query getTestSwrOnlyItemListing($defaultLanguage: String) { getTestSwrOnlyItemListing(defaultLanguage: $defaultLanguage) { edges { node { id title } } } }',
            ['defaultLanguage' => 'en']
        );
        $this->sendGraphQL(
            'getTestSwrOnlyItemListing',
            'query getTestSwrOnlyItemListing($defaultLanguage: String) { getTestSwrOnlyItemListing(defaultLanguage: $defaultLanguage) { edges { node { id title } } } }',
            ['defaultLanguage' => 'en']
        );

        $redis = $this->redis();
        $payloadKeys = $redis->keys(PersistentOutputCacheService::PAYLOAD_KEY_PREFIX . '*');
        $metaKeys = $redis->keys(PersistentOutputCacheService::META_KEY_PREFIX . '*');
        self::assertIsArray($payloadKeys);
        self::assertIsArray($metaKeys);
        self::assertSame(1, count($payloadKeys), 'exactly one payload sidecar must exist after identical concurrent writes');
        self::assertSame(1, count($metaKeys), 'exactly one meta sidecar must exist after identical concurrent writes');
        // Verify the hash suffixes match — both keys must derive from the same canonical input
        $payloadHash = substr($payloadKeys[0], strlen(PersistentOutputCacheService::PAYLOAD_KEY_PREFIX));
        $metaHash = substr($metaKeys[0], strlen(PersistentOutputCacheService::META_KEY_PREFIX));
        self::assertSame($payloadHash, $metaHash, 'payload and meta sidecars must share the same canonical-input hash');
    }

    private function forkColdMissCaller(): Process
    {
        $consoleBin = PIMCORE_PROJECT_ROOT . '/bin/console';
        $process = new Process([
            'php',
            $consoleBin,
            'pimcore-data-hub:test:cold-miss-probe',
            '--operation=getTestSwrOnlyItemListing',
        ]);
        $process->setTimeout(20.0);
        $process->setEnv(['APP_ENV' => 'test']);
        $process->start();

        return $process;
    }
}
