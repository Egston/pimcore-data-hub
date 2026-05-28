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
 * Unifies the two-site cooldown-refresh state machine: the forward path
 * (invalidation listener, "an edit just happened") and the reverse path
 * (refresh worker popping a dated trailing message, "the cooldown window may
 * have expired").
 *
 * The two paths share predicate inputs (`stale`, `pastCooldown`, `armed`) but
 * arrive at non-overlapping decisions. Each path's three arms partition a
 * different two-axis cube; see {@see CooldownInvalidationDecision} and
 * {@see CooldownTrailingDecision} for the partition tables.
 *
 * Pure-decision contract: the policy reads via {@see PersistentOutputCacheService}
 * accessors (forward path queries `hasOperationCooldown`) but performs no
 * dispatch, no sentinel arm/clear, no message construction. Callers own all
 * I/O and arm-specific logging. The policy stays I/O-light so it is trivial
 * to unit-test against the six decision cells.
 *
 * `\Throwable` is propagated to the caller — both call sites wrap the policy
 * invocation in their own narrow fail-soft catches (the listener falls
 * through to the watermark safety floor; the handler fires conservatively).
 * Catching here would silently flip arm decisions on a cache fault.
 */
final class CooldownRefreshPolicy
{
    public function __construct(
        private PersistentOutputCacheService $persistentCache,
    ) {
    }

    /**
     * Forward path: the invalidation listener just stamped `invalidatedAt` on
     * `$meta` and is asking which of the three forward arms to take.
     *
     * Decision ordering:
     * 1. `pastCooldown=true`            → LEADING_EDGE (short-circuits before `armed` is queried)
     * 2. `pastCooldown=false, armed=true`  → COALESCE_ARMED
     * 3. `pastCooldown=false, armed=false` → OPEN_TRAILING
     *
     * `stale=true` is implied by the listener having just stamped
     * `invalidatedAt=now` before calling here, so the policy does not re-check
     * staleness on the forward path.
     *
     * @param array<string, mixed> $meta     post-`stampInvalidatedAt` entry meta
     * @param int                  $cooldown operation cooldown window in seconds (caller has already verified it is non-null)
     * @param string               $hash     per-entry hash, used to query `hasOperationCooldown`
     * @param int|null             $now      explicit clock for the caller's per-batch capture; defaults to `time()`
     */
    public function decideOnInvalidation(
        array $meta,
        int $cooldown,
        string $hash,
        ?int $now = null,
    ): CooldownInvalidationDecision {
        $now ??= time();

        if ($this->persistentCache->isPastCooldown($meta, $cooldown, $now)) {
            return CooldownInvalidationDecision::leadingEdge($now + $cooldown);
        }

        if ($this->persistentCache->hasOperationCooldown($hash)) {
            return CooldownInvalidationDecision::coalesceArmed();
        }

        return CooldownInvalidationDecision::openTrailing(
            $this->persistentCache->windowEndsAt($meta, $cooldown),
        );
    }

    /**
     * Reverse path: a dated trailing message has reached the worker. Decide
     * whether to cancel (entry no longer needs refresh), fire (proceed to
     * acquire lock + run resolver), or re-arm (still stale but within
     * cooldown, dispatch the next trailing).
     *
     * Decision ordering:
     * 1. `stale=false, *`              → CANCEL (short-circuits before `pastCooldown`)
     * 2. `stale=true, pastCooldown=true`  → FIRE
     * 3. `stale=true, pastCooldown=false` → REARM at `lastRefreshAt + cooldown`
     *
     * `armed=true` is implied by a dated pop having arrived (a window must
     * have existed to schedule it), so the policy does not re-check `armed`
     * on the reverse path.
     *
     * @param array<string, mixed> $meta     reloaded entry meta at pop time
     * @param int                  $cooldown operation cooldown in seconds
     * @param int|null             $now      explicit clock; defaults to `time()`
     */
    public function decideOnTrailingPop(
        array $meta,
        int $cooldown,
        ?int $now = null,
    ): CooldownTrailingDecision {
        $now ??= time();

        if (!$this->persistentCache->isEntryStaleWithWatermark($meta)) {
            return CooldownTrailingDecision::cancel();
        }

        if ($this->persistentCache->isPastCooldown($meta, $cooldown, $now)) {
            return CooldownTrailingDecision::fire();
        }

        return CooldownTrailingDecision::rearm(
            $this->persistentCache->windowEndsAt($meta, $cooldown),
        );
    }
}
