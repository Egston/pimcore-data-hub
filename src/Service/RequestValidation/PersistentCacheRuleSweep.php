<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service\RequestValidation;

use Pimcore\Bundle\DataHubBundle\GraphQL\Exception\ClientSafeException;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Psr\Log\LoggerInterface;

class PersistentCacheRuleSweep
{
    public function __construct(
        private readonly PersistentOutputCacheService $cache,
        private readonly RequestVariableValidator $validator,
        private readonly RulesLoader $rulesLoader,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Walk every entry in the persistent cache index and evict those that no
     * longer conform to the current request-validation rules.
     *
     * In dry-run mode the backend is never touched: a non-conforming entry is
     * still counted under `evicted` (read it as "would evict"), and `evictFailed`
     * stays zero.
     */
    public function sweep(bool $dryRun = false): SweepCounts
    {
        if ($this->rulesLoader->load() === null) {
            return new SweepCounts(
                scanned: 0,
                evicted: 0,
                skippedMalformed: 0,
                evictFailed: 0,
                notEnforced: 0,
                passed: 0,
                validateFailed: 0,
            );
        }

        $result = $this->cache->listAllEntries();
        $skipped = $result['skipped'];
        $scanned = 0;
        $evicted = 0;
        $evictFailed = 0;
        $notEnforced = 0;
        $passed = 0;
        $validateFailed = 0;

        foreach ($result['entries'] as $entry) {
            $client = $entry['client'];
            $operation = $entry['operation'];
            $canonical = $entry['canonical'];

            if (!$this->validator->isEnforced($client)) {
                ++$notEnforced;

                continue;
            }

            ++$scanned;

            $decoded = json_decode($canonical, true);
            if (!is_array($decoded)) {
                $this->logger?->warning('datahub.request_validation.sweep_undecodable_canonical', [
                    'client' => $client,
                    'operation' => $operation,
                ]);

                if ($this->evictOrCount($dryRun, $client, $canonical, $operation)) {
                    ++$evicted;
                } else {
                    ++$evictFailed;
                }

                continue;
            }

            // Fallback is the index operation: stored entries are keyed by operation.
            ['operationName' => $opName, 'variables' => $variables] = RequestVariableValidator::decodeRequestShape($canonical, $operation);

            try {
                $this->validator->assertRequest($client, null, $opName, $variables);
                ++$passed;
            } catch (ClientSafeException) {
                if ($this->evictOrCount($dryRun, $client, $canonical, $opName)) {
                    ++$evicted;
                } else {
                    ++$evictFailed;
                }
            } catch (\Throwable $e) {
                ++$validateFailed;
                $this->logger?->warning('datahub.request_validation.sweep_validate_failed', [
                    'client' => $client,
                    'operation' => $opName,
                    'exception' => $e,
                ]);
            }
        }

        return new SweepCounts(
            scanned: $scanned,
            evicted: $evicted,
            skippedMalformed: $skipped,
            evictFailed: $evictFailed,
            notEnforced: $notEnforced,
            passed: $passed,
            validateFailed: $validateFailed,
        );
    }

    /**
     * Remove one non-conforming entry, or in dry-run merely report it as a
     * would-evict. The caller owns the evicted/evictFailed counters.
     */
    private function evictOrCount(bool $dryRun, string $client, string $canonical, string $operation): bool
    {
        if ($dryRun) {
            return true;
        }

        try {
            if ($this->cache->evictEntry($client, $canonical, $operation)) {
                $this->logger?->info('datahub.request_validation.sweep_evicted', [
                    'client' => $client,
                    'operation' => $operation,
                ]);

                return true;
            }

            $this->logger?->warning('datahub.request_validation.sweep_evict_unconfirmed', [
                'client' => $client,
                'operation' => $operation,
            ]);

            return false;
        } catch (\Throwable $e) {
            $this->logger?->warning('datahub.request_validation.sweep_evict_failed', [
                'client' => $client,
                'operation' => $operation,
                'exception' => $e,
            ]);

            return false;
        }
    }
}
