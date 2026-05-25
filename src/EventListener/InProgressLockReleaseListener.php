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

use Pimcore\Bundle\DataHubBundle\Lock\LockSignalRefresher;
use Pimcore\Logger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Safety-net that releases both the Symfony Lock and the Pimcore cache marker
 * set by OutputCacheService::maybeRejectOrAcquire() when save() never ran —
 * controller exception or skipOutputCache path. Without this, a failed request
 * leaks the marker for the full inProgressTtl, turning one error into 503s for
 * every same-operation request during that window.
 *
 * Idempotent: save() removes both attributes on the happy path, so this
 * listener exits immediately for normal requests.
 */
class InProgressLockReleaseListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -100],
            KernelEvents::TERMINATE => ['onKernelTerminate', -100],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $this->releaseIfAny($event->getRequest());
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->releaseIfAny($event->getRequest());
    }

    private function releaseIfAny(\Symfony\Component\HttpFoundation\Request $request): void
    {
        $hasWork = $request->attributes->has('datahub_inprogress_lock')
            || $request->attributes->has('datahub_inprogress_guard_key');

        // ALWAYS stop the SIGALRM refresher — even if this request never set
        // the guard attributes itself. A previous request in the same worker
        // may have armed the alarm and exited through a path that bypassed
        // save() and the local uninstall (e.g. fatal-then-listener), leaving
        // a closure-captured Lock that would otherwise refresh itself forever
        // on this idle worker. Cheap to call when nothing was armed.
        LockSignalRefresher::disarm();

        if (!$hasWork) {
            return;
        }

        $leaked = false;

        // Release Symfony Lock (present only when LockFactory is configured).
        $lock = $request->attributes->get('datahub_inprogress_lock');
        if ($lock) {
            try {
                if (method_exists($lock, 'release')) {
                    $lock->release();
                    $leaked = true;
                }
            } catch (\Throwable) {
                // best-effort; must not break the response
            }
            $request->attributes->remove('datahub_inprogress_lock');
        }

        // Delete the Pimcore cache marker (always present when the guard ran).
        $guardKey = $request->attributes->get('datahub_inprogress_guard_key');
        if ($guardKey) {
            try {
                \Pimcore\Cache::remove('datahub_inprogress_' . $guardKey);
                $leaked = true;
            } catch (\Throwable) {
                // best-effort
            }
            $request->attributes->remove('datahub_inprogress_guard_key');
        }

        if ($leaked) {
            Logger::warning(
                'DataHub in-progress guard released by safety-net listener — '
                . 'OutputCacheService::save() did not run for this request. '
                . 'Check earlier log entries for the root cause.'
            );
        }
    }
}
