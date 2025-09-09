<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Command;

use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'datahub:graphql:persistent-cache:mark-output-invalidated', description: 'Mark persistent GraphQL cache as stale by updating the last output invalidation timestamp')]
class PersistentCacheMarkInvalidatedCommand extends Command
{
    public function __construct(private PersistentOutputCacheService $persistent)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->persistent->markOutputInvalidated();
        $output->writeln('<info>Marked persistent GraphQL cache as stale (timestamp updated).</info>');
        return Command::SUCCESS;
    }
}

