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

namespace Pimcore\Bundle\DataHubBundle\Message;

final class PersistentRefreshMessage
{
    public function __construct(
        public readonly string $client,
        public readonly string $bodyJson,
        public readonly ?string $operationName = null,
        public readonly ?int $refreshedAt = null,
        /**
         * Per-operation priority weight consumed by {@see \Pimcore\Bundle\DataHubBundle\Messenger\PriorityRedisTransport::scoreFor()}
         * under the `oldest_refreshed_at_first_with_weight_bands` strategy to offset the score
         * by `priorityWeight × weightBandSeconds`.
         */
        public readonly ?int $priorityWeight = null,
        /**
         * Absolute earliest-delivery Unix timestamp for scheduled (delayed) refreshes.
         * When non-null, {@see \Pimcore\Bundle\DataHubBundle\Messenger\PriorityRedisTransport::scoreFor()}
         * uses it verbatim as the queue score, so the message stays invisible to
         * {@see \Pimcore\Bundle\DataHubBundle\Messenger\PriorityRedisTransport::get()} until
         * `now >= deliverAt`. Encoded as an absolute timestamp (not a relative delay) so the
         * transport's visibility-timeout reaper re-derives the same due-time from the envelope
         * and a reaped scheduled message keeps its original schedule. Null = immediate delivery.
         */
        public readonly ?int $deliverAt = null
    ) {
    }
}
