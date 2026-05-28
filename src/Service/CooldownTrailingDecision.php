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
 * Reverse-path (trailing-pop) decision returned by
 * {@see CooldownRefreshPolicy::decideOnTrailingPop()}.
 *
 * Three arms partition the `(stale ∈ {true,false}) × (pastCooldown ∈ {true,false})`
 * cube where `stale=false` collapses both `pastCooldown` cells onto CANCEL:
 *
 * - CANCEL — entry no longer stale. Caller clears the pending flag and the
 *   cooldown sentinel so the next edit re-arms. No `deliverAt`.
 * - FIRE   — stale and past cooldown. Caller proceeds to acquire the lock and
 *   run the resolver. No `deliverAt`.
 * - REARM  — stale but within cooldown. Caller re-dispatches a dated trailing
 *   at `lastRefreshAt + cooldown`, leaving the pending flag set so the loop
 *   converges. Carries {@see self::$deliverAt}.
 */
final class CooldownTrailingDecision
{
    private function __construct(
        public readonly CooldownTrailingDecisionKind $kind,
        public readonly ?int $deliverAt = null,
    ) {
    }

    public static function cancel(): self
    {
        return new self(CooldownTrailingDecisionKind::CANCEL);
    }

    public static function fire(): self
    {
        return new self(CooldownTrailingDecisionKind::FIRE);
    }

    public static function rearm(int $deliverAt): self
    {
        return new self(CooldownTrailingDecisionKind::REARM, $deliverAt);
    }
}
