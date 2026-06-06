<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Lock;

use Pimcore\Logger;
use Symfony\Component\Lock\LockInterface;

/**
 * Periodic SIGALRM refresher for long-running tasks holding a Symfony Lock
 * (and optionally a Pimcore cache marker). One slot per PHP process —
 * pcntl handlers can't capture closures usefully, so state lives in statics
 * and the handler runs as a class-callable.
 */
final class LockSignalRefresher
{
    private static ?LockInterface $activeLock = null;

    private static int $consecutiveFailures = 0;

    private const MAX_CONSECUTIVE_FAILURES = 3;

    private static ?string $activeMarkerKey = null;

    private static array $activeMarkerTags = [];

    private static int $activeTtl = 0;

    private static int $activeInterval = 0;

    public static function arm(
        LockInterface $lock,
        int $ttl,
        int $interval,
        ?string $markerKey = null,
        array $markerTags = []
    ): void {
        if ($interval < 1) {
            return;
        }
        if (!function_exists('pcntl_async_signals')
            || !function_exists('pcntl_alarm')
            || !function_exists('pcntl_signal')
            || !defined('SIGALRM')) {
            return;
        }

        if (self::$activeLock !== null) {
            Logger::warning('LockSignalRefresher: re-arm without prior disarm; dropping previous slot');
            self::disarm();
        }

        try {
            self::$activeLock = $lock;
            self::$consecutiveFailures = 0;
            self::$activeMarkerKey = $markerKey;
            self::$activeMarkerTags = $markerTags;
            self::$activeTtl = $ttl;
            self::$activeInterval = $interval;

            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, [self::class, 'handleRefreshSignal']);
            pcntl_alarm($interval);

            Logger::debug(sprintf(
                'LockSignalRefresher: armed (pid=%d, marker=%s, ttl=%ds, interval=%ds)',
                function_exists('getmypid') ? (int) getmypid() : -1,
                $markerKey ?? '-',
                $ttl,
                $interval
            ));
        } catch (\Throwable $e) {
            self::disarm();
        }
    }

    /** @internal pcntl_signal handler; not for direct invocation. */
    public static function handleRefreshSignal(): void
    {
        $lock = self::$activeLock;
        if ($lock === null) {
            return;
        }

        $pid = function_exists('getmypid') ? (int) getmypid() : -1;

        try {
            // Released externally (save path, safety-net listener, destructor) — stop ticking.
            if (!$lock->isAcquired()) {
                Logger::debug(sprintf('LockSignalRefresher: self-clear (pid=%d)', $pid));
                self::disarm();

                return;
            }

            $lock->refresh(self::$activeTtl);
            if (self::$activeMarkerKey !== null) {
                \Pimcore\Cache::save(
                    1,
                    self::$activeMarkerKey,
                    self::$activeMarkerTags,
                    self::$activeTtl,
                    1,
                    true
                );
            }
            self::$consecutiveFailures = 0;
            Logger::debug(sprintf(
                'LockSignalRefresher: tick (pid=%d, marker=%s)',
                $pid,
                self::$activeMarkerKey ?? '-'
            ));
        } catch (\Throwable $e) {
            self::$consecutiveFailures++;
            Logger::warning(sprintf(
                'LockSignalRefresher: refresh failed (pid=%d, attempt=%d): %s',
                $pid,
                self::$consecutiveFailures,
                $e->getMessage()
            ));
            if (self::$consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                Logger::warning(sprintf(
                    'LockSignalRefresher: disarming after %d consecutive failures (pid=%d)',
                    self::$consecutiveFailures,
                    $pid
                ));
                self::disarm();

                return;
            }
        }

        if (self::$activeInterval > 0 && function_exists('pcntl_alarm')) {
            pcntl_alarm(self::$activeInterval);
        }
    }

    public static function disarm(): void
    {
        $wasArmed = self::$activeLock !== null;

        self::$activeLock = null;
        self::$activeMarkerKey = null;
        self::$activeMarkerTags = [];
        self::$activeTtl = 0;
        self::$activeInterval = 0;
        self::$consecutiveFailures = 0;

        if (function_exists('pcntl_alarm')) {
            try {
                pcntl_alarm(0);
            } catch (\Throwable $e) {
            }
        }
        if (function_exists('pcntl_signal') && defined('SIGALRM')) {
            try {
                pcntl_signal(SIGALRM, SIG_IGN);
            } catch (\Throwable $e) {
            }
        }

        if ($wasArmed) {
            Logger::debug(sprintf(
                'LockSignalRefresher: disarmed (pid=%d)',
                function_exists('getmypid') ? (int) getmypid() : -1
            ));
        }
    }

    public static function isArmed(): bool
    {
        return self::$activeLock !== null;
    }
}
