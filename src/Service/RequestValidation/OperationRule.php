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
 * The allowed variable map for a single operation. A variable key present in
 * a request but absent from this map is rejected (default-deny); a declared
 * variable is checked against its constraint. Kernel-free value object.
 */
final class OperationRule
{
    /**
     * @param array<string, VariableConstraint> $variables
     */
    public function __construct(private readonly array $variables)
    {
    }

    public function hasVariable(string $name): bool
    {
        return isset($this->variables[$name]);
    }

    /**
     * @return array<string, VariableConstraint>
     */
    public function variables(): array
    {
        return $this->variables;
    }
}
