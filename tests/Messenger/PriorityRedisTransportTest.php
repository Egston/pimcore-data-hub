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

namespace Pimcore\Bundle\DataHubBundle\Tests\Messenger;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Messenger\PriorityRedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

if (!class_exists('Redis')) {
    eval('class Redis {
        public function multi() {}
        public function exec() {}
        public function zAdd($key, $score, $member) {}
        public function zPopMin($key, $count) {}
        public function zCard($key) {}
        public function hSet($key, $field, $value) {}
        public function hGet($key, $field) {}
        public function hDel($key, ...$fields) {}
        public function hExists($key, $field) {}
        public function hGetAll($key) {}
        public function connect($host, $port = 6379) {}
        public function auth($auth) {}
        public function select($db) {}
    }');
}

/**
 * Phpredis test double: in-memory associative arrays per Redis data type.
 * Mirrors the LockFactoryResolver test-seam idiom — implement only the
 * methods the transport actually calls; ignore stamps and MULTI state
 * since the transport's logic doesn't depend on per-pipeline atomicity
 * during a single PHP process.
 */
final class FakeRedis extends \Redis
{
    /** @var array<string, array<string, int|float>> */
    public array $zsets = [];

    /** @var array<string, array<string, string>> */
    public array $hashes = [];

    /** @var array<int, mixed> */
    private array $queued = [];

    private bool $inMulti = false;

    public bool $simulateExecFailure = false;

    public function multi(): bool|self
    {
        $this->inMulti = true;
        $this->queued = [];

        return $this;
    }

    public function exec(): bool|array
    {
        if ($this->simulateExecFailure) {
            $this->inMulti = false;
            $this->queued = [];

            return false;
        }
        $results = $this->queued;
        $this->inMulti = false;
        $this->queued = [];

        return $results;
    }

    public function zAdd($key, $score, $member): mixed
    {
        if (!isset($this->zsets[$key])) {
            $this->zsets[$key] = [];
        }
        $added = isset($this->zsets[$key][$member]) ? 0 : 1;
        $this->zsets[$key][$member] = $score;

        return $this->recordOrReturn($added);
    }

    public function zPopMin($key, $count = 1): mixed
    {
        if (!isset($this->zsets[$key]) || $this->zsets[$key] === []) {
            return [];
        }
        asort($this->zsets[$key]);
        $picked = array_slice($this->zsets[$key], 0, max(1, (int)$count), true);
        foreach (array_keys($picked) as $k) {
            unset($this->zsets[$key][$k]);
        }

        return $picked;
    }

    public function zCard($key): mixed
    {
        return isset($this->zsets[$key]) ? count($this->zsets[$key]) : 0;
    }

    public function hSet($key, $field, $value): mixed
    {
        if (!isset($this->hashes[$key])) {
            $this->hashes[$key] = [];
        }
        $newField = isset($this->hashes[$key][$field]) ? 0 : 1;
        $this->hashes[$key][$field] = (string)$value;

        return $this->recordOrReturn($newField);
    }

    public function hGet($key, $field): mixed
    {
        $value = $this->hashes[$key][$field] ?? false;

        return $this->recordOrReturn($value);
    }

    public function hDel($key, ...$fields): mixed
    {
        $deleted = 0;
        foreach ($fields as $f) {
            if (isset($this->hashes[$key][$f])) {
                unset($this->hashes[$key][$f]);
                ++$deleted;
            }
        }

        return $this->recordOrReturn($deleted);
    }

    public function hExists($key, $field): mixed
    {
        return isset($this->hashes[$key][$field]);
    }

    public function hGetAll($key): mixed
    {
        return $this->hashes[$key] ?? [];
    }

    private function recordOrReturn(mixed $value): mixed
    {
        if ($this->inMulti) {
            $this->queued[] = $value;

            return $this;
        }

        return $value;
    }
}

final class PriorityRedisTransportTest extends TestCase
{
    private const ZSET = 'datahub_refresh_priority_queue';

    private const MESSAGES = 'datahub_refresh_priority_messages';

    private const INFLIGHT = 'datahub_refresh_priority_inflight';

    private function makeTransport(
        FakeRedis $redis,
        ?SerializerInterface $serializer = null,
        int $visibilityTimeout = 600,
        int $requeueScoreBump = 5,
        string $priorityStrategy = 'oldest_refreshed_at_first',
        int $weightBandSeconds = 60
    ): PriorityRedisTransport {
        return new class($redis, $serializer ?? new PhpSerializer(), self::ZSET, self::MESSAGES, self::INFLIGHT, $visibilityTimeout, $requeueScoreBump, $priorityStrategy, $weightBandSeconds) extends PriorityRedisTransport {
            /** @var list<string> */
            public array $warnings = [];

            /** @var list<string> */
            public array $errors = [];

            protected function logWarning(string $message): void
            {
                $this->warnings[] = $message;
            }

            protected function logError(string $message): void
            {
                $this->errors[] = $message;
            }
        };
    }

    public function testSendAddsToZsetAndMessagesHashAtomically(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis);

        $message = new PersistentRefreshMessage('c1', '{}', 'Op1', 1700000000, null);
        $envelope = $transport->send(new Envelope($message));

        self::assertCount(1, $redis->zsets[self::ZSET]);
        self::assertCount(1, $redis->hashes[self::MESSAGES]);
        $stamp = $envelope->last(TransportMessageIdStamp::class);
        self::assertNotNull($stamp);
        self::assertArrayHasKey((string)$stamp->getId(), $redis->zsets[self::ZSET]);
        self::assertSame(1700000000, (int)$redis->zsets[self::ZSET][(string)$stamp->getId()]);
    }

    public function testScoreSelectionUsesRefreshedAtWhenSet(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis);

        $message = new PersistentRefreshMessage('c1', '{}', 'Op1', 1234567890, null);
        $transport->send(new Envelope($message));

        $scores = array_values($redis->zsets[self::ZSET]);
        self::assertSame(1234567890, (int)$scores[0]);
    }

    public function testScoreSelectionFallsBackToTimeWhenRefreshedAtNull(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis);

        $now = time();
        $message = new PersistentRefreshMessage('c1', '{}', 'Op1', null, null);
        $transport->send(new Envelope($message));

        $scores = array_values($redis->zsets[self::ZSET]);
        self::assertGreaterThanOrEqual($now, (int)$scores[0]);
        self::assertLessThanOrEqual($now + 5, (int)$scores[0]);
    }

    public function testRetryBumpsScoreWhenIdAlreadyInflight(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis, null, 600, 7);

        $idStamp = new TransportMessageIdStamp('reused-id');
        $message = new PersistentRefreshMessage('c1', '{}', 'Op1', 1000, null);

        $redis->hashes[self::INFLIGHT] = ['reused-id' => '{"poppedAt":' . time() . '}'];

        $transport->send(new Envelope($message, [$idStamp]));

        self::assertSame(1007, (int)$redis->zsets[self::ZSET]['reused-id']);
    }

    public function testEnvelopeRoundTripsViaSerializer(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer);

        $sent = new PersistentRefreshMessage('client-x', '{"query":"{__typename}"}', 'OpRoundTrip', 1700000000, 3);
        $transport->send(new Envelope($sent));

        $received = $transport->get();
        $envelopes = is_array($received) ? $received : iterator_to_array($received);
        self::assertCount(1, $envelopes);
        $decoded = $envelopes[0]->getMessage();
        self::assertInstanceOf(PersistentRefreshMessage::class, $decoded);
        self::assertSame('client-x', $decoded->client);
        self::assertSame('OpRoundTrip', $decoded->operationName);
        self::assertSame(1700000000, $decoded->refreshedAt);
        self::assertSame(3, $decoded->priorityWeight);
    }

    public function testEmptyZsetReturnsEmptyIterable(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis);

        $received = $transport->get();
        $envelopes = is_array($received) ? $received : iterator_to_array($received);
        self::assertSame([], $envelopes);
    }

    public function testReaperReQueuesStuckInflightWithIntrinsicScore(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer, 60, 5);

        $stuckMessage = new PersistentRefreshMessage('c1', '{}', 'Op1', 500, null);
        $encoded = $serializer->encode(new Envelope($stuckMessage));
        $redis->hashes[self::MESSAGES] = ['stuck-id' => $encoded['body']];
        $redis->hashes[self::INFLIGHT] = ['stuck-id' => json_encode(['poppedAt' => time() - 600])];

        $envelopes = iterator_to_array($transport->get());
        self::assertCount(1, $envelopes);
        $stamp = $envelopes[0]->last(TransportMessageIdStamp::class);
        self::assertNotNull($stamp);
        self::assertSame('stuck-id', (string)$stamp->getId());
        $decoded = $envelopes[0]->getMessage();
        self::assertInstanceOf(PersistentRefreshMessage::class, $decoded);
        self::assertSame(500, $decoded->refreshedAt);
        self::assertNotEmpty($transport->warnings);
    }

    public function testAckRemovesFromBothHashes(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis);

        $message = new PersistentRefreshMessage('c1', '{}', 'Op1', 1700000000, null);
        $transport->send(new Envelope($message));
        $received = $transport->get();
        $envelopes = is_array($received) ? $received : iterator_to_array($received);
        self::assertCount(1, $envelopes);

        $transport->ack($envelopes[0]);

        $id = (string)$envelopes[0]->last(TransportMessageIdStamp::class)->getId();
        self::assertArrayNotHasKey($id, $redis->hashes[self::MESSAGES]);
        self::assertArrayNotHasKey($id, $redis->hashes[self::INFLIGHT]);
    }

    public function testRejectRemovesFromBothHashesAndLogsError(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis);

        $message = new PersistentRefreshMessage('c1', '{}', 'Op1', 1700000000, null);
        $transport->send(new Envelope($message));
        $received = $transport->get();
        $envelopes = is_array($received) ? $received : iterator_to_array($received);

        $transport->reject($envelopes[0]);

        $id = (string)$envelopes[0]->last(TransportMessageIdStamp::class)->getId();
        self::assertArrayNotHasKey($id, $redis->hashes[self::MESSAGES]);
        self::assertArrayNotHasKey($id, $redis->hashes[self::INFLIGHT]);
        self::assertNotEmpty($transport->errors);
    }

    public function testScoreForUnderDefaultStrategyIgnoresPriorityWeight(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis);

        $weighted = new PersistentRefreshMessage('c1', '{}', 'Op1', 1700000000, 7);
        $unweighted = new PersistentRefreshMessage('c1', '{}', 'Op2', 1700000000, null);

        self::assertSame(1700000000, $transport->scoreFor($weighted));
        self::assertSame(1700000000, $transport->scoreFor($unweighted));
    }

    public function testScoreForUnderBandStrategyOffsetsByPriorityWeight(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis, null, 600, 5, 'oldest_refreshed_at_first_with_weight_bands', 60);

        $message = new PersistentRefreshMessage('c1', '{}', 'OpHigh', 1700000000, 7);

        self::assertSame(1700000000 - 420, $transport->scoreFor($message));
    }

    public function testScoreForUnderBandStrategyFallsBackToNeutralWeightWhenNull(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis, null, 600, 5, 'oldest_refreshed_at_first_with_weight_bands', 60);

        $message = new PersistentRefreshMessage('c1', '{}', 'OpUnclassified', 1700000000, null);

        self::assertSame(1700000000 - 60, $transport->scoreFor($message));
    }

    public function testLongestStaleFirstDrainsLowestScoreFirst(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer);

        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpRecent', 2000, null)));
        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpOldest', 1000, null)));
        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpMid', 1500, null)));

        $first = iterator_to_array($transport->get());
        self::assertSame('OpOldest', $first[0]->getMessage()->operationName);
        $transport->ack($first[0]);

        $second = iterator_to_array($transport->get());
        self::assertSame('OpMid', $second[0]->getMessage()->operationName);
    }
}
