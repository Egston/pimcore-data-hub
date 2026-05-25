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

use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Factory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subprocess probe used by ConcurrencyTest: boots Pimcore, fires a single
 * SWR_ONLY cold-miss GraphQL request, and prints one of three markers on stdout:
 *
 *   won-lock-inline    — this process acquired the cold-miss lock and ran the resolver
 *   observed-write     — this process lost the lock, polled, and observed the winner's write
 *   defensive-fallback — this process lost the lock and fell through to inline after timeout
 *
 * Exit 0 on a successful marker emission; exit 1 on any setup or execution
 * failure (including a 200 SWR_ONLY response that emitted no structured event,
 * which is a contract violation). The marker is derived from the
 * `swr.cold_miss.lock.*` events observed on the `monolog.logger.pimcore`
 * channel — the probe runs as its own PHP process so it must wire its own
 * Monolog TestHandler onto the booted kernel's logger channel; the parent
 * test's handler push is invisible across the process boundary.
 */
#[AsCommand(
    name: 'pimcore-data-hub:test:cold-miss-probe',
    description: 'L3 concurrency probe: fire a single SWR_ONLY cold-miss request and print the lock-path outcome marker.',
)]
final class TestColdMissProbeCommand extends Command
{
    public const MARKER_WON_LOCK_INLINE = 'won-lock-inline';

    public const MARKER_OBSERVED_WRITE = 'observed-write';

    public const MARKER_DEFENSIVE_FALLBACK = 'defensive-fallback';

    public const MARKER_GRAPHQL_ERROR = 'graphql-error';

    public function __construct(
        private readonly WebserviceController $controller,
        private readonly \Pimcore\Bundle\DataHubBundle\GraphQL\Service $graphQlService,
        private readonly LocaleServiceInterface $localeService,
        private readonly Factory $modelFactory,
        private readonly LongRunningHelper $longRunningHelper,
        private readonly ResponseServiceInterface $responseService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('operation', null, InputOption::VALUE_REQUIRED, 'GraphQL operation name to probe', 'getTestSwrOnlyItemListing')
            ->addOption('clientname', null, InputOption::VALUE_REQUIRED, 'Pimcore DataHub client name', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operation = (string)$input->getOption('operation');
        $clientname = (string)$input->getOption('clientname');

        $handler = new TestHandler();
        $logger = \Pimcore::getContainer()->get('monolog.logger.pimcore');
        if (!$logger instanceof MonologLogger) {
            $output->writeln('error: monolog.logger.pimcore is not a MonologLogger — probe cannot observe structured events');

            return Command::FAILURE;
        }
        $logger->pushHandler($handler);
        $handler->reset();

        $query = sprintf('query %s { %s { edges { node { id } } } }', $operation, $operation);
        $body = json_encode([
            'operationName' => $operation,
            'query' => $query,
            'variables' => (object)[],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $request = Request::create(
            '/datahub/graphql/' . $clientname,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body
        );
        $request->attributes->set('clientname', $clientname);

        $response = $this->controller->webonyxAction(
            $this->graphQlService,
            $this->localeService,
            $this->modelFactory,
            $request,
            $this->longRunningHelper,
            $this->responseService
        );

        if (!$response instanceof JsonResponse) {
            $output->writeln('error: webonyxAction did not return a JsonResponse');

            return Command::FAILURE;
        }

        if ($response->getStatusCode() !== 200) {
            $output->writeln(self::MARKER_GRAPHQL_ERROR);

            return Command::FAILURE;
        }

        $responseData = json_decode((string)$response->getContent(), true);
        if (is_array($responseData) && isset($responseData['errors'])) {
            $output->writeln(self::MARKER_GRAPHQL_ERROR);

            return Command::FAILURE;
        }

        $kernel = \Pimcore::getKernel();
        $eventDispatcher = \Pimcore::getContainer()->get('event_dispatcher');
        $terminateEvent = new TerminateEvent($kernel, $request, $response);
        $eventDispatcher->dispatch($terminateEvent, KernelEvents::TERMINATE);

        $messages = array_map(
            static fn (array $record): string => (string)($record['message'] ?? ''),
            $handler->getRecords()
        );

        if (in_array('swr.cold_miss.lock.acquired', $messages, true)) {
            $output->writeln(self::MARKER_WON_LOCK_INLINE);

            return Command::SUCCESS;
        }
        if (in_array('swr.cold_miss.lock.observed_write', $messages, true)) {
            $output->writeln(self::MARKER_OBSERVED_WRITE);

            return Command::SUCCESS;
        }
        if (in_array('swr.cold_miss.lock.timeout_fallback', $messages, true)) {
            $output->writeln(self::MARKER_DEFENSIVE_FALLBACK);

            return Command::SUCCESS;
        }

        $output->writeln(self::MARKER_GRAPHQL_ERROR);

        return Command::FAILURE;
    }
}
