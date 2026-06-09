<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service\RequestValidation;

/**
 * Counters from one sweep pass. `scanned` excludes `notEnforced` and `skippedMalformed`;
 * every scanned entry lands in exactly one bucket, so
 * `scanned === evicted + evictFailed + passed + validateFailed`. `evicted` spans both the
 * undecodable-canonical and rule-rejected paths; in normal mode it counts confirmed removals,
 * in dry-run mode it counts would-be removals (nothing is touched).
 * `evictFailed` absorbs both a throwing backend and an unconfirmed (false-return) removal.
 */
final readonly class SweepCounts
{
    public function __construct(
        public int $scanned,
        public int $evicted,
        public int $skippedMalformed,
        public int $evictFailed,
        public int $notEnforced,
        public int $passed,
        public int $validateFailed,
    ) {
        if (min($scanned, $evicted, $skippedMalformed, $evictFailed, $notEnforced, $passed, $validateFailed) < 0) {
            throw new \InvalidArgumentException('SweepCounts values must be non-negative');
        }
        if ($scanned !== $evicted + $evictFailed + $passed + $validateFailed) {
            throw new \InvalidArgumentException(
                sprintf(
                    'SweepCounts invariant violated: scanned(%d) !== evicted(%d) + evictFailed(%d) + passed(%d) + validateFailed(%d)',
                    $scanned,
                    $evicted,
                    $evictFailed,
                    $passed,
                    $validateFailed,
                )
            );
        }
    }

    /**
     * @return array<string, int>
     */
    public function toLogContext(): array
    {
        return [
            'scanned' => $this->scanned,
            'evicted' => $this->evicted,
            'skipped_malformed' => $this->skippedMalformed,
            'evict_failed' => $this->evictFailed,
            'not_enforced' => $this->notEnforced,
            'passed' => $this->passed,
            'validate_failed' => $this->validateFailed,
        ];
    }

    public function summaryLine(): string
    {
        $tag = $this->evictFailed > 0 ? 'comment' : 'info';
        $pairs = [];
        foreach ($this->toLogContext() as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return sprintf('<%1$s>Sweep complete: %2$s</%1$s>', $tag, implode(' ', $pairs));
    }

    public function isEffectiveSweep(): bool
    {
        return $this->scanned > 0 && $this->evictFailed === 0;
    }

    /**
     * Whether the sweep completed without any unresolved entries. Gates the
     * change-stamp advance: false means some entries could not be assessed or
     * removed, so the next cycle must retry.
     */
    public function isCleanCompletion(): bool
    {
        return $this->evictFailed === 0 && $this->validateFailed === 0;
    }
}
