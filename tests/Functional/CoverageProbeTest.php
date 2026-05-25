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

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;

/**
 * Landing site for the L3 kernel-booted POST_LOAD coverage assertion that
 * verifies every Pimcore element loader path (DataObject\Listing, Asset,
 * Document, search-backend listings, raw-SQL hydrators) fires POST_LOAD
 * and thus reaches the DependencyCollector.
 *
 * Until the substantive body lands, the in-flight
 * `datahub.swr.collector_empty_on_save` warning is the load-bearing
 * detector for missing-POST_LOAD-coverage regressions.
 */
final class CoverageProbeTest extends TestCase
{
    public function testCoverageProbePlaceholder(): void
    {
        $this->markTestSkipped('kernel-booted coverage probe pending; this file is the known landing site');
    }
}
