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
