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

use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQLService;
use Pimcore\Bundle\DataHubBundle\Service\FrontendRequestScope;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Factory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsCommand(name: 'datahub:graphql:persistent-cache:refresh', description: 'Refresh persistent GraphQL cache by executing a GraphQL request')]
class PersistentCacheRefreshCommand extends Command
{
    public function __construct(
        private WebserviceController $controller,
        private GraphQLService $graphQlService,
        private LocaleServiceInterface $localeService,
        private Factory $modelFactory,
        private LongRunningHelper $longRunningHelper,
        private ResponseServiceInterface $responseService,
        private ?RequestStack $requestStack = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('client', InputArgument::REQUIRED, 'DataHub client name')
            ->addOption('operation', null, InputOption::VALUE_OPTIONAL, 'Operation name (optional)')
            ->addOption('query', null, InputOption::VALUE_OPTIONAL, 'GraphQL query (string)')
            ->addOption('variables', null, InputOption::VALUE_OPTIONAL, 'Variables as JSON string')
            ->addOption('body-file', null, InputOption::VALUE_OPTIONAL, 'Path to file containing raw JSON GraphQL body');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = (string)$input->getArgument('client');
        $bodyFile = $input->getOption('body-file');
        $query = $input->getOption('query');
        $variables = $input->getOption('variables');
        $operation = $input->getOption('operation');

        $payload = null;
        if ($bodyFile) {
            if (!is_file($bodyFile)) {
                $output->writeln('<error>Body file not found: ' . $bodyFile . '</error>');

                return Command::FAILURE;
            }
            $payload = file_get_contents($bodyFile) ?: '';
        } else {
            $data = [
                'query' => (string)$query,
            ];
            if ($variables) {
                $vars = json_decode((string)$variables, true);
                if (!is_array($vars)) {
                    $output->writeln('<error>Invalid variables JSON</error>');

                    return Command::FAILURE;
                }
                $data['variables'] = $vars;
            }
            if ($operation) {
                $data['operationName'] = (string)$operation;
            }
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $request = Request::create('/datahub/graphql', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string)$payload);
        $request->attributes->set('clientname', $client);
        // This command is "refresh persistent" — bypass herd guard so the in-progress
        // marker can't 503 us, and signal preHandle to skip its short-circuit.
        $request->attributes->set('_datahub_persistent_refresh', true);
        $request->attributes->set('_datahub_bypass_in_progress_guard', true);

        $response = FrontendRequestScope::run($this->requestStack, $request, fn () => $this->controller->webonyxAction(
            $this->graphQlService,
            $this->localeService,
            $this->modelFactory,
            $request,
            $this->longRunningHelper,
            $this->responseService
        ));

        $status = $response->getStatusCode();
        $body = json_decode((string)$response->getContent(), true);
        if ($status < 200 || $status >= 300) {
            $output->writeln(sprintf('<error>GraphQL request returned HTTP %d for client: %s</error>', $status, $client));

            return Command::FAILURE;
        }
        if (is_array($body) && !empty($body['errors'])) {
            $messages = array_column((array)$body['errors'], 'message');
            $output->writeln(sprintf(
                '<error>GraphQL request returned errors for client %s: %s</error>',
                $client,
                json_encode($messages)
            ));

            return Command::FAILURE;
        }

        $output->writeln('<info>Executed GraphQL request for client: ' . $client . '</info>');

        return Command::SUCCESS;
    }
}
