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

namespace Pimcore\Bundle\DataHubBundle\Messenger;

use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;

/**
 * Demotes Messenger retry warnings for *recoverable* exceptions to DEBUG.
 *
 * Symfony's {@see \Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener}
 * logs every requeue at WARNING with the throwable attached (a full stack
 * trace). For a {@see RecoverableExceptionInterface} that level is wrong: the
 * handler is explicitly signalling "expected, retry me" — recoverable
 * exceptions even bypass `max_retries` — so the requeue is normal control
 * flow, not a fault.
 *
 * The dominant case is the refresh worker's per-op-name lock: at
 * `replicas > 1` a second consumer that meets an in-flight op's lock requeues
 * once per poll until the lock frees ({@see \Pimcore\Bundle\DataHubBundle\MessageHandler\PersistentRefreshMessageHandler}).
 * That is a healthy outcome of the parallelism contract, but at WARNING+trace
 * it floods the logs and reads as an incident.
 *
 * Decorates the `messenger` channel logger, so it applies wherever that
 * channel is injected. Demotion is deliberately stricter than the retry
 * listener's requeue test: a wrapped {@see HandlerFailedException} is demoted
 * only when *every* nested exception is recoverable, so a genuine fault riding
 * alongside a recoverable one keeps its WARNING + trace — retrying and
 * logging-loud are separate decisions. To preserve the *rate* signal the
 * demotion would otherwise hide, a periodic INFO summary reports how many
 * recoverable requeues were demoted per window.
 */
final class RecoverableRetryLogger extends AbstractLogger
{
    private const SUMMARY_INTERVAL_SECONDS = 60;

    private readonly ClockInterface $clock;

    private int $demotedInWindow = 0;

    private ?int $windowStartedAt = null;

    public function __construct(private readonly LoggerInterface $inner, ?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new NativeClock();
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::WARNING && self::isRecoverable($context['exception'] ?? null)) {
            // Drop the throwable: an expected requeue has nothing to debug, and
            // keeping it would re-attach the stack trace the demotion exists to remove.
            unset($context['exception']);
            $this->inner->debug($message, $context);
            $this->recordDemotion();

            return;
        }

        $this->inner->log($level, $message, $context);
    }

    /**
     * Keep a counted, rate-limited residue of the demoted requeues so a
     * pathological contention storm (e.g. a lock that never frees) still
     * surfaces at INFO even when DEBUG is below the production threshold. The
     * counter is per-process, so under N replicas a sustained storm shows as a
     * steady INFO drumbeat on every consumer.
     */
    private function recordDemotion(): void
    {
        ++$this->demotedInWindow;
        $now = $this->clock->now()->getTimestamp();

        if ($this->windowStartedAt === null) {
            $this->windowStartedAt = $now;

            return;
        }

        $elapsed = $now - $this->windowStartedAt;
        if ($elapsed < self::SUMMARY_INTERVAL_SECONDS) {
            return;
        }

        $this->inner->info(
            sprintf(
                'datahub.recoverable_retry: %d recoverable refresh requeue(s) demoted to debug in the last %ds',
                $this->demotedInWindow,
                $elapsed,
            ),
            ['demoted' => $this->demotedInWindow, 'window_seconds' => $elapsed],
        );

        $this->demotedInWindow = 0;
        $this->windowStartedAt = $now;
    }

    private static function isRecoverable(mixed $throwable): bool
    {
        if (!$throwable instanceof \Throwable) {
            return false;
        }

        if ($throwable instanceof RecoverableExceptionInterface) {
            return true;
        }

        // The retry listener requeues if *any* wrapped exception is recoverable;
        // demote only when *all* are, so a co-wrapped genuine fault stays loud.
        if ($throwable instanceof HandlerFailedException) {
            $wrapped = $throwable->getWrappedExceptions();
            if ($wrapped === []) {
                return false;
            }
            foreach ($wrapped as $nested) {
                if (!$nested instanceof RecoverableExceptionInterface) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
