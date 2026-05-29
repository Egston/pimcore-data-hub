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
use Pimcore\Bundle\DataHubBundle\Messenger\RecoverableRetryLogger;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * @internal
 */
final class RecoverableRetryLoggerTest extends TestCase
{
    public function testWarningWithDirectRecoverableExceptionIsDemotedAndTraceStripped(): void
    {
        [$spy, $logger] = $this->makeLogger();

        $logger->warning('lock contended; requeue', [
            'class' => 'SomeMessage',
            'exception' => new RecoverableMessageHandlingException('lock contended'),
        ]);

        self::assertCount(1, $spy->records);
        self::assertSame(LogLevel::DEBUG, $spy->records[0]['level']);
        self::assertSame('lock contended; requeue', $spy->records[0]['message']);
        self::assertArrayNotHasKey('exception', $spy->records[0]['context']);
        self::assertSame('SomeMessage', $spy->records[0]['context']['class']);
    }

    public function testWarningWithWrappedRecoverableExceptionIsDemoted(): void
    {
        [$spy, $logger] = $this->makeLogger();

        $wrapped = new HandlerFailedException(
            new Envelope(new \stdClass()),
            [new RecoverableMessageHandlingException('lock contended')]
        );

        $logger->warning('Error thrown while handling message', ['exception' => $wrapped]);

        self::assertCount(1, $spy->records);
        self::assertSame(LogLevel::DEBUG, $spy->records[0]['level']);
        self::assertArrayNotHasKey('exception', $spy->records[0]['context']);
    }

    public function testWarningWithOrdinaryExceptionIsLeftUntouched(): void
    {
        [$spy, $logger] = $this->makeLogger();

        $boom = new \RuntimeException('genuine failure');
        $logger->warning('Error thrown while handling message', ['exception' => $boom]);

        self::assertCount(1, $spy->records);
        self::assertSame(LogLevel::WARNING, $spy->records[0]['level']);
        self::assertSame($boom, $spy->records[0]['context']['exception']);
    }

    public function testWarningWithNoExceptionIsLeftUntouched(): void
    {
        [$spy, $logger] = $this->makeLogger();

        $logger->warning('plain warning', ['class' => 'X']);

        self::assertCount(1, $spy->records);
        self::assertSame(LogLevel::WARNING, $spy->records[0]['level']);
    }

    public function testNonWarningLevelWithRecoverableExceptionIsNotDemoted(): void
    {
        [$spy, $logger] = $this->makeLogger();

        $logger->critical('Removing from transport', [
            'exception' => new RecoverableMessageHandlingException('x'),
        ]);

        self::assertCount(1, $spy->records);
        // Only WARNING is demoted; other levels pass through verbatim, exception kept.
        self::assertSame(LogLevel::CRITICAL, $spy->records[0]['level']);
        self::assertArrayHasKey('exception', $spy->records[0]['context']);
    }

    public function testWarningWithMixedWrappedExceptionsKeepsTheFaultLoud(): void
    {
        [$spy, $logger] = $this->makeLogger();

        $fatal = new \RuntimeException('genuine failure');
        $wrapped = new HandlerFailedException(
            new Envelope(new \stdClass()),
            [new RecoverableMessageHandlingException('lock contended'), $fatal],
        );

        $logger->warning('Error thrown while handling message', ['exception' => $wrapped]);

        self::assertCount(1, $spy->records);
        // A genuine fault rides alongside the recoverable one — must not be demoted.
        self::assertSame(LogLevel::WARNING, $spy->records[0]['level']);
        self::assertSame($wrapped, $spy->records[0]['context']['exception']);
    }

    public function testRecoverableRequeuesEmitRateLimitedSummaryAfterInterval(): void
    {
        $clock = new MockClock('2024-01-01T00:00:00+00:00');
        [$spy, $logger] = $this->makeLogger($clock);

        $requeue = static fn () => $logger->warning('lock contended; requeue', [
            'exception' => new RecoverableMessageHandlingException('lock contended'),
        ]);

        $requeue();          // first demotion opens the window — no summary yet
        $clock->sleep(30);
        $requeue();          // 30s in, still below the interval
        self::assertSame([], $this->recordsAt($spy, LogLevel::INFO));

        $clock->sleep(31);   // 61s since the window opened
        $requeue();          // crosses the interval → emits the summary

        $infos = $this->recordsAt($spy, LogLevel::INFO);
        self::assertCount(1, $infos);
        self::assertStringContainsString('3 recoverable refresh requeue(s)', $infos[0]['message']);
        self::assertSame(3, $infos[0]['context']['demoted']);
        // Every requeue was still demoted to debug — the summary is additive, not a replacement.
        self::assertCount(3, $this->recordsAt($spy, LogLevel::DEBUG));
    }

    public function testRecoverableRequeuesBelowIntervalEmitNoSummary(): void
    {
        $clock = new MockClock('2024-01-01T00:00:00+00:00');
        [$spy, $logger] = $this->makeLogger($clock);

        for ($i = 0; $i < 5; ++$i) {
            $logger->warning('lock contended; requeue', [
                'exception' => new RecoverableMessageHandlingException('lock contended'),
            ]);
            $clock->sleep(10); // 5 requeues spread over 40s — never crosses 60s
        }

        self::assertSame([], $this->recordsAt($spy, LogLevel::INFO));
        self::assertCount(5, $this->recordsAt($spy, LogLevel::DEBUG));
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    private function recordsAt(object $spy, string $level): array
    {
        return array_values(array_filter($spy->records, static fn (array $r): bool => $r['level'] === $level));
    }

    /**
     * @return array{0: object, 1: RecoverableRetryLogger}
     */
    private function makeLogger(?ClockInterface $clock = null): array
    {
        $spy = new class() extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
            }
        };

        return [$spy, new RecoverableRetryLogger($spy, $clock)];
    }
}
