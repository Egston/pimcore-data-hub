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
use Pimcore\Bundle\DataHubBundle\GraphQL\Exception\ClientSafeException;
use Pimcore\Bundle\DataHubBundle\GraphQL\Mutation\MutationType;
use Pimcore\Bundle\DataHubBundle\GraphQL\Query\QueryType;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service;
use Pimcore\Bundle\DataHubBundle\PimcoreDataHubBundle;
use Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService;
use Pimcore\Bundle\DataHubBundle\Service\FileUploadService;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\OutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RequestVariableValidator;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Bundle\DataHubBundle\Service\Tier;
use Pimcore\Cache\RuntimeCache;
use Pimcore\Controller\FrontendController;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Logger;
use Pimcore\Model\Factory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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

    private readonly RequestVariableValidator $requestVariableValidator;

    private readonly string $bypassApikey;

    private readonly ?LoggerInterface $psrLogger;

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        CheckConsumerPermissionsService $permissionsService,
        OutputCacheService $cacheService,
        PersistentOutputCacheService $persistentCacheService,
        FileUploadService $uploadService,
        OperationClassifier $operationClassifier,
        RequestVariableValidator $requestVariableValidator,
        #[Autowire('%pimcore_data_hub.request_validation.bypass_apikey%')]
        string $bypassApikey = '',
        #[Autowire(service: 'monolog.logger.pimcore')]
        ?LoggerInterface $psrLogger = null,
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->permissionsService = $permissionsService;
        $this->cacheService = $cacheService;
        $this->persistentCacheService = $persistentCacheService;
        $this->uploadService = $uploadService;
        $this->operationClassifier = $operationClassifier;
        $this->requestVariableValidator = $requestVariableValidator;
        $this->bypassApikey = $bypassApikey;
        $this->psrLogger = $psrLogger;
    }

    /**
     * Resolve the `?version=N` query parameter to a positive int, or null when
     * absent or malformed. Absent params return null silently. Array-shaped
     * (`?version[]=`) and non-canonical-integer values ("01", "-1", "abc", "1.0")
     * resolve to null and emit one invalid_version warning. `->query->all()` is
     * used rather than `->get()` because the latter throws on array-shaped params.
     * Extracted so the early-flow test mirrors share this exact parse.
     */
    protected function parseVersionParam(Request $request): ?int
    {
        $versionParam = $request->query->all()['version'] ?? null;
        if ($versionParam === null) {
            return null;
        }

        $clientname = $request->attributes->getString('clientname');
        if (!is_scalar($versionParam)) {
            Logger::warning('datahub.request_validation.invalid_version', [
                'client' => $clientname,
                'version_raw' => '[non-scalar]',
            ]);

            return null;
        }

        $versionInt = (int)$versionParam;
        if ($versionInt > 0 && (string)$versionInt === (string)$versionParam) {
            return $versionInt;
        }

        Logger::warning('datahub.request_validation.invalid_version', [
            'client' => $clientname,
            'version_raw' => mb_substr((string)$versionParam, 0, 64),
        ]);

        return null;
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

        ['operationName' => $operationName, 'variables' => $inputVariables] = RequestVariableValidator::decodeRequestShape($request->getContent(), null);

        $version = $this->parseVersionParam($request);

        // Development/explorer bypass. Only honoured when the bypass key is
        // configured AND matches AND the client is NOT rules-enforced: pasting
        // the key on an enforced client (e.g. public-content) must leave the
        // endpoint fully validated and cached. The enforced-client guard makes
        // this a dev convenience, never an authentication escape hatch.
        $isBypass = false;
        if ($this->bypassApikey !== '' && !$this->requestVariableValidator->isEnforced($clientname)) {
            $resolvedApiKey = $this->permissionsService->resolveApiKey($request) ?? '';
            $isBypass = hash_equals($this->bypassApikey, $resolvedApiKey);
        }

        if ($isBypass) {
            $request->attributes->set(PersistentOutputCacheService::REQUEST_ATTR_BYPASS_CACHE, true);
            $this->psrLogger?->info('datahub.request_validation.bypass', [
                'operation' => $operationName,
                'client' => $clientname,
            ]);
        } else {
            try {
                $this->requestVariableValidator->assertRequest(
                    $clientname,
                    $version,
                    $operationName,
                    $inputVariables
                );
            } catch (ClientSafeException $e) {
                return new JsonResponse(
                    ['errors' => [['message' => $e->getMessage(), 'extensions' => ['category' => $e->getCategory()]]]],
                    400
                );
            }
        }

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

        // Persistent cache pre-check: may short-circuit or mark for background refresh.
        // No herd-guard yet — a HIT (fresh OR stale) serves a cached payload that needs
        // no compute protection. STALE-HIT's refresh dispatch is deduplicated downstream
        // by PersistentCacheRefreshOnTerminateListener (per-body lock) and by
        // PersistentRefreshMessageHandler (per-op lock at the queue worker).
        $isBypassCache = (bool)$request->attributes->get(PersistentOutputCacheService::REQUEST_ATTR_BYPASS_CACHE);

        if (!$isBypassCache && ($pResponse = $this->persistentCacheService->preHandle($request, $responseService))) {
            $responseService->addHitMissHeaders($pResponse, true);

            return $pResponse;
        }

        // Optionally bypass standard output cache when persistent applies to this request
        $configAll = $this->getParameter('pimcore_data_hub');
        $graphqlCfg = $configAll['graphql'] ?? [];
        $skipOutputCacheForGuarded = (bool)($graphqlCfg['persistent_disable_output_cache_for_guarded'] ?? false);

        // SWR_ONLY cold-miss path. Winner acquires a Symfony Lock keyed by the
        // sidecar key pair and runs the resolver inline; losers poll for the
        // winner's cache write up to swr_cold_miss_lock_wait_ms and fall
        // through to their own inline resolver after the deadline. The
        // never-503-for-browsers invariant rides on this fallback — a stuck
        // winner can't pin all FPM workers indefinitely. SWR_ONLY and
        // HERD_GUARDED are mutually exclusive per request by construction
        // (single tier classification per operationName), so this branch
        // never overlaps with the herd-guard atomic lock above.
        $lock = null;
        if ($tier === Tier::SWR_ONLY
            && !$isBypassCache
            && !$request->attributes->get('_datahub_persistent_refresh')
        ) {
            $waitMs = max(0, (int)($graphqlCfg['swr_cold_miss_lock_wait_ms'] ?? 5000));
            $lockTtl = max(1, (int)($graphqlCfg['swr_cold_miss_lock_ttl'] ?? 30));

            $lock = $this->persistentCacheService->acquireColdMissLock($request, $lockTtl);
            if ($lock === null && $waitMs > 0) {
                $deadline = microtime(true) + ($waitMs / 1000.0);
                while (microtime(true) < $deadline) {
                    // SIGALRM from a foreign LockSignalRefresher tick will
                    // return usleep early; the next iteration just re-probes
                    // preHandle and re-sleeps with a shorter quantum.
                    usleep(50_000);
                    $pResponse = $this->persistentCacheService->preHandle($request, $responseService);
                    if ($pResponse) {
                        $this->psrLogger?->info('swr.cold_miss.lock.observed_write', [
                            'cache_status' => $pResponse->headers->get('X-Pimcore-DataHub-Persistent-Cache', ''),
                        ]);
                        $responseService->addHitMissHeaders($pResponse, true);

                        return $pResponse;
                    }
                }
                // Timeout: a poll iteration may have briefly observed STALE
                // and set _datahub_persistent_refresh; clear it so postHandle
                // does not trigger a kernel.terminate refresh against the
                // response we are about to write inline.
                $request->attributes->remove('_datahub_persistent_refresh');
                $this->psrLogger?->info('swr.cold_miss.lock.timeout_fallback', [
                    'wait_ms_budget' => $waitMs,
                ]);
            }
        }

        try {
            if ($lock !== null) {
                $request->attributes->set(PersistentOutputCacheService::REQUEST_ATTR_COLD_MISS_LOCK, $lock);
            }
            // When running a background refresh, bypass the standard output cache layer
            $isPersistentRefresh = (bool)$request->attributes->get('_datahub_persistent_refresh');
            $isPersistentApplies = (bool)$request->attributes->get('_datahub_persistent_applies');
            $skipOutputCache = $isBypassCache || $isPersistentRefresh || ($skipOutputCacheForGuarded && $isPersistentApplies);

            if (!$skipOutputCache && ($response = $this->cacheService->load($request))) {
                Logger::debug('Output cache HIT');

                $responseService->addCorsHeaders($response);
                $responseService->addHitMissHeaders($response, true);

                return $response;
            }

            Logger::debug('Output cache MISS');

            // Engage the herd-guard only now that both cache layers missed and we're
            // about to do real compute work. Concurrent same-op callers either acquire
            // here (one winner) or receive the configured in-progress status. The guard
            // is released by save() (happy path) or by InProgressLockReleaseListener
            // (exception path). maybeRejectOrAcquire is a no-op for tiers / requests
            // that do not engage the herd-guard (including the _datahub_bypass attribute
            // that refresh sub-requests carry to avoid 503-ing themselves).
            if ($inProgressResponse = $this->cacheService->maybeRejectOrAcquire($request)) {
                Logger::debug(sprintf('In-progress: duplicate blocked (operationName=%s, status=%d)', (string)$operationName, $inProgressResponse->getStatusCode()));
                $responseService->addCorsHeaders($inProgressResponse);

                return $inProgressResponse;
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

            // A bypass request must never mint a persistent entry — postHandle has
            // no bypass-awareness internally; without this guard a bypass request
            // would mint a persistent entry if the operation is classified.
            if (!$isBypassCache) {
                $this->persistentCacheService->postHandle($request, $response);
            }
        } finally {
            $this->persistentCacheService->releaseColdMissLock(
                $request->attributes->get(PersistentOutputCacheService::REQUEST_ATTR_COLD_MISS_LOCK)
            );
            $request->attributes->remove(PersistentOutputCacheService::REQUEST_ATTR_COLD_MISS_LOCK);
        }
        $responseService->addCorsHeaders($response);
        $responseService->addHitMissHeaders($response, false);

        return $response;
    }
}
