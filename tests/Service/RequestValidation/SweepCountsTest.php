<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service\RequestValidation;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\SweepCounts;

final class SweepCountsTest extends TestCase
{
    public function testToLogContextReturnsExactSevenSnakeCaseKeys(): void
    {
        $counts = new SweepCounts(9, 3, 1, 0, 2, 6, 0);

        $ctx = $counts->toLogContext();

        self::assertSame([
            'scanned' => 9,
            'evicted' => 3,
            'skipped_malformed' => 1,
            'evict_failed' => 0,
            'not_enforced' => 2,
            'passed' => 6,
            'validate_failed' => 0,
        ], $ctx);
    }

    public function testSummaryLineMatchesCommandOutputWhenNoEvictFailed(): void
    {
        $counts = new SweepCounts(9, 3, 1, 0, 2, 6, 0);

        $line = $counts->summaryLine();

        self::assertSame(
            '<info>Sweep complete: scanned=9 evicted=3 skipped_malformed=1 evict_failed=0 not_enforced=2 passed=6 validate_failed=0</info>',
            $line,
        );
    }

    public function testSummaryLineUsesCommentTagWhenEvictFailed(): void
    {
        $counts = new SweepCounts(3, 1, 0, 1, 0, 1, 0);

        $line = $counts->summaryLine();

        self::assertSame(
            '<comment>Sweep complete: scanned=3 evicted=1 skipped_malformed=0 evict_failed=1 not_enforced=0 passed=1 validate_failed=0</comment>',
            $line,
        );
    }

    /**
     * @return array<string, array{scanned: int, evictFailed: int, passed: int, expected: bool}>
     */
    public static function isEffectiveSweepProvider(): array
    {
        return [
            'scanned > 0 and no evict failures' => ['scanned' => 1, 'evictFailed' => 0, 'passed' => 1, 'expected' => true],
            'scanned = 0' => ['scanned' => 0, 'evictFailed' => 0, 'passed' => 0, 'expected' => false],
            'scanned > 0 but evict failed' => ['scanned' => 3, 'evictFailed' => 1, 'passed' => 2, 'expected' => false],
            'both zero' => ['scanned' => 1, 'evictFailed' => 1, 'passed' => 0, 'expected' => false],
        ];
    }

    /**
     * @dataProvider isEffectiveSweepProvider
     */
    public function testIsEffectiveSweep(int $scanned, int $evictFailed, int $passed, bool $expected): void
    {
        $counts = new SweepCounts($scanned, 0, 0, $evictFailed, 0, $passed, 0);

        self::assertSame($expected, $counts->isEffectiveSweep());
    }

    /**
     * @return array<string, array{evictFailed: int, validateFailed: int, expected: bool}>
     */
    public static function isCleanCompletionProvider(): array
    {
        return [
            'no failures' => ['evictFailed' => 0, 'validateFailed' => 0, 'expected' => true],
            'evict failed' => ['evictFailed' => 1, 'validateFailed' => 0, 'expected' => false],
            'validate failed' => ['evictFailed' => 0, 'validateFailed' => 1, 'expected' => false],
            'both failed' => ['evictFailed' => 1, 'validateFailed' => 1, 'expected' => false],
            'empty sweep' => ['evictFailed' => 0, 'validateFailed' => 0, 'expected' => true],
        ];
    }

    /**
     * @dataProvider isCleanCompletionProvider
     */
    public function testIsCleanCompletion(int $evictFailed, int $validateFailed, bool $expected): void
    {
        $scanned = $evictFailed + $validateFailed;
        $counts = new SweepCounts($scanned, 0, 0, $evictFailed, 0, 0, $validateFailed);

        self::assertSame($expected, $counts->isCleanCompletion());
    }

    public function testConstructorRejectsNegativeCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SweepCounts(0, -1, 0, 0, 0, 0, 0);
    }

    public function testConstructorRejectsInvariantViolation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SweepCounts(5, 2, 0, 0, 0, 2, 0);
    }
}
