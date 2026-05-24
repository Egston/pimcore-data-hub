<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\DataHubBundle\Tests;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\PimcoreDataHubBundle;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exercises the boot-time diagnostic logic in PimcoreDataHubBundle::runBootDiagnostics().
 * Each test injects a spy LoggerInterface to observe which log level was called.
 */
final class PimcoreDataHubBundleBootTest extends TestCase
{
    private function makeSpy(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    private function runDiagnostics(array $graphql, bool $classifierPresent, LoggerInterface $logger): void
    {
        $bundle = new PimcoreDataHubBundle();
        $bundle->runBootDiagnostics($graphql, $classifierPresent, $logger);
    }

    public function testEmptyConfigProducesNoLogCalls(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('error');
        $logger->expects(self::never())->method('info');

        $this->runDiagnostics(['in_progress_queries' => [], 'operations' => []], true, $logger);
    }

    public function testInProgressQueriesPopulatedEmitsInfoDeprecation(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::once())->method('info')
            ->with(self::stringContains('in_progress_queries_deprecated'));
        $logger->expects(self::never())->method('error');

        $this->runDiagnostics(
            ['in_progress_queries' => ['OpA'], 'operations' => []],
            true,
            $logger
        );
    }

    public function testHerdGuardAliasKeysPopulatedEmitsDeprecationWarning(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::once())->method('warning')
            ->with(self::stringContains('herd_guard_keys_deprecated'));
        $logger->expects(self::never())->method('error');

        $this->runDiagnostics(
            ['in_progress_queries' => [], 'operations' => [], 'in_progress_ttl' => 30],
            true,
            $logger
        );
    }

    public function testEmptyStringAliasKeyDoesNotTriggerDeprecationWarning(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('error');

        $this->runDiagnostics(
            ['in_progress_queries' => [], 'operations' => [], 'in_progress_ttl' => ''],
            true,
            $logger
        );
    }

    public function testStringZeroAliasKeyTriggersDeprecationWarning(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::once())->method('warning')
            ->with(self::stringContains('herd_guard_keys_deprecated'));
        $logger->expects(self::never())->method('error');

        $this->runDiagnostics(
            ['in_progress_queries' => [], 'operations' => [], 'in_progress_ttl' => '0'],
            true,
            $logger
        );
    }

    public function testPersistentOutputCacheGuardOnlySentinelEmitsWarning(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::once())->method('warning')
            ->with(self::stringContains('persistent_output_cache_guard_only_removed'));
        $logger->expects(self::never())->method('error');

        $this->runDiagnostics(
            [
                'in_progress_queries' => [],
                'operations' => [],
                '_persistent_output_cache_guard_only_set' => true,
            ],
            true,
            $logger
        );
    }

    public function testHerdGuardAliasConflictSentinelEmitsWarning(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::once())->method('warning')
            ->with(self::stringContains('herd_guard_alias_conflict'));
        $logger->expects(self::never())->method('error');

        $this->runDiagnostics(
            [
                'in_progress_queries' => [],
                'operations' => [],
                '_herd_guard_alias_conflicts' => ['in_progress_ttl=30 overridden by herd_guard_ttl=90'],
            ],
            true,
            $logger
        );
    }

    public function testInProgressOperationsConflictSentinelEmitsWarning(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::once())->method('warning')
            ->with(self::stringContains('operations_in_progress_conflict'));
        $logger->expects(self::never())->method('error');

        $this->runDiagnostics(
            [
                'in_progress_queries' => ['OpA'],
                'operations' => ['OpA' => ['tier' => 'swr_only', 'granularity' => 'list']],
                '_in_progress_operations_conflicts' => ['OpA'],
            ],
            true,
            $logger
        );
    }

    public function testHerdGuardEnabledWithNoClassifierEmitsError(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::once())->method('error')
            ->with(self::stringContains('herd_guard_no_classifier'));

        $this->runDiagnostics(
            ['in_progress_queries' => [], 'operations' => [], 'herd_guard_enabled' => true],
            false,
            $logger
        );
    }

    public function testHerdGuardEnabledWithClassifierPresentEmitsNoError(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::never())->method('error');

        $this->runDiagnostics(
            ['in_progress_queries' => [], 'operations' => [], 'herd_guard_enabled' => true],
            true,
            $logger
        );
    }

    public function testHerdGuardEnabledViaAliasWithNoClassifierEmitsError(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::atLeastOnce())->method('warning');
        $logger->expects(self::once())->method('error')
            ->with(self::stringContains('herd_guard_no_classifier'));

        $this->runDiagnostics(
            [
                'in_progress_queries' => [],
                'operations' => [],
                'in_progress_protection_enabled' => true,
            ],
            false,
            $logger
        );
    }

    public function testClassifierBootFailureLogsExceptionDetails(): void
    {
        $logger = $this->makeSpy();
        $logger->expects(self::once())->method('error')
            ->with(self::stringContains('classifier_boot_failed'));

        $container = $this->createMock(ContainerInterface::class);
        $container->method('hasParameter')->with('pimcore_data_hub')->willReturn(true);
        $container->method('getParameter')->with('pimcore_data_hub')->willReturn([
            'graphql' => ['in_progress_queries' => [], 'operations' => []],
        ]);
        $container->method('get')->willReturnCallback(static function (string $id) use ($logger): mixed {
            if ($id === 'logger') {
                return $logger;
            }
            if ($id === OperationClassifier::class) {
                throw new \RuntimeException('simulated constructor failure');
            }

            return null;
        });

        $bundle = new PimcoreDataHubBundle();
        $bundle->setContainer($container);
        $bundle->boot();
    }
}
