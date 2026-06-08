<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Command;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\PersistentCacheRuleSweep;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\SweepCounts;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PersistentCachePurgeInvalidCommandTest extends TestCase
{
    private function makeSweep(SweepCounts $counts): PersistentCacheRuleSweep
    {
        $sweep = $this->createMock(PersistentCacheRuleSweep::class);
        $sweep->method('sweep')->willReturn($counts);

        return $sweep;
    }

    private function counts(int $scanned = 0, int $evicted = 0, int $skippedMalformed = 0, int $evictFailed = 0, int $notEnforced = 0, int $passed = 0, int $validateFailed = 0): SweepCounts
    {
        return new SweepCounts($scanned, $evicted, $skippedMalformed, $evictFailed, $notEnforced, $passed, $validateFailed);
    }

    public function testExecutePrintsCounts(): void
    {
        $tester = new CommandTester(new PersistentCachePurgeInvalidCommand(
            $this->makeSweep($this->counts(scanned: 10, evicted: 3, skippedMalformed: 1, passed: 7))
        ));

        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('scanned=10', $display);
        self::assertStringContainsString('evicted=3', $display);
        self::assertStringContainsString('skipped_malformed=1', $display);
        self::assertStringContainsString('evict_failed=0', $display);
        self::assertStringContainsString('not_enforced=0', $display);
        self::assertStringContainsString('passed=7', $display);
        self::assertStringContainsString('validate_failed=0', $display);
    }

    public function testExecuteSucceedsWhenNoEntriesPresent(): void
    {
        $tester = new CommandTester(new PersistentCachePurgeInvalidCommand(
            $this->makeSweep($this->counts())
        ));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testExecuteFailsWhenEvictFailed(): void
    {
        $tester = new CommandTester(new PersistentCachePurgeInvalidCommand(
            $this->makeSweep($this->counts(scanned: 3, evicted: 1, evictFailed: 1, passed: 1))
        ));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
    }
}
