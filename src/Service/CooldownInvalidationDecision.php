<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service;

/**
 * Forward-path (invalidation) decision returned by
 * {@see CooldownRefreshPolicy::decideOnInvalidation()}.
 *
 * Three arms partition the `(pastCooldown ∈ {true,false}) × (armed ∈ {true,false})`
 * cube where `pastCooldown=true` collapses both `armed` cells onto LEADING_EDGE:
 *
 * - LEADING_EDGE  — cooldown elapsed (or never refreshed). Caller warms
 *   immediately and opens a fresh window dated `now + cooldown`. Carries
 *   {@see self::$deliverAt} = `now + cooldown`.
 * - COALESCE_ARMED — within window, sentinel already armed. Caller writes the
 *   pending flag so the in-flight trailing observes the late edit. No
 *   `deliverAt`.
 * - OPEN_TRAILING — within window, no sentinel yet. Caller opens a window-end
 *   dated trailing. Carries {@see self::$deliverAt} = `lastRefreshAt + cooldown`.
 *
 * The two `deliverAt`-carrying arms hold deliberately asymmetric values. Do not collapse.
 */
final class CooldownInvalidationDecision
{
    private function __construct(
        public readonly CooldownInvalidationDecisionKind $kind,
        public readonly ?int $deliverAt = null,
    ) {
    }

    public static function leadingEdge(int $deliverAt): self
    {
        return new self(CooldownInvalidationDecisionKind::LEADING_EDGE, $deliverAt);
    }

    public static function coalesceArmed(): self
    {
        return new self(CooldownInvalidationDecisionKind::COALESCE_ARMED);
    }

    public static function openTrailing(int $deliverAt): self
    {
        return new self(CooldownInvalidationDecisionKind::OPEN_TRAILING, $deliverAt);
    }
}
