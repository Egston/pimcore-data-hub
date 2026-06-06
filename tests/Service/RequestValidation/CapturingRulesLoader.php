<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service\RequestValidation;

use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RulesLoader;

/**
 * Test seam capturing the loader's ERROR side-effect, which otherwise routes
 * through Pimcore\Logger (a no-op without a booted container).
 *
 * @phpstan-type LogEntry array{slug: string, context: array<string, mixed>}
 */
class CapturingRulesLoader extends RulesLoader
{
    /** @var list<array{slug: string, context: array<string, mixed>}> */
    public array $errors = [];

    protected function logError(string $slug, array $context): void
    {
        $this->errors[] = ['slug' => $slug, 'context' => $context];
    }
}
