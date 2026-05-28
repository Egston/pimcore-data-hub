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

namespace Pimcore\Bundle\DataHubBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\Granularity;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\Tier;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

final class OperationClassifierTest extends TestCase
{
    /**
     * @param array<string, mixed> $graphql
     */
    private function makeClassifier(array $graphql): OperationClassifier
    {
        $bag = $this->createMock(ContainerBagInterface::class);
        $bag->method('get')->willReturn(['graphql' => $graphql]);

        return new OperationClassifier($bag);
    }

    public function testGetTierReturnsHerdGuardedForHerdGuardedEntries(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpListGuarded' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
            ],
        ]);
        self::assertSame(Tier::HERD_GUARDED, $classifier->getTier('testOpListGuarded'));
    }

    public function testGetTierReturnsSwrOnlyForSwrOnlyEntries(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpSingleSwr' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ],
        ]);
        self::assertSame(Tier::SWR_ONLY, $classifier->getTier('testOpSingleSwr'));
    }

    public function testGetTierReturnsNeitherForUnlistedOperations(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpListGuarded' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
            ],
        ]);
        self::assertSame(Tier::NEITHER, $classifier->getTier('testOpUnknown'));
    }

    public function testGetGranularityReturnsNullForNeitherTier(): void
    {
        $classifier = $this->makeClassifier([]);
        self::assertNull($classifier->getGranularity('testOpUnknown'));
    }

    public function testGetGranularityReturnsExplicitGranularityForClassified(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpSingleSwr' => ['tier' => 'swr_only', 'granularity' => 'single'],
                'testOpListGuarded' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
            ],
        ]);
        self::assertSame(Granularity::SINGLE, $classifier->getGranularity('testOpSingleSwr'));
        self::assertSame(Granularity::LIST, $classifier->getGranularity('testOpListGuarded'));
    }

    public function testGetTtlUsesTtlOverrideWhenPresent(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpListSwr' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'ttl_override' => 600,
                ],
            ],
        ]);
        self::assertSame(600, $classifier->getTtl('testOpListSwr'));
    }

    public function testGetTtlFallsBackToPerGranularityDefaultWhenNoOverride(): void
    {
        $classifier = $this->makeClassifier([
            'persistent_output_cache_payload_ttl_by_granularity' => [
                'single' => 3600,
                'list' => 7200,
            ],
            'operations' => [
                'testOpSingleSwr' => ['tier' => 'swr_only', 'granularity' => 'single'],
                'testOpListSwr' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ],
        ]);
        self::assertSame(3600, $classifier->getTtl('testOpSingleSwr'));
        self::assertSame(7200, $classifier->getTtl('testOpListSwr'));
    }

    public function testGetTtlReturnsNullForNeitherTier(): void
    {
        $classifier = $this->makeClassifier([
            'persistent_output_cache_payload_ttl_by_granularity' => [
                'single' => 3600,
                'list' => 7200,
            ],
        ]);
        self::assertNull($classifier->getTtl('testOpUnknown'));
    }

    public function testHasOperationTrueForListed(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpListGuarded' => ['tier' => 'herd_guarded', 'granularity' => 'list'],
            ],
        ]);
        self::assertTrue($classifier->hasOperation('testOpListGuarded'));
    }

    public function testHasOperationFalseForUnlisted(): void
    {
        $classifier = $this->makeClassifier([]);
        self::assertFalse($classifier->hasOperation('testOpUnknown'));
    }

    public function testGetEnqueueDedupeTtlUsesOverrideOrReturnsNull(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpListSwr' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'enqueue_dedup_ttl_override' => 45,
                ],
                'testOpSingleSwr' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ],
        ]);
        self::assertSame(45, $classifier->getEnqueueDedupeTtl('testOpListSwr'));
        self::assertNull($classifier->getEnqueueDedupeTtl('testOpSingleSwr'));
        self::assertNull($classifier->getEnqueueDedupeTtl('testOpUnknown'));
    }

    public function testMalformedEntryWithNeitherTierIsSkippedNotClassified(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpNeither' => ['tier' => 'neither', 'granularity' => 'list'],
            ],
        ]);
        self::assertSame(Tier::NEITHER, $classifier->getTier('testOpNeither'));
        self::assertFalse($classifier->hasOperation('testOpNeither'));
        self::assertNull($classifier->getTtl('testOpNeither'));
    }

    public function testGetPriorityWeightDefaultsToOneAndRespectsOverride(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpListSwr' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'priority_weight' => 7,
                ],
                'testOpSingleSwr' => ['tier' => 'swr_only', 'granularity' => 'single'],
            ],
        ]);
        self::assertSame(7, $classifier->getPriorityWeight('testOpListSwr'));
        self::assertSame(1, $classifier->getPriorityWeight('testOpSingleSwr'));
        self::assertNull($classifier->getPriorityWeight('testOpUnknown'));
    }

    public function testGetReadPriorityWeightReturnsConfiguredValue(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpListSwr' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'read_priority_weight' => 9,
                ],
            ],
        ]);
        self::assertSame(9, $classifier->getReadPriorityWeight('testOpListSwr'));
    }

    public function testGetReadPriorityWeightDefaultsToOneWhenAbsent(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpListSwr' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ],
        ]);
        self::assertSame(1, $classifier->getReadPriorityWeight('testOpListSwr'));
    }

    public function testGetReadPriorityWeightReturnsNullForUnclassifiedOp(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpListSwr' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ],
        ]);
        self::assertNull($classifier->getReadPriorityWeight('testOpUnknown'));
    }

    public function testBandWeightForWithReadTriggeredFalseReturnsWarmWeight(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpBandWeight' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'priority_weight' => 7,
                    'read_priority_weight' => 9,
                ],
            ],
        ]);
        self::assertSame(7, $classifier->bandWeightFor('testOpBandWeight', false));
    }

    public function testBandWeightForWithReadTriggeredTrueReturnsReadWeight(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpBandWeight' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'priority_weight' => 7,
                    'read_priority_weight' => 9,
                ],
            ],
        ]);
        self::assertSame(9, $classifier->bandWeightFor('testOpBandWeight', true));
    }

    public function testBandWeightForUnclassifiedReturnsNull(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpBandWeight' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'priority_weight' => 7,
                    'read_priority_weight' => 9,
                ],
            ],
        ]);
        self::assertNull($classifier->bandWeightFor('testOpUnknown', true));
        self::assertNull($classifier->bandWeightFor('testOpUnknown', false));
    }

    public function testGetInvalidationCooldownReturnsValueWhenSet(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpListSwr' => [
                    'tier' => 'swr_only',
                    'granularity' => 'list',
                    'invalidation_cooldown_ttl' => 21600,
                ],
            ],
        ]);
        self::assertSame(21600, $classifier->getInvalidationCooldown('testOpListSwr'));
    }

    public function testGetInvalidationCooldownReturnsNullWhenUnsetOrUnclassified(): void
    {
        $classifier = $this->makeClassifier([
            'operations' => [
                'testOpListSwr' => ['tier' => 'swr_only', 'granularity' => 'list'],
            ],
        ]);
        self::assertNull($classifier->getInvalidationCooldown('testOpListSwr'));
        self::assertNull($classifier->getInvalidationCooldown('testOpUnknown'));
    }
}
