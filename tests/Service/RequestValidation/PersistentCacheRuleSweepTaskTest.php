<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service\RequestValidation;

use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\PersistentCacheRuleSweep;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\PersistentCacheRuleSweepTask;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RulesLoader;

final class PersistentCacheRuleSweepTaskTest extends TempfileTestCase
{
    private const CLIENT = 'public-content';

    private function makeRulesLoader(): CapturingRulesLoader
    {
        $rules = [
            'versions' => [
                '1' => [
                    'operations' => [
                        'AllowedOp' => ['variables' => []],
                    ],
                ],
            ],
        ];
        $this->writeJson($rules);
        $loader = new CapturingRulesLoader($this->file);
        $loader->load();

        return $loader;
    }

    /**
     * Subclass that exposes the stamp store without calling Pimcore\Cache.
     */
    private function makeTask(
        PersistentCacheRuleSweep $sweep,
        RulesLoader $rulesLoader,
        array $enforcedClients,
        ?string $storedStamp,
        ?string &$savedStamp,
    ): PersistentCacheRuleSweepTask {
        return new class($sweep, $rulesLoader, $enforcedClients, $storedStamp, $savedStamp) extends PersistentCacheRuleSweepTask {
            public function __construct(
                PersistentCacheRuleSweep $sweep,
                RulesLoader $rulesLoader,
                array $enforcedClients,
                private readonly ?string $storedStamp,
                private ?string &$savedStamp,
            ) {
                parent::__construct($sweep, $rulesLoader, $enforcedClients);
            }

            protected function stampLoad(): mixed
            {
                return $this->storedStamp;
            }

            protected function stampSave(string $stamp): void
            {
                $this->savedStamp = $stamp;
            }
        };
    }

    public function testSkipsWhenRulesAreNull(): void
    {
        $rulesLoader = new CapturingRulesLoader('');
        $sweep = $this->createMock(PersistentCacheRuleSweep::class);
        $sweep->expects(self::never())->method('sweep');

        $savedStamp = null;
        $task = $this->makeTask($sweep, $rulesLoader, [self::CLIENT], null, $savedStamp);
        $task->execute();

        self::assertNull($savedStamp);
    }

    public function testSkipsWhenStampUnchanged(): void
    {
        $rulesLoader = $this->makeRulesLoader();

        $sweepFirst = $this->createMock(PersistentCacheRuleSweep::class);
        $sweepFirst->method('sweep')->willReturn(['scanned' => 2, 'evicted' => 0, 'skipped_malformed' => 0, 'evict_failed' => 0, 'not_enforced' => 0, 'passed' => 2, 'validate_failed' => 0]);

        $savedStamp = null;
        $helper = $this->makeTask($sweepFirst, $rulesLoader, [self::CLIENT], null, $savedStamp);
        $helper->execute();

        $firstStamp = $savedStamp;
        self::assertNotNull($firstStamp);

        $sweepSecond = $this->createMock(PersistentCacheRuleSweep::class);
        $sweepSecond->expects(self::never())->method('sweep');

        $savedStamp2 = null;
        $task = $this->makeTask($sweepSecond, $rulesLoader, [self::CLIENT], $firstStamp, $savedStamp2);
        $task->execute();

        self::assertNull($savedStamp2);
    }

    public function testSweepsWhenStampChanges(): void
    {
        $rulesLoader = $this->makeRulesLoader();
        $sweep = $this->createMock(PersistentCacheRuleSweep::class);
        $sweep->expects(self::once())
            ->method('sweep')
            ->willReturn(['scanned' => 3, 'evicted' => 1, 'skipped_malformed' => 0, 'evict_failed' => 0, 'not_enforced' => 0, 'passed' => 2, 'validate_failed' => 0]);

        $savedStamp = null;
        $task = $this->makeTask($sweep, $rulesLoader, [self::CLIENT], 'old-stamp-value', $savedStamp);
        $task->execute();

        self::assertNotNull($savedStamp);
        self::assertNotSame('old-stamp-value', $savedStamp);
    }

    public function testSweepsWhenNoPreviousStamp(): void
    {
        $rulesLoader = $this->makeRulesLoader();
        $sweep = $this->createMock(PersistentCacheRuleSweep::class);
        $sweep->expects(self::once())
            ->method('sweep')
            ->willReturn(['scanned' => 1, 'evicted' => 0, 'skipped_malformed' => 0, 'evict_failed' => 0, 'not_enforced' => 0, 'passed' => 1, 'validate_failed' => 0]);

        $savedStamp = null;
        $task = $this->makeTask($sweep, $rulesLoader, [self::CLIENT], null, $savedStamp);
        $task->execute();

        self::assertNotNull($savedStamp);
    }

    public function testZeroScanDoesNotStamp(): void
    {
        $rulesLoader = $this->makeRulesLoader();
        $sweep = $this->createMock(PersistentCacheRuleSweep::class);
        $sweep->method('sweep')->willReturn(['scanned' => 0, 'evicted' => 0, 'skipped_malformed' => 0, 'evict_failed' => 0, 'not_enforced' => 0, 'passed' => 0, 'validate_failed' => 0]);

        $savedStamp = null;
        $task = $this->makeTask($sweep, $rulesLoader, [self::CLIENT], null, $savedStamp);
        $task->execute();

        self::assertNull($savedStamp);
    }

    public function testEvictFailedDoesNotStamp(): void
    {
        $rulesLoader = $this->makeRulesLoader();
        $sweep = $this->createMock(PersistentCacheRuleSweep::class);
        $sweep->method('sweep')->willReturn(['scanned' => 3, 'evicted' => 1, 'skipped_malformed' => 0, 'evict_failed' => 1, 'not_enforced' => 0, 'passed' => 1, 'validate_failed' => 0]);

        $savedStamp = null;
        $task = $this->makeTask($sweep, $rulesLoader, [self::CLIENT], null, $savedStamp);
        $task->execute();

        self::assertNull($savedStamp);
    }

    public function testSweepThrowDoesNotStamp(): void
    {
        $rulesLoader = $this->makeRulesLoader();
        $sweep = $this->createMock(PersistentCacheRuleSweep::class);
        $sweep->method('sweep')->willThrowException(new \RuntimeException('backend fault'));

        $savedStamp = null;
        $task = $this->makeTask($sweep, $rulesLoader, [self::CLIENT], null, $savedStamp);
        $task->execute();

        self::assertNull($savedStamp);
    }

    public function testStampIncludesEnforcedClients(): void
    {
        $rulesLoader = $this->makeRulesLoader();
        $sweep = $this->createMock(PersistentCacheRuleSweep::class);
        $sweep->method('sweep')->willReturn(['scanned' => 1, 'evicted' => 0, 'skipped_malformed' => 0, 'evict_failed' => 0, 'not_enforced' => 0, 'passed' => 1, 'validate_failed' => 0]);

        $savedStampA = null;
        $savedStampB = null;

        $taskA = $this->makeTask($sweep, $rulesLoader, ['client-a'], null, $savedStampA);
        $taskA->execute();

        $taskB = $this->makeTask($sweep, $rulesLoader, ['client-b'], null, $savedStampB);
        $taskB->execute();

        self::assertNotNull($savedStampA);
        self::assertNotNull($savedStampB);
        self::assertNotSame($savedStampA, $savedStampB);
    }
}
