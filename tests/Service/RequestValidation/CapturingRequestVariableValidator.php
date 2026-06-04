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

use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RequestVariableValidator;

/**
 * Test seam capturing the validator's reject WARNING side-effect, which
 * otherwise routes through Pimcore\Logger (a no-op without a booted container).
 */
final class CapturingRequestVariableValidator extends RequestVariableValidator
{
    /** @var list<array{slug: string, context: array<string, mixed>}> */
    public array $warnings = [];

    protected function logWarning(string $slug, array $context): void
    {
        $this->warnings[] = ['slug' => $slug, 'context' => $context];
    }
}
