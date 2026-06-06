<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Lock;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockInterface;

final class LockSignalRefresherTest extends TestCase
{
    protected function setUp(): void
    {
        LockSignalRefresher::disarm();
    }

    protected function tearDown(): void
    {
        LockSignalRefresher::disarm();
    }

    private function makeLock(bool $isAcquired = true, int &$refreshCount = 0): LockInterface
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->method('isAcquired')->willReturn($isAcquired);
        $lock->method('refresh')->willReturnCallback(function () use (&$refreshCount) {
            $refreshCount++;
        });

        return $lock;
    }

    public function testDisarmIsIdempotentWhenNotArmed(): void
    {
        $this->assertFalse(LockSignalRefresher::isArmed());
        LockSignalRefresher::disarm();
        $this->assertFalse(LockSignalRefresher::isArmed());
    }

    public function testIsArmedAfterArm(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl not available');
        }

        $lock = $this->makeLock();
        LockSignalRefresher::arm($lock, 60, 30);
        $this->assertTrue(LockSignalRefresher::isArmed());
    }

    public function testDisarmClearsArmedState(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl not available');
        }

        $lock = $this->makeLock();
        LockSignalRefresher::arm($lock, 60, 30);
        $this->assertTrue(LockSignalRefresher::isArmed());
        LockSignalRefresher::disarm();
        $this->assertFalse(LockSignalRefresher::isArmed());
    }

    public function testHandleRefreshSignalSelfClearsWhenLockNotAcquired(): void
    {
        $lock = $this->makeLock(false);

        // Manually set up the static slot without going through arm() (pcntl may not be available).
        // Reach the handler directly to test the isAcquired self-clear path.
        if (function_exists('pcntl_alarm')) {
            LockSignalRefresher::arm($lock, 60, 30);
        }

        LockSignalRefresher::handleRefreshSignal();
        $this->assertFalse(LockSignalRefresher::isArmed());
    }

    public function testHandleRefreshSignalNoOpWhenNotArmed(): void
    {
        $this->assertFalse(LockSignalRefresher::isArmed());
        LockSignalRefresher::handleRefreshSignal();
        $this->assertFalse(LockSignalRefresher::isArmed());
    }

    public function testHandleRefreshSignalDisarmsAfterConsecutiveFailures(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->method('isAcquired')->willReturn(true);
        $lock->method('refresh')->willThrowException(new \RuntimeException('Redis gone'));

        if (function_exists('pcntl_alarm')) {
            LockSignalRefresher::arm($lock, 60, 30);
        } else {
            // Drive directly without pcntl: prime the slot via reflection.
            // If arm() silently returns (no pcntl), the handler immediately exits at the null guard.
            // Nothing to test in that environment beyond the disarm idempotency covered above.
            $this->markTestSkipped('pcntl not available; failure-threshold path unreachable');
        }

        LockSignalRefresher::handleRefreshSignal();
        $this->assertTrue(LockSignalRefresher::isArmed(), 'Still armed after 1 failure');

        LockSignalRefresher::handleRefreshSignal();
        $this->assertTrue(LockSignalRefresher::isArmed(), 'Still armed after 2 failures');

        LockSignalRefresher::handleRefreshSignal();
        $this->assertFalse(LockSignalRefresher::isArmed(), 'Must disarm after 3 consecutive failures');
    }
}
