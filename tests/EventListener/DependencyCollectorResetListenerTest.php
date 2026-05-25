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

namespace Pimcore\Bundle\DataHubBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\EventListener\DependencyCollectorResetListener;
use Pimcore\Bundle\DataHubBundle\Service\DependencyCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class DependencyCollectorResetListenerTest extends TestCase
{
    public function testGetSubscribedEventsKernelRequestPriority4096(): void
    {
        $events = DependencyCollectorResetListener::getSubscribedEvents();
        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertSame(['onKernelRequest', 4096], $events[KernelEvents::REQUEST]);
    }

    public function testOnKernelRequestCallsCollectorReset(): void
    {
        $collector = $this->createMock(DependencyCollector::class);
        $collector->expects(self::once())->method('reset');

        $listener = new DependencyCollectorResetListener($collector);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST
        );
        $listener->onKernelRequest($event);
    }

    public function testResetExceptionDoesNotPropagate(): void
    {
        $this->expectNotToPerformAssertions();

        $collector = $this->createMock(DependencyCollector::class);
        $collector->method('reset')->willThrowException(new \RuntimeException('reset boom'));

        $listener = new DependencyCollectorResetListener($collector);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST
        );
        $listener->onKernelRequest($event);
    }
}
