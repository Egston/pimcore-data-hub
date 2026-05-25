<?php

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

namespace Pimcore\Bundle\DataHubBundle\Controller;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\Warning;
use GraphQL\GraphQL;
use GraphQL\Server\RequestError;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\ExecutorEvents;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\ExecutorEvent;
use Pimcore\Bundle\DataHubBundle\Event\GraphQL\Model\ExecutorResultEvent;
use Pimcore\Bundle\DataHubBundle\GraphQL\ClassTypeDefinitions;
use Pimcore\Bundle\DataHubBundle\GraphQL\Mutation\MutationType;
use Pimcore\Bundle\DataHubBundle\GraphQL\Query\QueryType;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service;
use Pimcore\Bundle\DataHubBundle\PimcoreDataHubBundle;
use Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService;
use Pimcore\Bundle\DataHubBundle\Service\FileUploadService;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\OutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Bundle\DataHubBundle\Service\Tier;
use Pimcore\Cache\RuntimeCache;
use Pimcore\Controller\FrontendController;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Logger;
use Pimcore\Model\Factory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WebserviceController extends FrontendController
{
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var CheckConsumerPermissionsService
     */
    private $permissionsService;

    /**
     * @var OutputCacheService
     */
    private $cacheService;

    /**
     * @var PersistentOutputCacheService
     */
    private $persistentCacheService;

    /**
     * @var FileUploadService
     */
    private $uploadService;

    /**
     * @var OperationClassifier
     */
    private $operationClassifier;

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        CheckConsumerPermissionsService $permissionsService,
        OutputCacheService $cacheService,
        PersistentOutputCacheService $persistentCacheService,
        FileUploadService $uploadService,
        OperationClassifier $operationClassifier
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->permissionsService = $permissionsService;
        $this->cacheService = $cacheService;
        $this->persistentCacheService = $persistentCacheService;
        $this->uploadService = $uploadService;
        $this->operationClassifier = $operationClassifier;
    }

    /**
     *
     * @return JsonResponse
     *
     * @throws RequestError|\Exception
     */
    public function webonyxAction(
        Service $service,
        LocaleServiceInterface $localeService,
        Factory $modelFactory,
        Request $request,
        LongRunningHelper $longRunningHelper,
        ResponseServiceInterface $responseService
    ) {
        $clientname = $request->attributes->getString('clientname');
        $variableValues = null;

        $configuration = Configuration::getByName($clientname);
        if (!$configuration || !$configuration->isActive()) {
            throw new NotFoundHttpException('No active configuration found for ' . $clientname);
        }

        if (!$this->permissionsService->performSecurityCheck($request, $configuration)) {
            throw new AccessDeniedHttpException('Permission denied, apikey not valid');
        }

        $input = json_decode($request->getContent(), true) ?: [];
        $operationName = is_string($input['operationName'] ?? null) ? $input['operationName'] : null;

        $tier = $operationName !== null
            ? $this->operationClassifier->getTier($operationName)
            : Tier::NEITHER;
        $request->attributes->set('_datahub_tier', $tier->value);

        // Lightweight cache-status probe: HEAD or cache_status=1
        $isStatusProbe = strtoupper($request->getMethod()) === 'HEAD' || $request->query->getBoolean('cache_status');
        if ($isStatusProbe) {
            $outStatus = $this->cacheService->probeStatus($request);
            $persistProbe = $this->persistentCacheService->probeStatus($request);
            $response = new JsonResponse(null, 204);
            $cacheStatus = sprintf('pimcore-output; %s', strtolower($outStatus));
            if ($persistProbe['applies']) {
                $cacheStatus .= sprintf(', pimcore-persistent; %s', strtolower($persistProbe['status']));
                $response->headers->set('X-Pimcore-DataHub-Persistent-Cache', $persistProbe['status']);
                if ($persistProbe['status'] === 'STALE') {
                    $response->headers->set('Warning', '110 - "Response is Stale"');
                }
            }
            $response->headers->set('Cache-Status', $cacheStatus);
            $responseService->addCorsHeaders($response);
            $responseService->addHitMissHeaders($response, $outStatus === 'HIT');

            return $response;
        }

        // Herd guard runs ahead of the persistent-cache layer for HERD_GUARDED
        // operations — a STALE persistent HIT would otherwise let concurrent
        // callers each spawn an unprotected refresh. Refresh sub-requests
        // carry _datahub_bypass_in_progress_guard so they don't 503 themselves.
        if ($tier === Tier::HERD_GUARDED
            && !$request->attributes->get('_datahub_bypass_in_progress_guard')
        ) {
            if ($inProgressResponse = $this->cacheService->maybeRejectOrAcquire($request)) {
                Logger::debug(sprintf('In-progress (early): duplicate blocked (operationName=%s, status=%d)', (string)$operationName, $inProgressResponse->getStatusCode()));
                $responseService->addCorsHeaders($inProgressResponse);

                return $inProgressResponse;
            }
        }

        // Persistent cache pre-check: may short-circuit or mark for background refresh
        if ($pResponse = $this->persistentCacheService->preHandle($request, $responseService)) {
            // Persistent HIT (fresh). Add output-cache header as MISS to clarify layer used
            $responseService->addHitMissHeaders($pResponse, true);

            return $pResponse;
        }

        // Optionally bypass standard output cache when persistent applies to this request
        $configAll = $this->getParameter('pimcore_data_hub');
        $graphqlCfg = $configAll['graphql'] ?? [];
        $skipOutputCacheForGuarded = (bool)($graphqlCfg['persistent_disable_output_cache_for_guarded'] ?? false);

        // When running a background refresh, bypass the standard output cache layer
        $isPersistentRefresh = (bool)$request->attributes->get('_datahub_persistent_refresh');
        $isPersistentApplies = (bool)$request->attributes->get('_datahub_persistent_applies');
        $skipOutputCache = $isPersistentRefresh || ($skipOutputCacheForGuarded && $isPersistentApplies);

        if (!$skipOutputCache && ($response = $this->cacheService->load($request))) {
            Logger::debug('Output cache HIT');

            $responseService->addCorsHeaders($response);
            $responseService->addHitMissHeaders($response, true);

            return $response;
        }

        Logger::debug('Output cache MISS');

        // Try to acquire an in-progress marker for selected protected queries to avoid thundering herd.
        // HERD_GUARDED operations already ran the early-gate above; this fallback handles the legacy
        // in_progress_queries-only path (operation absent from the new `operations:` tree).
        if ($tier !== Tier::HERD_GUARDED) {
            if ($inProgressResponse = $this->cacheService->maybeRejectOrAcquire($request)) {
                Logger::debug(sprintf('In-progress: duplicate blocked (operationName=%s, status=%d)', (string)$operationName, $inProgressResponse->getStatusCode()));
                $responseService->addCorsHeaders($inProgressResponse);

                return $inProgressResponse;
            }
        }

        // If we get here and a lock attribute exists, we acquired the protection lock
        if ($request->attributes->get('datahub_inprogress_lock')) {
            Logger::debug(sprintf('In-progress: lock ACQUIRED (operationName=%s)', (string)$operationName));
        } else {
            Logger::debug('In-progress: no protection (not enabled or query not listed)');
        }

        // context info, will be passed on to all resolver function
        $context = ['clientname' => $clientname, 'configuration' => $configuration];

        $config = $this->getParameter('pimcore_data_hub');

        if (isset($config['graphql']) && isset($config['graphql']['not_allowed_policy'])) {
            PimcoreDataHubBundle::setNotAllowedPolicy($config['graphql']['not_allowed_policy']);
        }

        $longRunningHelper->addPimcoreRuntimeCacheProtectedItems([PimcoreDataHubBundle::RUNTIME_CONTEXT_KEY]);
        RuntimeCache::set(PimcoreDataHubBundle::RUNTIME_CONTEXT_KEY, $context);

        ClassTypeDefinitions::build($service, $context);

        $queryType = new QueryType($service, $localeService, $modelFactory, $this->eventDispatcher, [], $context);
        $mutationType = new MutationType($service, $localeService, $modelFactory, $this->eventDispatcher, [], $context);

        try {
            $schemaConfig = [
                'query' => $queryType,
            ];
            if (!$mutationType->isEmpty()) {
                $schemaConfig['mutation'] = $mutationType;
            }
            $schema = new \GraphQL\Type\Schema(
                $schemaConfig
            );
        } catch (\Exception $e) {
            Warning::enable(false);
            $schema = new \GraphQL\Type\Schema(
                [
                    'query' => $queryType,
                    'mutation' => $mutationType,
                ]
            );
            $schema->assertValid();
            Logger::error($e);

            throw $e;
        }

        $contentType = $request->headers->get('content-type') ?? '';

        if (mb_stripos($contentType, 'multipart/form-data') !== false) {
            $input = $this->uploadService->parseUploadedFiles($request);
        } else {
            $input = json_decode($request->getContent(), true);
        }

        $query = $input['query'] ?? '';

        try {
            $rootValue = [];

            $validators = null;

            $event = new ExecutorEvent(
                $request,
                $query,
                $schema,
                $context
            );

            $this->eventDispatcher->dispatch($event, ExecutorEvents::PRE_EXECUTE);

            if ($event->getRequest() instanceof Request) {
                $variableValues = $event->getRequest()->request->all('variables');
            }

            if (!$variableValues) {
                $variableValues = $input['variables'] ?? null;
            }

            $configAllowIntrospection = true;
            if (isset($config['graphql']) && isset($config['graphql']['allow_introspection'])) {
                $configAllowIntrospection = $config['graphql']['allow_introspection'];
            }

            $disableIntrospection = !$configAllowIntrospection || (isset($configuration->getSecurityConfig()['disableIntrospection']) && $configuration->getSecurityConfig()['disableIntrospection']);

            DocumentValidator::addRule(new DisableIntrospection((int)$disableIntrospection));

            $result = GraphQL::executeQuery(
                $event->getSchema(),
                $event->getQuery(),
                $rootValue,
                $event->getContext(),
                $variableValues,
                null,
                null,
                $validators
            );

            $exResult = new ExecutorResultEvent($request, $result);
            $this->eventDispatcher->dispatch($exResult, ExecutorEvents::POST_EXECUTE);
            $result = $exResult->getResult();

            if (\Pimcore::inDebugMode()) {
                $debug = DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE;
                $output = $result->toArray($debug);
            } else {
                $output = $result->toArray();
            }
        } catch (\Exception $e) {
            $output = [
                'errors' => [
                    [
                        'message' => $e->getMessage(),
                    ],
                ],
            ];
        }

        $response = new JsonResponse($output);

        $responseService->removeCorsHeaders($response);
        if (!$skipOutputCache) {
            $this->cacheService->save($request, $response);
        }

        $this->persistentCacheService->postHandle($request, $response);
        $responseService->addCorsHeaders($response);
        $responseService->addHitMissHeaders($response, false);

        return $response;
    }
}
