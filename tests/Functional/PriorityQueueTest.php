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

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional;

use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures\KernelTestCase;

/**
 * Pins the priority-dispatch ordering invariant against the real Redis-backed
 * {@see \Pimcore\Bundle\DataHubBundle\Messenger\PriorityRedisTransport}:
 *
 *  - Under `oldest_refreshed_at_first`, three messages with non-monotonic
 *    scoreBaseline values drain lowest-score-first.
 *  - Under `oldest_refreshed_at_first_with_weight_bands`, a per-op
 *    priority_weight bumps a later-refreshed message ahead of an earlier
 *    sibling.
 *
 * Assertions correlate each ZSET id's score back to the decoded envelope's
 * `scoreBaseline` (and, under the band strategy, the `priorityWeight` offset)
 * via {@see KernelTestCase::envelopeScoreBaselineById()}. A transport bug that
 * preserved the sorted score set but scrambled id→envelope pairings would
 * break the per-row correlation here without surfacing on score sequence
 * alone.
 */
final class PriorityQueueTest extends KernelTestCase
{
    public function testOldestRefreshedAtFirstDispatchesInLowestScoreOrder(): void
    {
        $bus = \Pimcore::getContainer()->get('messenger.bus.default');
        $t30 = time() - 30;
        $t90 = time() - 90;
        $t60 = time() - 60;
        $bus->dispatch($this->newMessage('getTestSwrOnlyItemListing', $t30));
        $bus->dispatch($this->newMessage('getTestSwrOnlyItemListing', $t90));
        $bus->dispatch($this->newMessage('getTestSwrOnlyItemListing', $t60));

        $rows = $this->envelopeScoreBaselineById();
        self::assertCount(3, $rows);

        foreach ($rows as $id => $row) {
            self::assertEqualsWithDelta(
                $row['message']->scoreBaseline,
                $row['score'],
                1,
                'id ' . $id . ': score must match envelope scoreBaseline under default strategy'
            );
        }

        $scores = array_values(array_map(static fn (array $row): float => $row['score'], $rows));
        sort($scores);
        self::assertEqualsWithDelta($t90, $scores[0], 1, 'sorted-score regression floor: oldest scoreBaseline yields the lowest ZSET score');
    }

    public function testBandStrategyBumpsHigherWeightAheadOfEarlierSibling(): void
    {
        $bus = \Pimcore::getContainer()->get('messenger.bus.default');
        $t30 = time() - 30;
        $t10 = time() - 10;
        $bus->dispatch($this->newMessage('getTestSwrGuardedItemListing', $t30, 1));
        $bus->dispatch($this->newMessage('getTestSwrGuardedItemListing', $t10, 5));

        $rows = $this->envelopeScoreBaselineById();
        self::assertCount(2, $rows);

        foreach ($rows as $id => $row) {
            self::assertEqualsWithDelta(
                $row['message']->scoreBaseline - (($row['message']->priorityWeight ?? 1) * 60),
                $row['score'],
                1,
                'id ' . $id . ': score must match band-offset transform of envelope scoreBaseline and priorityWeight'
            );
        }
    }

    private function newMessage(string $operationName, int $refreshedAt, ?int $priorityWeight = null): PersistentRefreshMessage
    {
        $body = json_encode([
            'operationName' => $operationName,
            'query' => sprintf('query %s { %s { __typename } }', $operationName, $operationName),
            'variables' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new PersistentRefreshMessage('default', $body, $operationName, $refreshedAt, $priorityWeight);
    }
}
