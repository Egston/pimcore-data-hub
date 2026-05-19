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
        int $weightBandSeconds = 60
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
            $weightBandSeconds
        );
    }

    public function testScoreForReturnsRefreshedAtWhenPersistentMessageHasIt(): void
    {
        $transport = $this->makeTransport();
        $msg = new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, null);
        self::assertSame(1234567890, $transport->scoreFor($msg));
    }

    public function testScoreForFallsBackToTimeWhenRefreshedAtNull(): void
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

    public function testScoreForUnderBandStrategyOffsetsRefreshedAtByWeightedBand(): void
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
}
