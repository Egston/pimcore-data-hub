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

use Pimcore\Logger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Net catching the in-progress lock attached to a DataHub GraphQL request
 * (`datahub_inprogress_lock` attribute, set by OutputCacheService::maybeRejectOrAcquire).
 *
 * In the happy path OutputCacheService::save() releases the lock and removes the
 * attribute itself; this listener only fires when save() never ran — controller
 * exception bubbled up to Symfony's exception handler, or useCache() turned the
 * standard output cache off for this request. Without this safety net a
 * controller-throw leaks the lock for the full inProgressTtl (default 60s,
 * configurable up to 600s+), blocking every subsequent same-operation request
 * with the herd-guard 503.
 *
 * Idempotent — removing the attribute on the happy-path save() prevents a
 * double release here.
 */
class InProgressLockReleaseListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -100],
            KernelEvents::TERMINATE => 'onKernelTerminate',
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
        $lock = $request->attributes->get('datahub_inprogress_lock');
        if (!$lock) {
            return;
        }

        try {
            if (method_exists($lock, 'release')) {
                $lock->release();
                Logger::warning(
                    'DataHub in-progress lock released by safety-net listener — '
                    . 'controller did not reach OutputCacheService::save(). '
                    . 'This usually means the controller threw an unhandled '
                    . 'exception. Check earlier log entries for the root cause.'
                );
            }
        } catch (\Throwable $e) {
            // best-effort release; logging failures must not break the response
        }
        $request->attributes->remove('datahub_inprogress_lock');
    }
}
