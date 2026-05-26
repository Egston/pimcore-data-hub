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

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class InProgressLockReleaseListenerTest extends TestCase
{
    private InProgressLockReleaseListener $listener;

    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $this->listener = new InProgressLockReleaseListener();
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    public function testSubscribesCorrectly(): void
    {
        $events = InProgressLockReleaseListener::getSubscribedEvents();
        $this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
        $this->assertArrayHasKey(KernelEvents::TERMINATE, $events);
    }

    public function testGetSubscribedEventsReturnsPriorityMinus100ForTerminate(): void
    {
        $events = InProgressLockReleaseListener::getSubscribedEvents();
        // Safety-net release must run after PersistentCacheRefreshOnTerminateListener (0).
        $this->assertSame(['onKernelTerminate', -100], $events[KernelEvents::TERMINATE]);
        $this->assertSame(['onKernelException', -100], $events[KernelEvents::EXCEPTION]);
    }

    public function testNoOpWhenNoAttributes(): void
    {
        $request = Request::create('/api', 'POST');

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('test')
        );

        // Must not throw; listener exits immediately
        $this->listener->onKernelException($event);

        $this->assertFalse($request->attributes->has('datahub_inprogress_lock'));
        $this->assertFalse($request->attributes->has('datahub_inprogress_guard_key'));
    }

    public function testReleasesSymfonyLockAndClearsAttribute(): void
    {
        $releaseCalled = false;
        $lock = new class($releaseCalled) {
            private bool $called;

            public function __construct(bool &$called)
            {
                $this->called = &$called;
            }

            public function release(): void
            {
                $this->called = true;
            }
        };

        $request = Request::create('/api', 'POST');
        $request->attributes->set('datahub_inprogress_lock', $lock);

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('test')
        );

        $this->listener->onKernelException($event);

        $this->assertTrue($releaseCalled, 'Lock::release() must be called');
        $this->assertFalse(
            $request->attributes->has('datahub_inprogress_lock'),
            'Lock attribute must be removed after release'
        );
    }

    public function testClearsGuardKeyAttributeOnTerminate(): void
    {
        $request = Request::create('/api', 'POST');
        $request->attributes->set('datahub_inprogress_guard_key', 'abc123');

        $event = new TerminateEvent($this->kernel, $request, new Response());

        // \Pimcore\Cache::remove() fails without a kernel but is caught silently;
        // the attribute must still be cleaned up.
        $this->listener->onKernelTerminate($event);

        $this->assertFalse(
            $request->attributes->has('datahub_inprogress_guard_key'),
            'Guard key attribute must be removed even if Cache::remove() throws'
        );
    }

    public function testBothAttributesClearedOnException(): void
    {
        $releaseCalled = false;
        $lock = new class($releaseCalled) {
            private bool $called;

            public function __construct(bool &$called)
            {
                $this->called = &$called;
            }

            public function release(): void
            {
                $this->called = true;
            }
        };

        $request = Request::create('/api', 'POST');
        $request->attributes->set('datahub_inprogress_lock', $lock);
        $request->attributes->set('datahub_inprogress_guard_key', 'abc123');

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('test')
        );

        $this->listener->onKernelException($event);

        $this->assertTrue($releaseCalled);
        $this->assertFalse($request->attributes->has('datahub_inprogress_lock'));
        $this->assertFalse($request->attributes->has('datahub_inprogress_guard_key'));
    }

    public function testIdempotentOnDoubleInvocation(): void
    {
        $request = Request::create('/api', 'POST');
        $request->attributes->set('datahub_inprogress_guard_key', 'abc123');

        $event = new TerminateEvent($this->kernel, $request, new Response());

        $this->listener->onKernelTerminate($event);
        // Second call — attribute is gone; listener must exit cleanly
        $this->listener->onKernelTerminate($event);

        $this->assertFalse($request->attributes->has('datahub_inprogress_guard_key'));
    }
}
