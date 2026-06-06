<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Command;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PersistentCacheClearCommandTest extends TestCase
{
    public function testExecuteReturnsSuccessWhenClearAllSucceeds(): void
    {
        $service = $this->createMock(PersistentOutputCacheService::class);
        $service->method('clearAll')->willReturn(true);

        $tester = new CommandTester(new PersistentCacheClearCommand($service));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Persistent GraphQL cache cleared.', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureWhenClearAllReturnsFalse(): void
    {
        $service = $this->createMock(PersistentOutputCacheService::class);
        $service->method('clearAll')->willReturn(false);

        $tester = new CommandTester(new PersistentCacheClearCommand($service));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(PersistentOutputCacheService::TAG_COMMON, $tester->getDisplay());
    }

    public function testExecuteReturnsFailureOnClearAllException(): void
    {
        $service = $this->createMock(PersistentOutputCacheService::class);
        $service->method('clearAll')->willThrowException(new \RuntimeException('Redis unreachable'));

        $tester = new CommandTester(new PersistentCacheClearCommand($service));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString(PersistentOutputCacheService::TAG_COMMON, $display);
        $this->assertStringContainsString('Redis unreachable', $display);
    }
}
