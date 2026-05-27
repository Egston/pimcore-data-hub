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

namespace Pimcore\Bundle\DataHubBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\EventListener\PersistentCacheRefreshOnTerminateListener;
use Pimcore\Bundle\DataHubBundle\GraphQL\Service as GraphQLService;
use Pimcore\Bundle\DataHubBundle\Service\OperationClassifier;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Factory;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PersistentCacheRefreshOnTerminateListenerKeyParityTest extends TestCase
{
    private function makeListener(): PersistentCacheRefreshOnTerminateListener
    {
        $controller = $this->createMock(WebserviceController::class);
        $graphQlService = $this->createMock(GraphQLService::class);
        $localeService = $this->createMock(LocaleServiceInterface::class);
        $factory = (new \ReflectionClass(Factory::class))->newInstanceWithoutConstructor();
        $longRunningHelper = (new \ReflectionClass(LongRunningHelper::class))->newInstanceWithoutConstructor();
        $responseService = new class implements ResponseServiceInterface {
            public function removeCorsHeaders(JsonResponse $response): void
            {
            }

            public function addCorsHeaders(JsonResponse $response): void
            {
            }

            public function addHitMissHeaders(JsonResponse $response, bool $isCacheHit): void
            {
            }
        };
        $container = $this->createMock(ContainerBagInterface::class);
        $container->method('get')->willReturn(['graphql' => []]);
        $classifier = new OperationClassifier($container);

        return new PersistentCacheRefreshOnTerminateListener(
            $controller,
            $graphQlService,
            $localeService,
            $factory,
            $longRunningHelper,
            $responseService,
            $container,
            $classifier
        );
    }

    private function makeRequest(string $client, string $body): Request
    {
        $req = Request::create('/datahub/graphql', 'POST', [], [], [], [], $body);
        $req->attributes->set('clientname', $client);

        return $req;
    }

    /**
     * Close the prose-only contract gap: when the meta+payload sidecar
     * attributes are absent (queue-handler entry point, fresh Request from
     * the refresh listener), the listener's `buildRefreshMarkerKey` MUST
     * produce the same key as the static helper consumed by the message
     * handler. Before this batch the two halves canonicalised the body
     * differently — proven only by prose. This test pins it structurally.
     */
    public function testBuildRefreshMarkerKeyReturnsSameKeyAsComputeSwrRefreshLockKeyForRequestWithoutSidecars(): void
    {
        $client = 'c1';
        $body = (string)json_encode([
            'query' => 'query Q { a }',
            'variables' => ['z' => 3, 'a' => 1],
        ]);

        $listener = $this->makeListener();
        $request = $this->makeRequest($client, $body);
        // Deliberately do NOT set _datahub_persistent_meta_key / _payload_key.

        $rm = new \ReflectionMethod($listener, 'buildRefreshMarkerKey');
        $rm->setAccessible(true);

        $listenerKey = (string)$rm->invoke($listener, $request);
        $serviceKey = PersistentOutputCacheService::computeSwrRefreshLockKey($client, $body);

        self::assertSame($serviceKey, $listenerKey);
    }

    public function testBuildEnqueueDedupeKeyReturnsSameKeyAsComputeEnqueueDedupeKey(): void
    {
        $client = 'c1';
        $body = (string)json_encode([
            'query' => 'query Q { a }',
            'variables' => ['z' => 3, 'a' => 1],
        ]);

        $listener = $this->makeListener();
        $request = $this->makeRequest($client, $body);

        $rm = new \ReflectionMethod($listener, 'buildEnqueueDedupeKey');
        $rm->setAccessible(true);

        $listenerKey = (string)$rm->invoke($listener, $request);
        $serviceKey = PersistentOutputCacheService::computeEnqueueDedupeKey($client, $body);

        self::assertSame($serviceKey, $listenerKey);
    }

    public function testEntryHashFromBodyEqualsEntryHashOfCanonicalForm(): void
    {
        $client = 'c1';
        $rawBody = (string)json_encode([
            'query' => 'query Q { a }',
            'variables' => ['z' => 3, 'a' => 1],
        ]);
        $canonical = PersistentOutputCacheService::canonicalizePayloadString($rawBody);

        self::assertSame(
            PersistentOutputCacheService::entryHash($client, $canonical),
            PersistentOutputCacheService::entryHashFromBody($client, $rawBody)
        );
    }

    public function testEntryHashFromBodyNormalisesQueryWhitespace(): void
    {
        $client = 'c1';
        // Extra internal whitespace — canonicalizer prints via AST so this must collapse.
        $rawBody = '{"query":"query Q {  a }"}';
        $canonical = PersistentOutputCacheService::canonicalizePayloadString($rawBody);

        self::assertSame(
            PersistentOutputCacheService::entryHash($client, $canonical),
            PersistentOutputCacheService::entryHashFromBody($client, $rawBody)
        );
    }

    public function testEntryHashClientIsolation(): void
    {
        $body = (string)json_encode(['query' => 'query Q { a }']);
        $canonical = PersistentOutputCacheService::canonicalizePayloadString($body);

        self::assertNotSame(
            PersistentOutputCacheService::entryHash('c1', $canonical),
            PersistentOutputCacheService::entryHash('c2', $canonical)
        );
    }

    public function testInvalidationListenerCooldownKeyAndTerminateEnqueueDedupeKeyShareOneEntryIdentity(): void
    {
        $client = 'c1';
        $rawBody = (string)json_encode([
            'query' => 'query Q { a }',
            'variables' => ['z' => 3, 'a' => 1],
        ]);
        $canonical = PersistentOutputCacheService::canonicalizePayloadString($rawBody);

        $invalidationHash = PersistentOutputCacheService::entryHash($client, $canonical);

        $terminateDedupeKey = PersistentOutputCacheService::computeEnqueueDedupeKey($client, $rawBody);
        $terminateHash = substr($terminateDedupeKey, strlen(PersistentOutputCacheService::ENQUEUE_DEDUPE_PREFIX));

        self::assertSame($invalidationHash, $terminateHash);
    }
}
