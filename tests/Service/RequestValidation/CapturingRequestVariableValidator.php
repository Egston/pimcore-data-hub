<?php

declare(strict_types=1);

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
