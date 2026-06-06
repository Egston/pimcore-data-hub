<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional;

use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Pins consumer-resume behaviour: a SIGKILL'd consumer mid-handler must not
 * permanently hold the per-operation lock or strand the in-flight refresh
 * message in the priority transport's inflight HASH.
 *
 * The consumer is started as a subprocess against the priority transport.
 * After a short delay the parent process sends SIGKILL. The priority-transport
 * reaper restores the message to the ZSET once the visibility-timeout window
 * expires; a fresh consumer drains the requeued message. The test polls
 * until the inflight count drops (bounded by the transport's default
 * visibility timeout) before launching the drain consumer.
 */
final class FailureRecoveryTest extends KernelTestCase
{
    public function testKilledConsumerLeavesRequeuableMessage(): void
    {
        $bus = \Pimcore::getContainer()->get('messenger.bus.default');
        $bus->dispatch(new PersistentRefreshMessage(
            'default',
            json_encode([
                'operationName' => 'getTestSwrGuardedItemListing',
                'query' => 'query getTestSwrGuardedItemListing { getTestSwrGuardedItemListing { __typename } }',
                'variables' => [],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'getTestSwrGuardedItemListing',
            time() - 30,
            1
        ));

        $consoleBin = PIMCORE_PROJECT_ROOT . '/bin/console';
        $consumer = new Process([
            'php',
            $consoleBin,
            'messenger:consume',
            'datahub_graphql_refresh',
            '--limit=1',
            '--time-limit=60',
            '-q',
        ]);
        $consumer->setEnv(['APP_ENV' => 'test']);
        $consumer->start();
        $pollStart = time();
        while ($this->refreshInflightCount() === 0 && (time() - $pollStart) < 10) {
            usleep(100_000);
        }
        $consumer->signal(9);
        $consumer->wait();

        self::assertGreaterThan(
            0,
            $this->refreshInflightCount() + $this->refreshQueueDepth(),
            'killed consumer must leave the message either in inflight or back on the queue'
        );

        $pollStart = time();
        while ($this->refreshInflightCount() > 0 && (time() - $pollStart) < 10) {
            usleep(200_000);
        }

        $reaperRunner = new Process([
            'php',
            $consoleBin,
            'messenger:consume',
            'datahub_graphql_refresh',
            '--limit=1',
            '--time-limit=30',
            '-q',
        ]);
        $reaperRunner->setTimeout(60.0);
        $reaperRunner->setEnv(['APP_ENV' => 'test']);
        $reaperRunner->run();
        self::assertSame(0, $reaperRunner->getExitCode(), 'reaper-driven consume must drain the requeued message');
        self::assertSame(
            0,
            $this->refreshInflightCount() + $this->refreshQueueDepth(),
            'queue + inflight must be empty after fresh consumer drains the recovered message'
        );
    }
}
