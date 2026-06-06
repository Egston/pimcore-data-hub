<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service\RequestValidation;

use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need a single temporary file with automatic teardown.
 */
abstract class TempfileTestCase extends TestCase
{
    protected string $file;

    protected function setUp(): void
    {
        $this->file = (string)tempnam(sys_get_temp_dir(), 'rules_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    protected function writeRaw(string $contents, int $mtime): void
    {
        file_put_contents($this->file, $contents);
        touch($this->file, $mtime);
    }

    /**
     * @param array<string, mixed> $rules
     */
    protected function writeJson(array $rules): void
    {
        file_put_contents($this->file, (string)json_encode($rules));
    }
}
