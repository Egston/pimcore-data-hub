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
         * Per-operation priority weight sourced from {@see \Pimcore\Bundle\DataHubBundle\Service\OperationClassifier::getPriorityWeight()}.
         * Threaded through the message envelope as a forward-compatible scoring input;
         * {@see \Pimcore\Bundle\DataHubBundle\Messenger\PriorityRedisTransport::scoreFor()} currently orders solely by
         * {@see self::$refreshedAt} and ignores this value. Future scoring strategies that blend staleness with
         * per-operation weight will consume it without a schema change to the message.
         */
        public readonly ?int $priorityWeight = null
    ) {
    }
}
