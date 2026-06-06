<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Command;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\PersistentCacheRuleSweep;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PersistentCachePurgeInvalidCommandTest extends TestCase
{
    private function makeSweep(array $counts): PersistentCacheRuleSweep
    {
        $sweep = $this->createMock(PersistentCacheRuleSweep::class);
        $sweep->method('sweep')->willReturn($counts);

        return $sweep;
    }

    private function fullCounts(array $overrides = []): array
    {
        return array_merge(['scanned' => 0, 'evicted' => 0, 'skipped_malformed' => 0, 'evict_failed' => 0, 'not_enforced' => 0, 'passed' => 0, 'validate_failed' => 0], $overrides);
    }

    public function testExecutePrintsCounts(): void
    {
        $tester = new CommandTester(new PersistentCachePurgeInvalidCommand(
            $this->makeSweep($this->fullCounts(['scanned' => 10, 'evicted' => 3, 'skipped_malformed' => 1, 'passed' => 7, 'not_enforced' => 0]))
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
            $this->makeSweep($this->fullCounts())
        ));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testExecuteFailsWhenEvictFailed(): void
    {
        $tester = new CommandTester(new PersistentCachePurgeInvalidCommand(
            $this->makeSweep($this->fullCounts(['scanned' => 3, 'evicted' => 1, 'evict_failed' => 1, 'passed' => 1]))
        ));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
    }
}
