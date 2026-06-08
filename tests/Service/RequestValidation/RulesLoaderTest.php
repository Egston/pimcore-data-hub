<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service\RequestValidation;

use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RulesLoader;

final class RulesLoaderTest extends TempfileTestCase
{
    public function testEmptyPathReturnsNull(): void
    {
        $loader = new CapturingRulesLoader('');
        self::assertNull($loader->load());
    }

    public function testMissingFileReturnsNull(): void
    {
        $loader = new CapturingRulesLoader('/nonexistent/rules/file.json');
        self::assertNull($loader->load());
    }

    public function testLoadsValidFixture(): void
    {
        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['opA' => ['variables' => []]]]],
        ]), 1000);

        $loader = new CapturingRulesLoader($this->file);
        $set = $loader->load();
        self::assertNotNull($set);
        self::assertTrue($set->forVersionOrLatest(1)?->hasOperation('opA'));
        self::assertSame([], $loader->errors);
    }

    public function testUnchangedMtimeDoesNotReparse(): void
    {
        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['original' => ['variables' => []]]]],
        ]), 2000);

        $loader = new CapturingRulesLoader($this->file);
        $first = $loader->load();
        self::assertTrue($first?->forVersionOrLatest(1)?->hasOperation('original'));

        // Rewrite content but keep the same mtime: a re-parse would pick up the
        // new content, the mtime cache must not.
        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['rewritten' => ['variables' => []]]]],
        ]), 2000);

        $second = $loader->load();
        self::assertTrue($second?->forVersionOrLatest(1)?->hasOperation('original'));
        self::assertFalse($second->forVersionOrLatest(1)?->hasOperation('rewritten'));
    }

    public function testChangedMtimeTriggersReparse(): void
    {
        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['original' => ['variables' => []]]]],
        ]), 3000);

        $loader = new CapturingRulesLoader($this->file);
        $loader->load();

        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['rewritten' => ['variables' => []]]]],
        ]), 4000);

        $reloaded = $loader->load();
        self::assertTrue($reloaded?->forVersionOrLatest(1)?->hasOperation('rewritten'));
        self::assertFalse($reloaded->forVersionOrLatest(1)?->hasOperation('original'));
    }

    public function testParseFailureRetainsLastKnownGoodAndLogsError(): void
    {
        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['good' => ['variables' => []]]]],
        ]), 5000);

        $loader = new CapturingRulesLoader($this->file);
        $good = $loader->load();
        self::assertTrue($good?->forVersionOrLatest(1)?->hasOperation('good'));

        $this->writeRaw('{ this is not valid json', 6000);
        $retained = $loader->load();

        self::assertTrue($retained?->forVersionOrLatest(1)?->hasOperation('good'), 'last-known-good retained');
        self::assertCount(1, $loader->errors);
        self::assertSame(RulesLoader::LOG_SLUG, $loader->errors[0]['slug']);
        self::assertTrue($loader->errors[0]['context']['retained_last_known_good']);
    }

    public function testFirstLoadFailureReturnsNull(): void
    {
        $this->writeRaw('{ broken', 7000);

        $loader = new CapturingRulesLoader($this->file);
        self::assertNull($loader->load(), 'first-load failure fails to no-op, not to deny');
        self::assertCount(1, $loader->errors);
        self::assertFalse($loader->errors[0]['context']['retained_last_known_good']);
    }

    public function testSchemaInvalidUnknownKindFirstLoadReturnsNull(): void
    {
        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['opA' => ['variables' => ['x' => ['kind' => 'bogus']]]]]],
        ]), 8000);

        $loader = new CapturingRulesLoader($this->file);
        self::assertNull($loader->load());
        self::assertCount(1, $loader->errors);
    }

    public function testLoaderIsRulesLoaderSubclass(): void
    {
        self::assertInstanceOf(RulesLoader::class, new CapturingRulesLoader(''));
    }

    public function testFilemtimeFailureColdLogsAndReturnsNull(): void
    {
        $this->writeRaw('{}', 9000);

        $loader = new class($this->file) extends CapturingRulesLoader {
            protected function readMtime(string $path): int|false
            {
                return false;
            }
        };

        self::assertNull($loader->load(), 'cold filemtime failure must return null');
        self::assertCount(1, $loader->errors);
        self::assertSame(RulesLoader::LOG_SLUG, $loader->errors[0]['slug']);
        self::assertFalse($loader->errors[0]['context']['retained_last_known_good']);
    }

    public function testFilemtimeFailureWarmRetainsLastKnownGoodAndLogs(): void
    {
        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['opA' => ['variables' => []]]]],
        ]), 1000);

        $loader = new class($this->file) extends CapturingRulesLoader {
            public bool $forceFail = false;

            protected function readMtime(string $path): int|false
            {
                return $this->forceFail ? false : parent::readMtime($path);
            }
        };

        $good = $loader->load();
        self::assertTrue($good?->forVersionOrLatest(1)?->hasOperation('opA'));
        self::assertSame([], $loader->errors);

        $loader->forceFail = true;
        $retained = $loader->load();

        self::assertTrue($retained?->forVersionOrLatest(1)?->hasOperation('opA'), 'last-known-good retained');
        self::assertCount(1, $loader->errors);
        self::assertSame(RulesLoader::LOG_SLUG, $loader->errors[0]['slug']);
        self::assertTrue($loader->errors[0]['context']['retained_last_known_good']);
    }

    public function testWarmParseFailureLogsOncePerMtime(): void
    {
        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['good' => ['variables' => []]]]],
        ]), 5000);

        $loader = new CapturingRulesLoader($this->file);
        $loader->load();

        // Introduce a bad file at a new mtime.
        $this->writeRaw('{ bad json', 6000);
        $loader->load();
        $loader->load();
        $loader->load();

        self::assertCount(1, $loader->errors, 'repeated warm failure with same mtime logs once');
        self::assertSame(RulesLoader::LOG_SLUG, $loader->errors[0]['slug']);

        // A different bad mtime triggers one more log entry.
        $this->writeRaw('{ still bad', 7000);
        $loader->load();
        $loader->load();

        self::assertCount(2, $loader->errors, 'new mtime produces one additional error log');
    }

    public function testWarmParseRecoveryRearmsErrorSignal(): void
    {
        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['good' => ['variables' => []]]]],
        ]), 5000);

        $loader = new CapturingRulesLoader($this->file);
        $loader->load();

        // Bad file — should log once.
        $this->writeRaw('{ bad', 6000);
        $loader->load();
        self::assertCount(1, $loader->errors);

        // Recover.
        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['recovered' => ['variables' => []]]]],
        ]), 7000);
        $recovered = $loader->load();
        self::assertTrue($recovered?->forVersionOrLatest(1)?->hasOperation('recovered'));
        self::assertSame(7000, $loader->getLoadedMtime());

        // Bad file again — signal must re-arm after the recovery.
        $this->writeRaw('{ bad again', 8000);
        $loader->load();
        self::assertCount(2, $loader->errors, 'error signal re-arms after a successful parse');
    }

    public function testFilemtimeFailureLogsOncePerSession(): void
    {
        $this->writeRaw((string)json_encode([
            'versions' => ['1' => ['operations' => ['opA' => ['variables' => []]]]],
        ]), 1000);

        $loader = new class($this->file) extends CapturingRulesLoader {
            public bool $forceFail = false;

            protected function readMtime(string $path): int|false
            {
                return $this->forceFail ? false : parent::readMtime($path);
            }
        };

        $loader->load();
        $loader->forceFail = true;

        $loader->load();
        $loader->load();
        $loader->load();

        self::assertCount(1, $loader->errors, 'filemtime failure logs once per failing session');
    }
}
