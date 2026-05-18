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

namespace Pimcore\Bundle\DataHubBundle\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Round-trips the service through a real PSR-6 tag-aware backend instead of
 * mocking cacheLoad/cacheSave. The pre-existing tests asserted on string
 * arguments passed to the mock, so a PSR-6 reserved-character violation
 * (`{}()/\@:` in tag/key names) or `expiresAfter(0)` "expires now" semantics
 * — both real bugs shipped in this bundle — went undetected for months.
 *
 * Each test in this class would have failed against the broken implementation
 * on any of those axes.
 */
final class PersistentOutputCacheServiceBackendTest extends TestCase
{
    private function makeService(array $graphql = []): ArrayBackedPersistentOutputCacheService
    {
        $defaults = [
            'persistent_output_cache_enabled' => true,
            'persistent_output_cache_lifetime' => 10,
            'persistent_output_cache_payload_ttl' => 60,
            'persistent_output_cache_guard_only' => false,
        ];
        $c = $this->createMock(ContainerBagInterface::class);
        $c->method('get')->willReturn(['graphql' => $graphql + $defaults]);

        return new ArrayBackedPersistentOutputCacheService($c);
    }

    private function makeRequest(string $client, string $op, array $variables = []): Request
    {
        $body = json_encode([
            'operationName' => $op,
            'query' => "query $op { __typename }",
            'variables' => $variables,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $req = Request::create('/datahub/graphql', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $req->attributes->set('clientname', $client);

        return $req;
    }

    private function makeResponseService(): ResponseServiceInterface
    {
        return new class implements ResponseServiceInterface {
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
    }

    /**
     * Anti-regression for the `:` separator bug: production code used to build
     * tag names like `datahub_graphql_op:<op>` and `datahub_graphql_client:<c>`,
     * which PSR-6 / Symfony Cache rejects via CacheItem::validateKey on tags.
     * Every savePersistent() threw; postHandle's try/catch swallowed it; the
     * persistent layer was permanently empty and probe always returned MISS.
     */
    public function testSavePersistentRoundTripsThroughRealPsr6Backend(): void
    {
        $svc = $this->makeService();
        $req = $this->makeRequest('c1', 'TestOp');

        $svc->savePersistent($req, new JsonResponse(['data' => ['x' => 1]]));

        $probe = $svc->probeStatus($req);
        $this->assertSame(['applies' => true, 'status' => 'HIT'], $probe);
    }

    /**
     * Anti-regression: index keys had the same `:` problem
     * (`datahub_graphql_persistent_index_op:<op>` etc.). With the underscore
     * fix, the indices land in the real backend and are readable back.
     */
    public function testIndexKeysSurviveRealPsr6Validation(): void
    {
        $svc = $this->makeService();

        $svc->savePersistent($this->makeRequest('c1', 'OpA'), new JsonResponse(['data' => ['a' => 1]]));
        $svc->savePersistent($this->makeRequest('c2', 'OpB'), new JsonResponse(['data' => ['b' => 1]]));

        foreach ([
            PersistentOutputCacheService::INDEX_ALL,
            PersistentOutputCacheService::INDEX_CLIENT_PREFIX . 'c1',
            PersistentOutputCacheService::INDEX_CLIENT_PREFIX . 'c2',
            PersistentOutputCacheService::INDEX_OP_PREFIX . 'OpA',
            PersistentOutputCacheService::INDEX_OP_PREFIX . 'OpB',
        ] as $indexKey) {
            $list = $svc->pool->getItem($indexKey)->get();
            $this->assertIsArray($list, "Index key $indexKey did not round-trip");
            $this->assertNotEmpty($list, "Index key $indexKey should be non-empty");
        }
    }

    /**
     * Anti-regression for the `lifetime=0` bug. The watermark used to be
     * written with TTL=0, which Symfony's `CacheItem::expiresAfter(0)`
     * interprets as "expires now": `$this->expiry = 0 + microtime(true)`.
     * Reading back returned MISS immediately, so `$isStale` was always false
     * and STALE→refresh transitions never fired.
     */
    public function testMarkOutputInvalidatedWatermarkSurvivesSubsequentLoad(): void
    {
        $svc = $this->makeService();
        $svc->markOutputInvalidated(1700000000);

        $item = $svc->pool->getItem(PersistentOutputCacheService::KEY_LAST_INVALIDATION);
        $this->assertTrue($item->isHit(), 'Watermark must persist beyond the save call');
        $this->assertSame(1700000000, $item->get());
    }

    /**
     * Anti-regression: indices were also written with `lifetime=0`. Under the
     * "expires now" semantics each `addToIndex` read returned an empty list,
     * so the index never accumulated more than one entry — invalidation
     * scheduling couldn't find anything to refresh.
     */
    public function testAddToIndexAccumulatesMembersAcrossSaves(): void
    {
        $svc = $this->makeService();
        $svc->savePersistent($this->makeRequest('c1', 'Op', ['v' => 1]), new JsonResponse(['data' => ['a' => 1]]));
        $svc->savePersistent($this->makeRequest('c1', 'Op', ['v' => 2]), new JsonResponse(['data' => ['a' => 2]]));
        $svc->savePersistent($this->makeRequest('c1', 'Op', ['v' => 3]), new JsonResponse(['data' => ['a' => 3]]));

        $list = $svc->pool->getItem(PersistentOutputCacheService::INDEX_OP_PREFIX . 'Op')->get();
        $this->assertIsArray($list);
        $this->assertCount(3, $list);
    }

    /**
     * Full SWR lifecycle round-trip: composite of the two bugs above plus
     * the controller-side handoff. preHandle must transition HIT → STALE
     * → HIT-after-refresh against a real cache, with all headers and
     * request attributes set correctly.
     */
    public function testFullSwrLifecycleHitStaleRefreshHit(): void
    {
        $svc = $this->makeService();
        $rs = $this->makeResponseService();

        // 1. cold MISS
        $req = $this->makeRequest('c1', 'Op');
        $this->assertSame('MISS', $svc->probeStatus($req)['status']);

        // 2. fresh save → HIT
        $svc->savePersistent($req, new JsonResponse(['data' => ['v' => 1]]));
        $this->assertSame('HIT', $svc->probeStatus($req)['status']);

        $req = $this->makeRequest('c1', 'Op');
        $resp = $svc->preHandle($req, $rs);
        $this->assertNotNull($resp);
        $this->assertSame('HIT', $resp->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $this->assertNull($resp->headers->get('Warning'));

        // 3. invalidate → STALE
        sleep(1);
        $svc->markOutputInvalidated();
        $this->assertSame('STALE', $svc->probeStatus($req)['status']);

        $req = $this->makeRequest('c1', 'Op');
        $resp = $svc->preHandle($req, $rs);
        $this->assertNotNull($resp);
        $this->assertSame('STALE', $resp->headers->get('X-Pimcore-DataHub-Persistent-Cache'));
        $this->assertSame('110 - "Response is Stale"', $resp->headers->get('Warning'));
        $this->assertTrue($req->attributes->get('_datahub_persistent_refresh'));

        // 4. background refresh completes → HIT again
        $svc->savePersistent($this->makeRequest('c1', 'Op'), new JsonResponse(['data' => ['v' => 2]]));
        $this->assertSame('HIT', $svc->probeStatus($this->makeRequest('c1', 'Op'))['status']);
    }

    /**
     * R1.1 — savePersistent must refuse to cache non-2xx responses, so a
     * transient downstream error (DB hiccup, herd-guard 503, schema build
     * crash) doesn't get persisted for 24h. Before this gate the herd-guard
     * 503 returned by the refresh sub-request could rewrite a perfectly
     * good entry into a poison payload.
     */
    public function testSavePersistentRefusesNon2xxResponse(): void
    {
        $svc = $this->makeService();
        $req = $this->makeRequest('c1', 'Op');

        $svc->savePersistent($req, new JsonResponse(['data' => ['ok' => true]], 503));

        $this->assertSame('MISS', $svc->probeStatus($req)['status']);
    }

    /**
     * Errors-only 200 responses (no `data`, null `data`, empty `data`) are NOT
     * cached — they typically come from a transient broken-schema window and
     * persisting them outlasts the recovery. Partial-success responses (non-empty
     * `data` AND `errors`) are still cached — see
     * `testSavePersistentCachesPartialSuccessWithErrors`.
     */
    public function testSavePersistentRefusesErrorsOnlyPayload(): void
    {
        $svc = $this->makeService();
        $req = $this->makeRequest('c1', 'Op');

        $svc->savePersistent($req, new JsonResponse([
            'errors' => [['message' => 'type definition ResourceLibraryTag not found']],
        ]));

        $this->assertSame('MISS', $svc->probeStatus($req)['status']);
    }

    /**
     * Partial-success responses (`data` non-empty AND `errors` present) are still
     * cached: the errors are deterministic against the input and refusing them
     * would create a herd-guard storm on every retry.
     */
    public function testSavePersistentCachesPartialSuccessWithErrors(): void
    {
        $svc = $this->makeService();
        $req = $this->makeRequest('c1', 'Op');

        $svc->savePersistent($req, new JsonResponse([
            'data' => ['someField' => 'value', 'failingField' => null],
            'errors' => [['message' => 'failingField could not be resolved']],
        ]));

        $this->assertSame('HIT', $svc->probeStatus($req)['status']);
    }

    /**
     * R1.1 — savePersistent must refuse to cache empty payloads.
     */
    public function testSavePersistentRefusesEmptyPayload(): void
    {
        $svc = $this->makeService();
        $req = $this->makeRequest('c1', 'Op');

        $svc->savePersistent($req, new JsonResponse([]));

        $this->assertSame('MISS', $svc->probeStatus($req)['status']);
    }

    /**
     * R3.7 — markOutputInvalidated($ts) with a non-positive $ts clamps to
     * time() instead of writing an epoch watermark that would make every
     * cached entry look FRESH until the next mutation event.
     */
    public function testMarkOutputInvalidatedClampsNonPositiveTimestamp(): void
    {
        $svc = $this->makeService();
        $svc->markOutputInvalidated(0);
        $wm = $svc->pool->getItem(PersistentOutputCacheService::KEY_LAST_INVALIDATION)->get();
        $this->assertIsInt($wm);
        $this->assertGreaterThan(0, $wm);
        $this->assertGreaterThanOrEqual(time() - 5, $wm);
    }

    /**
     * clearAll() evicts every payload, meta, and index entry tagged with
     * TAG_COMMON but leaves the watermark entry (KEY_LAST_INVALIDATION,
     * tagged with TAG_WATERMARK) intact — clearing the watermark would
     * make every freshly-written entry look FRESH again until the next
     * external invalidation event.
     */
    public function testClearAllEvictsEntriesAndPreservesWatermark(): void
    {
        $svc = $this->makeService();
        $req = $this->makeRequest('c1', 'Op');

        // Seed: one cached payload + a watermark.
        $svc->savePersistent($req, new JsonResponse(['data' => ['ok' => true]]));
        $svc->markOutputInvalidated(123456789);
        $this->assertSame('HIT', $svc->probeStatus($req)['status'], 'precondition: entry is cached');

        $svc->clearAll();

        $this->assertSame('MISS', $svc->probeStatus($req)['status'], 'payload should be evicted after clearAll');
        $watermarkItem = $svc->pool->getItem(PersistentOutputCacheService::KEY_LAST_INVALIDATION);
        $this->assertTrue($watermarkItem->isHit(), 'watermark must survive clearAll');
        $this->assertSame(123456789, $watermarkItem->get(), 'watermark value must be unchanged');
    }
}

/**
 * Test seam: a PersistentOutputCacheService that delegates cacheLoad/cacheSave
 * to a real Symfony `TagAwareAdapter(ArrayAdapter)` instead of the production
 * `\Pimcore\Cache` static bridge. This is the integration layer the existing
 * mock-based tests skipped, where PSR-6 key/tag validation and
 * `expiresAfter()` semantics actually fire.
 */
final class ArrayBackedPersistentOutputCacheService extends PersistentOutputCacheService
{
    public TagAwareAdapterInterface $pool;

    public function __construct(ContainerBagInterface $c)
    {
        parent::__construct($c);
        $this->pool = new TagAwareAdapter(new ArrayAdapter(storeSerialized: true));
    }

    protected function cacheLoad(string $key)
    {
        $item = $this->pool->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    protected function cacheSave(string $key, $value, array $tags, ?int $ttl): void
    {
        $item = $this->pool->getItem($key);
        $item->set($value);
        $item->expiresAfter($ttl);
        $item->tag($tags);
        $this->pool->save($item);
    }

    protected function cacheClearTag(string $tag): bool
    {
        return $this->pool->invalidateTags([$tag]);
    }
}
