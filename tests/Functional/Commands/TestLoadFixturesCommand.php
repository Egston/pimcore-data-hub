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

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional\Commands;

use Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures\FixtureLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Bootstrapping CLI for the L3 functional-test fixtures. */
#[AsCommand(
    name: 'pimcore-data-hub:test:load-fixtures',
    description: 'Load the L3 functional-test fixture data (TestSwrGuardedItem / TestSwrOnlyItem / TestUncachedItem) into the running Pimcore instance.',
)]
final class TestLoadFixturesCommand extends Command
{
    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
Idempotently loads the bundle's L3 fixture data files into the running
Pimcore instance. Each fixture file (swr-guarded-items.json,
swr-only-items.json, uncached-items.json) is loaded against its declared
parent folder, with the parent's existing children purged before reload.

Prerequisite: <info>pimcore:class-definitions:import</info> must have been
run against the three class-definition JSON files shipped under
<info>tests/Functional/Fixtures/class-definitions/</info> first.

The command fails-loud on the first malformed fixture / missing class /
save error and surfaces the underlying exception so the bootstrap script
that drives it can halt the L3 namespace stand-up before tests run.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $loader = new FixtureLoader();

        try {
            $created = $loader->loadAll();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>L3 fixture load failed: %s</error>', $e->getMessage()));
            if ($output->isVerbose()) {
                $output->writeln((string)$e);
            }

            return Command::FAILURE;
        }

        foreach ($created as $className => $ids) {
            $output->writeln(sprintf(
                '<info>Loaded %d fixture object(s) for class %s</info>',
                count($ids),
                $className
            ));
        }

        return Command::SUCCESS;
    }
}
