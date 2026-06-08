<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Command;

use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\PersistentCacheRuleSweep;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $counts = $this->sweep->sweep();

        $output->writeln($counts->summaryLine());

        return $counts->evictFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
