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

/**
 * Classification tier of a GraphQL operation under the two-tier SWR design.
 *
 * NEITHER is reserved for operationNames absent from the `operations` config
 * tree; an operation declared there must resolve to HERD_GUARDED or SWR_ONLY.
 * Observing NEITHER for a declared operation indicates a config misread.
 */
enum Tier: string
{
    case HERD_GUARDED = 'herd_guarded';
    case SWR_ONLY = 'swr_only';
    case NEITHER = 'neither';

    /**
     * Whether this tier engages the herd-guard duplicate-request barrier.
     * Colocated here so adding a new case forces an explicit answer at definition time.
     */
    public function engagesHerdGuard(): bool
    {
        return match ($this) {
            self::HERD_GUARDED => true,
            self::SWR_ONLY, self::NEITHER => false,
        };
    }
}
