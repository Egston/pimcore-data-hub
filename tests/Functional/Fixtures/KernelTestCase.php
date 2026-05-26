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

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures;

use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Tests\Support\Test\TestCase as PimcoreSupportTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * L3 functional-test base. Boots the Pimcore kernel via the parent codeception
 * support harness, loads fixture data once per test class, and flushes the
 * bundle's Redis prefix space between tests.
 *
 * The base intentionally does NOT clear Pimcore's standard output cache layer
 * between tests — NEITHER-tier coverage in ColdMissTest needs the standard
 * output cache hit on the second request, and clearing it would mask that
 * behaviour. Tests that need a clean output-cache slate flush explicitly.
 */
abstract class KernelTestCase extends PimcoreSupportTestCase
{
    /** @var array<string, list<int>> Cached fixture ids loaded by loadFixturesOnce(). */
    private static array $fixtureIds = [];

    private ?TestHandler $logCapture = null;

    protected function needsDb(): bool
    {
        return true;
    }

    /**
     * @beforeClass
     */
    final public static function loadFixturesOnce(): void
    {
        $loader = new FixtureLoader();
        self::$fixtureIds = $loader->loadAll();
    }

    final protected function setUp(): void
    {
        parent::setUp();
        $this->flushBundleKeys();
        $this->logCapture = null;
    }

    /**
     * @return array<string, list<int>>
     */
    final protected function fixtureIds(): array
    {
        return self::$fixtureIds;
    }

    /**
     * Dispatch a GraphQL request through the controller, bypassing HTTP.
     *
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $attributes additional request attributes
     */
    final protected function sendGraphQL(
        string $operationName,
        string $query,
        array $variables = [],
        string $clientname = 'default',
        array $attributes = []
    ): JsonResponse {
        $body = json_encode([
            'operationName' => $operationName,
            'query' => $query,
            'variables' => $variables,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            throw new \RuntimeException('datahub.functional: failed to encode GraphQL request body');
        }

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
        foreach ($attributes as $name => $value) {
            $request->attributes->set($name, $value);
        }

        $controller = \Pimcore::getContainer()->get(WebserviceController::class);
        if (!$controller instanceof WebserviceController) {
            throw new \RuntimeException('datahub.functional: WebserviceController service not available');
        }

        $response = $controller->webonyxAction(
            \Pimcore::getContainer()->get(\Pimcore\Bundle\DataHubBundle\GraphQL\Service::class),
            \Pimcore::getContainer()->get(\Pimcore\Localization\LocaleServiceInterface::class),
            \Pimcore::getContainer()->get(\Pimcore\Model\Factory::class),
            $request,
            \Pimcore::getContainer()->get(\Pimcore\Helper\LongRunningHelper::class),
            \Pimcore::getContainer()->get(\Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface::class)
        );

        if (!$response instanceof JsonResponse) {
            throw new \RuntimeException('datahub.functional: webonyxAction did not return a JsonResponse');
        }

        $kernel = \Pimcore::getKernel();
        $eventDispatcher = \Pimcore::getContainer()->get('event_dispatcher');
        $terminateEvent = new TerminateEvent($kernel, $request, $response);
        $eventDispatcher->dispatch($terminateEvent, KernelEvents::TERMINATE);

        return $response;
    }

    /**
     * Returns the Pimcore Redis cache client. The L3 suite is gated on
     * minikube where Redis is always the cache backend — an absent backend
     * is a test-environment setup error, not a runtime fallback.
     *
     * @throws \RuntimeException when the Redis client cannot be resolved
     */
    final protected function redis(): \Redis
    {
        try {
            $client = \Pimcore::getContainer()->get('pimcore.cache.adapter.redis_tag_aware');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'datahub.functional: Redis backend not available — L3 suite contract violated',
                0,
                $e
            );
        }

        if ($client instanceof \Redis) {
            return $client;
        }

        if (is_object($client) && method_exists($client, 'getRedis')) {
            $maybe = $client->getRedis();
            if ($maybe instanceof \Redis) {
                return $maybe;
            }
        }

        throw new \RuntimeException(
            'datahub.functional: Redis backend not available — L3 suite contract violated'
        );
    }

    /**
     * Wires a Monolog TestHandler into the bundle's logger channel so tests
     * can assert on emitted structured events. The static {@see \Pimcore\Logger}
     * facade is not interceptable by this handler — production code must route
     * through an injected PSR-3 logger for emissions to be observable here.
     */
    final protected function logCapture(): TestHandler
    {
        if ($this->logCapture instanceof TestHandler) {
            return $this->logCapture;
        }

        $handler = new TestHandler();

        $logger = \Pimcore::getContainer()->get('monolog.logger.pimcore');
        if (!$logger instanceof MonologLogger) {
            throw new \RuntimeException(
                'datahub.functional: monolog.logger.pimcore is not a MonologLogger — log capture wiring broken'
            );
        }
        $logger->pushHandler($handler);
        $this->logCapture = $handler;

        return $handler;
    }

    /**
     * Returns the number of in-flight messages currently held by the priority
     * transport's inflight HASH (messages acquired by a consumer but not yet acked).
     */
    final protected function refreshInflightCount(): int
    {
        $redis = $this->redis();
        $keys = $redis->keys('datahub_refresh_priority_*inflight*');
        if (!is_array($keys)) {
            return 0;
        }
        $count = 0;
        foreach ($keys as $key) {
            if ($redis->type($key) === \Redis::REDIS_HASH) {
                $count += (int)$redis->hLen($key);
            }
        }

        return $count;
    }

    /**
     * Returns the number of pending messages in the priority refresh transport
     * by summing the cardinality of all ZSET keys under the transport's prefix.
     */
    final protected function refreshQueueDepth(): int
    {
        $redis = $this->redis();
        $keys = $redis->keys('datahub_refresh_priority_*');
        if (!is_array($keys)) {
            return 0;
        }
        $depth = 0;
        foreach ($keys as $key) {
            if ($redis->type($key) === \Redis::REDIS_ZSET) {
                $depth += (int)$redis->zCard($key);
            }
        }

        return $depth;
    }

    /**
     * Returns id→(score, refreshedAt, message) for every envelope currently
     * held in the priority transport's messages HASH, indexed by the ZSET id.
     *
     * Scans every `datahub_refresh_priority_*` ZSET, maps each ZSET key to its
     * sibling messages HASH by replacing a trailing `_queue` with `_messages`
     * (falling back to `datahub_refresh_priority_messages` when the suffix does
     * not apply), HGETs each id's encoded body, and decodes it locally via a
     * fresh `PhpSerializer` — matching how the transport encodes on `send()` so
     * the decode shape is round-trip equivalent.
     *
     * Failing loud is the contract: a torn write (ZSET id with no body in the
     * HASH), a decode failure, or a body whose envelope carries a non-
     * `PersistentRefreshMessage` payload all throw. Callers assert envelope→
     * score correlation rather than score-sequence-only, so a transport bug
     * that scrambled id→envelope pairings while preserving the sorted score
     * set is observable.
     *
     * @return array<string, array{score: float, refreshedAt: ?int, message: PersistentRefreshMessage}>
     */
    final protected function envelopeRefreshedAtById(): array
    {
        $redis = $this->redis();
        $keys = $redis->keys('datahub_refresh_priority_*');
        if (!is_array($keys) || $keys === []) {
            return [];
        }

        $serializer = new PhpSerializer();
        $rows = [];

        foreach ($keys as $key) {
            if ($redis->type($key) !== \Redis::REDIS_ZSET) {
                continue;
            }

            $entries = $redis->zRange($key, 0, -1, true);
            if (!is_array($entries) || $entries === []) {
                continue;
            }

            $messagesKey = str_ends_with($key, '_queue')
                ? substr($key, 0, -strlen('_queue')) . '_messages'
                : 'datahub_refresh_priority_messages';

            foreach ($entries as $id => $score) {
                $id = (string)$id;
                $body = $redis->hGet($messagesKey, $id);
                if (!is_string($body) || $body === '') {
                    throw new \RuntimeException(
                        'datahub.functional: priority-queue ZSET id ' . $id . ' has no body in messages HASH — torn write'
                    );
                }

                $envelope = $serializer->decode(['body' => $body]);
                $message = $envelope->getMessage();
                if (!$message instanceof PersistentRefreshMessage) {
                    throw new \RuntimeException(
                        'datahub.functional: priority-queue envelope decode produced unexpected message type ' . get_class($message)
                    );
                }

                $rows[$id] = [
                    'score' => (float)$score,
                    'refreshedAt' => $message->refreshedAt,
                    'message' => $message,
                ];
            }
        }

        return $rows;
    }

    /**
     * Flushes only Redis keys under the bundle's prefix space; touches no
     * sibling-application keys. The per-test isolation contract relies on
     * test bootstrap having stood up Redis — an absent backend is a
     * test-environment setup error.
     */
    final protected function flushBundleKeys(): void
    {
        $redis = $this->redis();

        foreach (BundleRedisPrefixes::ALL as $prefix) {
            $cursor = null;
            while (($keys = $redis->scan($cursor, $prefix . '*', 256)) !== false) {
                if ($keys !== []) {
                    $redis->del(...$keys);
                }
            }
        }

        \Pimcore\Cache::clearTag(PersistentOutputCacheService::TAG_COMMON);

        if (\Pimcore\Cache::load(PersistentOutputCacheService::KEY_FALLBACK_WATERMARK_TS) !== false) {
            \Pimcore\Cache::remove(PersistentOutputCacheService::KEY_FALLBACK_WATERMARK_TS);
        }
    }
}
