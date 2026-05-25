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

use Pimcore\Bundle\DataHubBundle\Service\DependencyCollector;
use Pimcore\Logger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restores the request-scoped invariant of DependencyCollector under
 * Symfony's shared-service container: the collector is a singleton across
 * the worker process, so without an explicit per-request reset the
 * collected set would persist across sub-requests and worker iterations.
 *
 * Priority 4096 runs ahead of every framework + bundle kernel.request
 * listener; the reset must happen before any element load (which can occur
 * inside other request listeners) so the collected set always reflects only
 * the current request's loads.
 */
class DependencyCollectorResetListener implements EventSubscriberInterface
{
    public function __construct(private DependencyCollector $collector)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4096],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        unset($event);

        try {
            $this->collector->reset();
        } catch (\Throwable $e) {
            Logger::error('DependencyCollector reset failed: ' . $e->getMessage());
        }
    }
}
