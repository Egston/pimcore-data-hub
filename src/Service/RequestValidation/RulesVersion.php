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

namespace Pimcore\Bundle\DataHubBundle\Service\RequestValidation;

/**
 * One rules version's already-flattened operation allowlist. Inheritance
 * (parent merge, per-op override, per-op removal) is resolved at load time by
 * RulesLoader before this object is constructed; the validator never walks the
 * chain at request time. Kernel-free value object.
 */
final class RulesVersion
{
    /**
     * @param array<string, OperationRule> $operations
     */
    public function __construct(private readonly array $operations)
    {
    }

    public function hasOperation(string $operationName): bool
    {
        return isset($this->operations[$operationName]);
    }

    public function operationRule(string $operationName): ?OperationRule
    {
        return $this->operations[$operationName] ?? null;
    }
}
