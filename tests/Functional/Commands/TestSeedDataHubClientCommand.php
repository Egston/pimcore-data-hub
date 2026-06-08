<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional\Commands;

use Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures\DataHubClientSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Bootstrapping CLI that seeds the `default` DataHub client for the L3 functional suite. */
#[AsCommand(
    name: 'pimcore-data-hub:test:seed-datahub-client',
    description: 'Seed the `default` DataHub GraphQL client config for the L3 functional-test namespace.',
)]
final class TestSeedDataHubClientCommand extends Command
{
    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
Idempotently creates (or recreates) the `default` DataHub GraphQL client
config in the running Pimcore instance.

The client enables the three L3 fixture classes (TestSwrGuardedItem,
TestSwrOnlyItem, TestUncachedItem), enforces datahub_apikey security with
the two L3 test keys, and grants read access to the object workspace root.

Prerequisite: the L3 cache must be warm so this command is registered
(<info>cache:warmup</info> must have been run in the same APP_ENV first).

The command fails-loud on save errors or if the client cannot be re-read
after save — the bootstrap script halts on failure before tests run.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $seeder = new DataHubClientSeeder();

        try {
            $seeder->seedDefaultClient();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>DataHub client seed failed: %s</error>', $e->getMessage()));
            if ($output->isVerbose()) {
                $output->writeln((string)$e);
            }

            return Command::FAILURE;
        }

        $output->writeln('<info>Seeded default DataHub client for L3 functional suite</info>');

        return Command::SUCCESS;
    }
}
