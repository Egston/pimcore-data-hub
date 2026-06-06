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
     */
    public function sweep(): SweepCounts
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

                try {
                    $this->cache->evictEntry($client, $canonical, $operation);
                    ++$evicted;
                } catch (\Throwable $e) {
                    ++$evictFailed;
                    $this->logger?->warning('datahub.request_validation.sweep_evict_failed', [
                        'client' => $client,
                        'operation' => $operation,
                        'exception' => $e,
                    ]);
                }

                continue;
            }

            // Fallback is the index operation: stored entries are keyed by operation.
            ['operationName' => $opName, 'variables' => $variables] = RequestVariableValidator::decodeRequestShape($canonical, $operation);

            try {
                $this->validator->assertRequest($client, null, $opName, $variables);
                ++$passed;
            } catch (ClientSafeException) {
                try {
                    $this->cache->evictEntry($client, $canonical, $opName);
                    ++$evicted;
                    $this->logger?->info('datahub.request_validation.sweep_evicted', [
                        'client' => $client,
                        'operation' => $opName,
                    ]);
                } catch (\Throwable $e) {
                    ++$evictFailed;
                    $this->logger?->warning('datahub.request_validation.sweep_evict_failed', [
                        'client' => $client,
                        'operation' => $opName,
                        'exception' => $e,
                    ]);
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
}
