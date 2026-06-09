<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Command;

use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\PersistentCacheRuleSweep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'datahub:graphql:persistent-cache:purge-invalid',
    description: 'Evict persistent GraphQL cache entries that no longer conform to the current request-validation rules.',
)]
class PersistentCachePurgeInvalidCommand extends Command
{
    public function __construct(private readonly PersistentCacheRuleSweep $sweep)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report how many entries would be evicted without removing any.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');

        $counts = $this->sweep->sweep($dryRun);

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN — no entries removed; "evicted" is the would-evict count.</comment>');
        }
        $output->writeln($counts->summaryLine());

        return $counts->evictFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
