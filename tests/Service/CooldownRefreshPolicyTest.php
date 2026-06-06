<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\CooldownInvalidationDecisionKind;
use Pimcore\Bundle\DataHubBundle\Service\CooldownRefreshPolicy;
use Pimcore\Bundle\DataHubBundle\Service\CooldownTrailingDecisionKind;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;

final class CooldownRefreshPolicyTest extends TestCase
{
    private const COOLDOWN = 21600;

    private function makePolicy(PersistentOutputCacheService $cache): CooldownRefreshPolicy
    {
        return new CooldownRefreshPolicy($cache);
    }

    // ---- forward path: decideOnInvalidation ---------------------------------

    public function testDecideOnInvalidationReturnsLeadingEdgeWhenPastCooldownAndUnarmed(): void
    {
        $now = 1_700_000_000;
        $meta = ['lastRefreshAt' => $now - 2 * self::COOLDOWN];

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('isPastCooldown')->with($meta, self::COOLDOWN, $now)->willReturn(true);
        $cache->expects(self::never())->method('hasOperationCooldown');
        $cache->expects(self::never())->method('windowEndsAt');

        $decision = $this->makePolicy($cache)->decideOnInvalidation($meta, self::COOLDOWN, 'hash', $now);

        self::assertSame(CooldownInvalidationDecisionKind::LEADING_EDGE, $decision->kind);
        self::assertSame($now + self::COOLDOWN, $decision->deliverAt, 'leading-edge deliverAt = now + cooldown');
    }

    public function testDecideOnInvalidationReturnsLeadingEdgeWhenPastCooldownEvenIfArmed(): void
    {
        // The pastCooldown=true branch short-circuits before the armed check.
        // hasOperationCooldown must never be queried; mis-ordering would map
        // (true, true) onto COALESCE_ARMED and silently flip leading-edge to
        // a coalesced no-op.
        $now = 1_700_000_000;
        $meta = ['lastRefreshAt' => 0];

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('isPastCooldown')->willReturn(true);
        $cache->expects(self::never())->method('hasOperationCooldown');

        $decision = $this->makePolicy($cache)->decideOnInvalidation($meta, self::COOLDOWN, 'hash', $now);

        self::assertSame(CooldownInvalidationDecisionKind::LEADING_EDGE, $decision->kind);
        self::assertSame($now + self::COOLDOWN, $decision->deliverAt);
    }

    public function testDecideOnInvalidationReturnsCoalesceArmedWhenWithinWindowAndArmed(): void
    {
        $now = 1_700_000_000;
        $meta = ['lastRefreshAt' => $now - 100];

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('isPastCooldown')->willReturn(false);
        $cache->method('hasOperationCooldown')->with('hash-coalesce')->willReturn(true);
        $cache->expects(self::never())->method('windowEndsAt');

        $decision = $this->makePolicy($cache)
            ->decideOnInvalidation($meta, self::COOLDOWN, 'hash-coalesce', $now);

        self::assertSame(CooldownInvalidationDecisionKind::COALESCE_ARMED, $decision->kind);
        self::assertNull($decision->deliverAt, 'coalesce-armed carries no deliverAt');
    }

    public function testDecideOnInvalidationReturnsOpenTrailingWhenWithinWindowAndUnarmed(): void
    {
        $now = 1_700_000_000;
        $lastRefreshAt = $now - 100;
        $meta = ['lastRefreshAt' => $lastRefreshAt];

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('isPastCooldown')->willReturn(false);
        $cache->method('hasOperationCooldown')->willReturn(false);
        $cache->method('windowEndsAt')
            ->with($meta, self::COOLDOWN)
            ->willReturn($lastRefreshAt + self::COOLDOWN);

        $decision = $this->makePolicy($cache)
            ->decideOnInvalidation($meta, self::COOLDOWN, 'hash-open', $now);

        self::assertSame(CooldownInvalidationDecisionKind::OPEN_TRAILING, $decision->kind);
        self::assertSame(
            $lastRefreshAt + self::COOLDOWN,
            $decision->deliverAt,
            'open-trailing deliverAt = lastRefreshAt + cooldown (NOT now + cooldown)',
        );
        self::assertNotSame(
            $now + self::COOLDOWN,
            $decision->deliverAt,
            'the deliverAt asymmetry is load-bearing; do not collapse to now + cooldown',
        );
    }

    public function testDecideOnInvalidationLeadingEdgeUsesCallerSuppliedNow(): void
    {
        // The listener captures $now once per batch and passes it to all clock-sensitive calls; honour the capture or leading-edge deliverAt drifts relative to stampInvalidatedAt.
        $callerNow = 1_700_000_000;
        $meta = ['lastRefreshAt' => 0];

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('isPastCooldown')
            ->willReturnCallback(fn (array $m, int $c, ?int $n): bool => ($n ?? 0) - (int)($m['lastRefreshAt'] ?? 0) >= $c);

        $decision = $this->makePolicy($cache)
            ->decideOnInvalidation($meta, self::COOLDOWN, 'hash', $callerNow);

        self::assertSame(CooldownInvalidationDecisionKind::LEADING_EDGE, $decision->kind);
        self::assertSame(
            $callerNow + self::COOLDOWN,
            $decision->deliverAt,
            'policy must thread the caller-supplied $now into deliverAt',
        );
    }

    // ---- reverse path: decideOnTrailingPop ----------------------------------

    public function testDecideOnTrailingPopReturnsCancelWhenNotStale(): void
    {
        $now = 1_700_000_000;
        $meta = ['refreshedAt' => 200, 'invalidatedAt' => 100, 'lastRefreshAt' => $now - 100];

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('isEntryStaleWithWatermark')->with($meta)->willReturn(false);
        $cache->expects(self::never())->method('isPastCooldown');
        $cache->expects(self::never())->method('windowEndsAt');

        $decision = $this->makePolicy($cache)->decideOnTrailingPop($meta, self::COOLDOWN, $now);

        self::assertSame(CooldownTrailingDecisionKind::CANCEL, $decision->kind);
        self::assertNull($decision->deliverAt);
    }

    public function testDecideOnTrailingPopReturnsCancelEvenIfPastCooldown(): void
    {
        // The stale=false branch short-circuits before pastCooldown is queried.
        // Mis-ordering would map (false, true) onto FIRE — a silent regression
        // that would re-fire refreshes the listener already deemed unnecessary.
        $meta = ['refreshedAt' => 200, 'invalidatedAt' => 100];

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('isEntryStaleWithWatermark')->willReturn(false);
        $cache->expects(self::never())->method('isPastCooldown');

        $decision = $this->makePolicy($cache)->decideOnTrailingPop($meta, self::COOLDOWN);

        self::assertSame(CooldownTrailingDecisionKind::CANCEL, $decision->kind);
    }

    public function testDecideOnTrailingPopReturnsFireWhenStaleAndPastCooldown(): void
    {
        $now = 1_700_000_000;
        $meta = ['refreshedAt' => 1, 'invalidatedAt' => 100];

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('isEntryStaleWithWatermark')->willReturn(true);
        $cache->method('isPastCooldown')->with($meta, self::COOLDOWN, $now)->willReturn(true);
        $cache->expects(self::never())->method('windowEndsAt');

        $decision = $this->makePolicy($cache)->decideOnTrailingPop($meta, self::COOLDOWN, $now);

        self::assertSame(CooldownTrailingDecisionKind::FIRE, $decision->kind);
        self::assertNull($decision->deliverAt, 'fire carries no deliverAt; caller acquires lock + runs resolver');
    }

    public function testDecideOnTrailingPopReturnsRearmAtWindowEndsAtWhenStaleWithinCooldown(): void
    {
        $now = 1_700_000_000;
        $lastRefreshAt = $now - 5000;
        $meta = ['refreshedAt' => 1, 'invalidatedAt' => 100, 'lastRefreshAt' => $lastRefreshAt];

        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('isEntryStaleWithWatermark')->willReturn(true);
        $cache->method('isPastCooldown')->willReturn(false);
        $cache->method('windowEndsAt')
            ->with($meta, self::COOLDOWN)
            ->willReturn($lastRefreshAt + self::COOLDOWN);

        $decision = $this->makePolicy($cache)->decideOnTrailingPop($meta, self::COOLDOWN, $now);

        self::assertSame(CooldownTrailingDecisionKind::REARM, $decision->kind);
        self::assertSame(
            $lastRefreshAt + self::COOLDOWN,
            $decision->deliverAt,
            'rearm deliverAt = lastRefreshAt + cooldown (NOT now + cooldown)',
        );
        self::assertNotSame(
            $now + self::COOLDOWN,
            $decision->deliverAt,
            'the deliverAt asymmetry is load-bearing; rearm must converge on lastRefreshAt + cooldown',
        );
    }

    // ---- exception propagation ----------------------------------------------

    public function testInvalidationPropagatesPredicateExceptions(): void
    {
        // The policy must not swallow exceptions — both call sites own
        // their own fail-soft boundary. A swallow here would silently flip the
        // arm decision on a cache fault.
        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('isPastCooldown')->willThrowException(new \RuntimeException('cache down'));

        $this->expectException(\RuntimeException::class);
        $this->makePolicy($cache)->decideOnInvalidation([], self::COOLDOWN, 'hash', 1_700_000_000);
    }

    public function testTrailingPopPropagatesPredicateExceptions(): void
    {
        $cache = $this->createMock(PersistentOutputCacheService::class);
        $cache->method('isEntryStaleWithWatermark')->willThrowException(new \RuntimeException('cache down'));

        $this->expectException(\RuntimeException::class);
        $this->makePolicy($cache)->decideOnTrailingPop([], self::COOLDOWN);
    }
}
