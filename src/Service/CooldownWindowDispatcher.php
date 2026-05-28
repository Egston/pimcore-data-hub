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

namespace Pimcore\Bundle\DataHubBundle\Service;

use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Arms the per-entry cooldown sentinel and enqueues the dated trailing refresh
 * in one structural step — sentinel and companion are inseparable by construction.
 *
 * `$deliverAt` is an explicit caller parameter, never computed here: the two
 * call sites are deliberately asymmetric — leading-edge passes `now + cooldown`,
 * within-window and re-arm pass `lastRefreshAt + cooldown`. The dispatcher must
 * never derive it.
 *
 * @throws \Throwable propagated to the caller; outer fail-soft catches on the
 *                    invalidation listener and handler own the failure boundary.
 */
final class CooldownWindowDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private PersistentOutputCacheService $persistentCache
    ) {
    }

    /**
     * @param string                  $hash        per-entry hash (entryHash / entryHashFromBody)
     * @param int                     $cooldownTtl cooldown window length in seconds (sentinel TTL)
     * @param int                     $deliverAt   absolute earliest-delivery Unix timestamp
     * @param PersistentRefreshMessage $template   source of client, bodyJson, operationName, priorityWeight
     */
    public function open(
        string $hash,
        int $cooldownTtl,
        int $deliverAt,
        PersistentRefreshMessage $template
    ): void {
        $this->persistentCache->armOperationCooldown($hash, $cooldownTtl);
        $this->bus->dispatch(new PersistentRefreshMessage(
            client: $template->client,
            bodyJson: $template->bodyJson,
            operationName: $template->operationName,
            scoreBaseline: time(),
            priorityWeight: $template->priorityWeight,
            deliverAt: $deliverAt,
        ));
    }
}
