<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service\RequestValidation;

use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Logger;
use Pimcore\Maintenance\TaskInterface;

/**
 * Pimcore maintenance task that sweeps the persistent GraphQL cache against
 * the current request-validation rules, evicting entries that no longer
 * conform. Gated by a change-stamp so it only runs when the effective rules
 * have changed since the last sweep — keeping each maintenance cycle cheap.
 */
class PersistentCacheRuleSweepTask implements TaskInterface
{
    private const STAMP_CACHE_KEY = 'datahub_request_validation_sweep_stamp';

    /**
     * @param list<string> $enforcedClients
     */
    public function __construct(
        private readonly PersistentCacheRuleSweep $sweep,
        private readonly RulesLoader $rulesLoader,
        private readonly array $enforcedClients,
    ) {
    }

    public function execute(): void
    {
        $stamp = $this->computeStamp();
        if ($stamp === null) {
            return;
        }

        $stored = $this->stampLoad();
        if (is_string($stored) && $stored === $stamp) {
            return;
        }

        try {
            $counts = $this->sweep->sweep();
        } catch (\Throwable $e) {
            Logger::warning('datahub.request_validation.sweep_task_failed', ['exception' => $e]);

            return;
        }

        if ($counts['evict_failed'] > 0) {
            Logger::warning('datahub.request_validation.sweep_task_complete', $counts);
        } else {
            Logger::info('datahub.request_validation.sweep_task_complete', $counts);
        }

        if ($counts['scanned'] === 0 || $counts['evict_failed'] > 0) {
            return;
        }

        try {
            $this->stampSave($stamp);
        } catch (\Throwable $e) {
            Logger::warning('datahub.request_validation.sweep_stamp_save_failed', ['exception' => $e]);
        }
    }

    private function computeStamp(): ?string
    {
        if ($this->rulesLoader->load() === null) {
            return null;
        }

        $mtime = $this->rulesLoader->getLoadedMtime();
        if ($mtime === null) {
            return null;
        }

        $clientsHash = hash('sha256', implode(',', $this->enforcedClients));

        return hash('sha256', (string)$mtime . '|' . $clientsHash);
    }

    protected function stampLoad(): mixed
    {
        return \Pimcore\Cache::load(self::STAMP_CACHE_KEY);
    }

    protected function stampSave(string $stamp): void
    {
        \Pimcore\Cache::save($stamp, self::STAMP_CACHE_KEY, [PersistentOutputCacheService::TAG_COMMON], null, 1, true);
    }
}
