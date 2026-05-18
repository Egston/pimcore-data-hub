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

namespace Pimcore\Bundle\DataHubBundle\Command;

use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Logger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'datahub:graphql:persistent-cache:clear',
    description: 'Evict every entry from the persistent GraphQL cache (bypasses SWR; use when mark-output-invalidated is not enough).',
)]
class PersistentCacheClearCommand extends Command
{
    public function __construct(private PersistentOutputCacheService $persistent)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
Drops every entry from the DataHub persistent GraphQL cache (payloads,
meta, indices, and per-op/per-client tag indices). The invalidation
watermark is preserved so the cache does not immediately re-mark every
freshly-written entry as FRESH.

When to use this instead of <info>:mark-output-invalidated</info>:

  * After a schema-rebuild / class-rebuild that changed which types are
    registered — cached responses may reference types that no longer exist.
  * When errors-only responses must be evicted immediately.
  * When the SWR refresh path itself is broken and stale entries persist.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $ok = $this->persistent->clearAll();
        } catch (\Throwable $e) {
            Logger::error(sprintf(
                'DataHub persistent cache: exception clearing tag %s: %s',
                PersistentOutputCacheService::TAG_COMMON,
                $e->getMessage()
            ));
            $output->writeln(sprintf(
                '<error>Failed to clear persistent-cache tag %s: %s</error>',
                PersistentOutputCacheService::TAG_COMMON,
                $e->getMessage()
            ));

            return Command::FAILURE;
        }

        if (!$ok) {
            Logger::error(sprintf(
                'DataHub persistent cache: backend reported failure clearing tag %s',
                PersistentOutputCacheService::TAG_COMMON
            ));
            $output->writeln(sprintf(
                '<error>Cache backend reported failure clearing tag %s.</error>',
                PersistentOutputCacheService::TAG_COMMON
            ));

            return Command::FAILURE;
        }

        $output->writeln('<info>Persistent GraphQL cache cleared.</info>');

        return Command::SUCCESS;
    }
}
