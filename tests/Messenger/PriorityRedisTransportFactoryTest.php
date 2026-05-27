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

namespace Pimcore\Bundle\DataHubBundle\Tests\Messenger;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Messenger\PriorityRedisTransportFactory;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class PriorityRedisTransportFactoryTest extends TestCase
{
    private function makeFactory(): PriorityRedisTransportFactory
    {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'persistent_refresh_priority_visibility_timeout' => 600,
                'persistent_refresh_priority_requeue_score_bump' => 5,
            ],
        ]);

        return new PriorityRedisTransportFactory($container);
    }

    public function testSupportsMatchesPriorityRedisScheme(): void
    {
        $factory = $this->makeFactory();
        self::assertTrue($factory->supports('datahub-priority-redis://redis-master:6379/0', []));
    }

    public function testSupportsRejectsPlainRedisScheme(): void
    {
        $factory = $this->makeFactory();
        self::assertFalse($factory->supports('redis://redis-master:6379/0', []));
    }

    public function testSupportsRejectsDoctrineScheme(): void
    {
        $factory = $this->makeFactory();
        self::assertFalse($factory->supports('doctrine://default', []));
    }

    public function testSupportsRejectsMalformedDsn(): void
    {
        $factory = $this->makeFactory();
        self::assertFalse($factory->supports('not-a-dsn', []));
        self::assertFalse($factory->supports('', []));
    }

    public function testCreateTransportRejectsWrongScheme(): void
    {
        $factory = $this->makeFactory();
        $this->expectException(\InvalidArgumentException::class);
        $factory->createTransport('redis://redis-master:6379/0', [], $this->createMock(SerializerInterface::class));
    }

    public function testCreateTransportRejectsDsnWithoutHost(): void
    {
        $factory = $this->makeFactory();
        $this->expectException(\InvalidArgumentException::class);
        $factory->createTransport('datahub-priority-redis://', [], $this->createMock(SerializerInterface::class));
    }

    public function testCreateTransportRejectsUnknownPriorityStrategy(): void
    {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'persistent_refresh_priority_strategy' => 'bogus_strategy',
                'persistent_refresh_priority_visibility_timeout' => 600,
                'persistent_refresh_priority_requeue_score_bump' => 5,
            ],
        ]);
        $factory = new PriorityRedisTransportFactory($container);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bogus_strategy/');
        $factory->createTransport('datahub-priority-redis://redis-master:6379/0', [], $this->createMock(SerializerInterface::class));
    }

    public function testFactoryRejectsOffsetBelowWarmBandSpanUnderWeightBands(): void
    {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'persistent_refresh_priority_strategy' => 'oldest_refreshed_at_first_with_weight_bands',
                'persistent_refresh_priority_weight_band_seconds' => 60,
                'persistent_refresh_priority_max_weight' => 100,
                'persistent_refresh_priority_read_trigger_offset_seconds' => 5999,
                'persistent_refresh_priority_visibility_timeout' => 600,
                'persistent_refresh_priority_requeue_score_bump' => 5,
            ],
        ]);
        $factory = new PriorityRedisTransportFactory($container);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/read_trigger_offset_seconds/');
        $factory->createTransport('datahub-priority-redis://redis-master:6379/0', [], $this->createMock(SerializerInterface::class));
    }

    public function testFactoryAcceptsOffsetDominatingWarmBandSpan(): void
    {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'persistent_refresh_priority_strategy' => 'oldest_refreshed_at_first_with_weight_bands',
                'persistent_refresh_priority_weight_band_seconds' => 60,
                'persistent_refresh_priority_max_weight' => 100,
                'persistent_refresh_priority_read_trigger_offset_seconds' => 6001,
                'persistent_refresh_priority_visibility_timeout' => 600,
                'persistent_refresh_priority_requeue_score_bump' => 5,
            ],
        ]);
        $factory = new PriorityRedisTransportFactory($container);

        try {
            $factory->createTransport('datahub-priority-redis://redis-master:6379/0', [], $this->createMock(SerializerInterface::class));
        } catch (\InvalidArgumentException $e) {
            $this->fail('Guard must not fire when offset dominates the warm band span: ' . $e->getMessage());
        } catch (\Throwable) {
        }
        $this->addToAssertionCount(1);
    }

    public function testFactoryGuardDoesNotApplyUnderPlainOldestFirstStrategy(): void
    {
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn([
            'graphql' => [
                'persistent_refresh_priority_strategy' => 'oldest_refreshed_at_first',
                'persistent_refresh_priority_weight_band_seconds' => 60,
                'persistent_refresh_priority_max_weight' => 100,
                'persistent_refresh_priority_read_trigger_offset_seconds' => 1,
                'persistent_refresh_priority_visibility_timeout' => 600,
                'persistent_refresh_priority_requeue_score_bump' => 5,
            ],
        ]);
        $factory = new PriorityRedisTransportFactory($container);

        try {
            $factory->createTransport('datahub-priority-redis://redis-master:6379/0', [], $this->createMock(SerializerInterface::class));
        } catch (\InvalidArgumentException $e) {
            $this->fail('Guard must not fire under oldest_refreshed_at_first strategy: ' . $e->getMessage());
        } catch (\Throwable) {
        }
        $this->addToAssertionCount(1);
    }
}
