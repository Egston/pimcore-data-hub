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

namespace Pimcore\Bundle\DataHubBundle\Tests\Service\RequestValidation;

use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RulesSet;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RulesVersion;

final class RulesInheritanceTest extends TempfileTestCase
{
    /**
     * @param array<string, mixed> $rules
     */
    private function loadFrom(array $rules): RulesSet
    {
        $this->writeJson($rules);
        $loader = new CapturingRulesLoader($this->file);
        $set = $loader->load();
        self::assertNotNull($set);

        return $set;
    }

    public function testVersionInheritsParentOperations(): void
    {
        $set = $this->loadFrom([
            'versions' => [
                '1' => ['operations' => ['opA' => ['variables' => ['x' => ['kind' => 'null']]]]],
                '2' => ['inherits' => 1, 'operations' => ['opB' => ['variables' => []]]],
            ],
        ]);

        $v2 = $set->forVersionOrLatest(2);
        self::assertInstanceOf(RulesVersion::class, $v2);
        self::assertTrue($v2->hasOperation('opA'), 'inherited op present');
        self::assertTrue($v2->hasOperation('opB'), 'own op present');
    }

    public function testPerOpOverrideReplacesInherited(): void
    {
        $set = $this->loadFrom([
            'versions' => [
                '1' => ['operations' => ['opA' => ['variables' => ['x' => ['kind' => 'null']]]]],
                '2' => ['inherits' => 1, 'operations' => ['opA' => ['variables' => ['y' => ['kind' => 'null']]]]],
            ],
        ]);

        $rule = $set->forVersionOrLatest(2)?->operationRule('opA');
        self::assertNotNull($rule);
        self::assertFalse($rule->hasVariable('x'), 'overridden op drops parent variable');
        self::assertTrue($rule->hasVariable('y'), 'override variable present');
    }

    public function testPerOpRemovalDropsInherited(): void
    {
        $set = $this->loadFrom([
            'versions' => [
                '1' => ['operations' => ['opA' => ['variables' => []], 'opB' => ['variables' => []]]],
                '2' => ['inherits' => 1, 'operations' => ['opA' => null]],
            ],
        ]);

        $v2 = $set->forVersionOrLatest(2);
        self::assertNotNull($v2);
        self::assertFalse($v2->hasOperation('opA'), 'removed op dropped');
        self::assertTrue($v2->hasOperation('opB'), 'sibling op retained');
    }

    public function testForVersionNullResolvesLatest(): void
    {
        $set = $this->loadFrom([
            'versions' => [
                '1' => ['operations' => ['only1' => ['variables' => []]]],
                '3' => ['operations' => ['only3' => ['variables' => []]]],
            ],
        ]);

        self::assertSame(3, $set->latestVersion());
        self::assertTrue($set->forVersionOrLatest(null)?->hasOperation('only3'));
    }

    public function testForVersionUnknownHighResolvesLatest(): void
    {
        $set = $this->loadFrom([
            'versions' => [
                '1' => ['operations' => ['only1' => ['variables' => []]]],
                '3' => ['operations' => ['only3' => ['variables' => []]]],
            ],
        ]);

        self::assertTrue($set->forVersionOrLatest(99)?->hasOperation('only3'));
    }

    public function testForVersionUnknownLowResolvesLatest(): void
    {
        $set = $this->loadFrom([
            'versions' => [
                '2' => ['operations' => ['only2' => ['variables' => []]]],
                '3' => ['operations' => ['only3' => ['variables' => []]]],
            ],
        ]);

        self::assertTrue($set->forVersionOrLatest(1)?->hasOperation('only3'));
    }

    public function testEmptySetForVersionReturnsNull(): void
    {
        $set = new RulesSet([]);
        self::assertTrue($set->isEmpty());
        self::assertNull($set->latestVersion());
        self::assertNull($set->forVersionOrLatest(null));
        self::assertNull($set->forVersionOrLatest(1));
    }
}
