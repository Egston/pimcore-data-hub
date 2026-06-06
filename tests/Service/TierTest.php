<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\Tier;

final class TierTest extends TestCase
{
    public function testEngagesHerdGuardTruthTable(): void
    {
        self::assertTrue(Tier::HERD_GUARDED->engagesHerdGuard());
        self::assertFalse(Tier::SWR_ONLY->engagesHerdGuard());
        self::assertFalse(Tier::NEITHER->engagesHerdGuard());
    }
}
