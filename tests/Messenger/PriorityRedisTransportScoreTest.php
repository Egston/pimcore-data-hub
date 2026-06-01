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

namespace Pimcore\Bundle\DataHubBundle\Tests\Messenger;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Messenger\PriorityRedisTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

require_once __DIR__ . '/PriorityRedisTransportTest.php';

final class PriorityRedisTransportScoreTest extends TestCase
{
    private function makeTransport(
        string $priorityStrategy = 'oldest_refreshed_at_first',
        int $weightBandSeconds = 60,
        int $readTriggerOffsetSeconds = 86400
    ): PriorityRedisTransport {
        return new PriorityRedisTransport(
            new FakeRedis(),
            new PhpSerializer(),
            'z',
            'm',
            'i',
            600,
            5,
            $priorityStrategy,
            $weightBandSeconds,
            $readTriggerOffsetSeconds
        );
    }

    public function testScoreForReturnsScoreBaselineWhenPersistentMessageHasIt(): void
    {
        $transport = $this->makeTransport();
        $msg = new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, null);
        self::assertSame(1234567890, $transport->scoreFor($msg));
    }

    public function testScoreForFallsBackToTimeWhenScoreBaselineNull(): void
    {
        $transport = $this->makeTransport();
        $msg = new PersistentRefreshMessage('c1', '{}', 'Op1', null, null);
        $now = time();
        $score = $transport->scoreFor($msg);
        self::assertGreaterThanOrEqual($now, $score);
        self::assertLessThanOrEqual($now + 5, $score);
    }

    public function testScoreForFallsBackToTimeForNonPersistentMessages(): void
    {
        $transport = $this->makeTransport();
        $other = new \stdClass();
        $now = time();
        $score = $transport->scoreFor($other);
        self::assertGreaterThanOrEqual($now, $score);
        self::assertLessThanOrEqual($now + 5, $score);
    }

    public function testScoreForUnderBandStrategyOffsetsScoreBaselineByWeightedBand(): void
    {
        $transport = $this->makeTransport('oldest_refreshed_at_first_with_weight_bands', 60);
        $msg = new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, 7);
        self::assertSame(1234567890 - 420, $transport->scoreFor($msg));
    }

    public function testScoreForUnderBandStrategyUsesNeutralWeightWhenNull(): void
    {
        $transport = $this->makeTransport('oldest_refreshed_at_first_with_weight_bands', 60);
        $msg = new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, null);
        self::assertSame(1234567890 - 60, $transport->scoreFor($msg));
    }

    public function testScoreForUnderBandStrategyWithZeroBandSecondsReducesToBaseScore(): void
    {
        $transport = $this->makeTransport('oldest_refreshed_at_first_with_weight_bands', 0);
        $msg = new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, 7);
        self::assertSame(1234567890, $transport->scoreFor($msg));
    }

    public function testScoreForUnderDisabledStrategyFallsBackToTime(): void
    {
        $transport = $this->makeTransport('disabled', 60);
        $msg = new PersistentRefreshMessage('c1', '{}', 'Op1', null, null);
        $now = time();
        $score = $transport->scoreFor($msg);
        self::assertGreaterThanOrEqual($now, $score);
        self::assertLessThanOrEqual($now + 5, $score);
    }

    public function testScoreForUnknownStrategyThrows(): void
    {
        $transport = $this->makeTransport('bogus_strategy', 60);
        $this->expectException(\LogicException::class);
        $transport->scoreFor(new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, 1));
    }

    public function testReadTriggeredMessageSubtractsOffsetUnderPlainStrategy(): void
    {
        $transport = $this->makeTransport('oldest_refreshed_at_first', 60, 86400);
        $read = new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, null, null, true);
        self::assertSame(1234567890 - 86400, $transport->scoreFor($read));
    }

    public function testReadTriggeredMessageSubtractsOffsetUnderBandStrategy(): void
    {
        $transport = $this->makeTransport('oldest_refreshed_at_first_with_weight_bands', 60, 86400);
        $read = new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, 7, null, true);
        self::assertSame(1234567890 - 420 - 86400, $transport->scoreFor($read));
    }

    public function testWarmMessageDoesNotSubtractOffset(): void
    {
        $transport = $this->makeTransport('oldest_refreshed_at_first', 60, 86400);
        $warm = new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, null, null, false);
        self::assertSame(1234567890, $transport->scoreFor($warm));
    }

    public function testDatedMessageScoreIsDeliverAtVerbatim(): void
    {
        $deliverAt = time() + 3600;
        $transport = $this->makeTransport('oldest_refreshed_at_first', 60, 86400);
        $dated = new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, null, $deliverAt);
        self::assertSame($deliverAt, $transport->scoreFor($dated));
    }

    public function testDatedMessageScoreIsDeliverAtVerbatimUnderBandStrategy(): void
    {
        $deliverAt = time() + 21600;
        $transport = $this->makeTransport('oldest_refreshed_at_first_with_weight_bands', 60, 86400);
        // Non-null priorityWeight=7 with weightBandSeconds=60 would yield a -420
        // band offset absent the deliverAt guard; assert deliverAt wins anyway.
        $dated = new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, 7, $deliverAt);
        self::assertSame($deliverAt, $transport->scoreFor($dated));
    }

    public function testReadWithLowestWeightStillScoresBelowHighestWeightWarm(): void
    {
        // Hard-guarantee proof at the default offset: a read carrying the
        // lowest plausible warm-weight (1) sorts strictly below a warm carrying
        // a high weight (10), at the same scoreBaseline, under weight bands.
        $transport = $this->makeTransport('oldest_refreshed_at_first_with_weight_bands', 60, 86400);
        $read = new PersistentRefreshMessage('c1', '{}', 'OpRead', 1234567890, 1, null, true);
        $warm = new PersistentRefreshMessage('c1', '{}', 'OpWarm', 1234567890, 10, null, false);
        self::assertLessThan($transport->scoreFor($warm), $transport->scoreFor($read));
    }

    public function testDefaultStrategyIgnoresPriorityWeight(): void
    {
        $transport = $this->makeTransport('oldest_refreshed_at_first');
        $weighted = new PersistentRefreshMessage('c1', '{}', 'Op1', 1700000000, 7);
        $unweighted = new PersistentRefreshMessage('c1', '{}', 'Op2', 1700000000, null);
        self::assertSame(1700000000, $transport->scoreFor($weighted));
        self::assertSame(1700000000, $transport->scoreFor($unweighted));
    }

    public function testReadTriggeredWithDeliverAtThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, null, time() + 21600, true);
    }
}
