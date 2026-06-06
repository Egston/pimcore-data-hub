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

        $tag = $counts['evict_failed'] > 0 ? 'comment' : 'info';
        $output->writeln(sprintf(
            '<%1$s>Sweep complete: scanned=%2$d evicted=%3$d skipped_malformed=%4$d evict_failed=%5$d not_enforced=%6$d passed=%7$d validate_failed=%8$d</%1$s>',
            $tag,
            $counts['scanned'],
            $counts['evicted'],
            $counts['skipped_malformed'],
            $counts['evict_failed'],
            $counts['not_enforced'],
            $counts['passed'],
            $counts['validate_failed'],
        ));

        return $counts['evict_failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
