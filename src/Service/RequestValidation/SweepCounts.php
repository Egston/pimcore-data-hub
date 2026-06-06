<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service\RequestValidation;

/**
 * Counters from one sweep pass. `scanned` excludes `notEnforced` and `skippedMalformed`;
 * `evicted` spans both the undecodable-canonical and rule-rejected paths.
 */
readonly class SweepCounts
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

        return sprintf(
            '<%1$s>Sweep complete: scanned=%2$d evicted=%3$d skipped_malformed=%4$d evict_failed=%5$d not_enforced=%6$d passed=%7$d validate_failed=%8$d</%1$s>',
            $tag,
            $this->scanned,
            $this->evicted,
            $this->skippedMalformed,
            $this->evictFailed,
            $this->notEnforced,
            $this->passed,
            $this->validateFailed,
        );
    }

    public function isEffectiveSweep(): bool
    {
        return $this->scanned > 0 && $this->evictFailed === 0;
    }
}
