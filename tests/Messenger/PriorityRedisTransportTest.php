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
        public function zRangeByScore($key, $start, $end, $options = []) {}
        public function zRem($key, ...$members) {}
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

    /**
     * Returns members whose score is within [start, end] ascending by score,
     * honoring the LIMIT option. Only the `-inf` lower bound and a numeric
     * upper bound (the shape the transport uses) are supported.
     *
     * @param array{limit?: array{int, int}} $options
     *
     * @return list<string>
     */
    public function zRangeByScore($key, $start, $end, $options = []): mixed
    {
        if (!isset($this->zsets[$key]) || $this->zsets[$key] === []) {
            return [];
        }
        $min = $start === '-inf' ? -INF : (float)$start;
        $max = $end === '+inf' ? INF : (float)$end;
        $matching = [];
        foreach ($this->zsets[$key] as $member => $score) {
            if ($score >= $min && $score <= $max) {
                $matching[(string)$member] = $score;
            }
        }
        asort($matching);
        $members = array_keys($matching);
        if (isset($options['limit']) && is_array($options['limit'])) {
            [$offset, $count] = $options['limit'];
            $members = array_slice($members, (int)$offset, (int)$count);
        }

        return array_values(array_map('strval', $members));
    }

    public function zRem($key, ...$members): mixed
    {
        $removed = 0;
        foreach ($members as $member) {
            if (isset($this->zsets[$key][$member])) {
                unset($this->zsets[$key][$member]);
                ++$removed;
            }
        }

        return $this->recordOrReturn($removed);
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
        int $weightBandSeconds = 60,
        int $readTriggerOffsetSeconds = 86400
    ): PriorityRedisTransport {
        return new class($redis, $serializer ?? new PhpSerializer(), self::ZSET, self::MESSAGES, self::INFLIGHT, $visibilityTimeout, $requeueScoreBump, $priorityStrategy, $weightBandSeconds, $readTriggerOffsetSeconds) extends PriorityRedisTransport {
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
        self::assertSame(1700000000, $decoded->scoreBaseline);
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
        self::assertSame(500, $decoded->scoreBaseline);
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

    public function testScoreForUsesDeliverAtVerbatimWhenSet(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis, null, 600, 5, 'oldest_refreshed_at_first_with_weight_bands', 60);

        $future = time() + 21600;
        $scheduled = new PersistentRefreshMessage('c1', '{}', 'OpCooldown', time(), 7, $future);

        // deliverAt wins over both the refreshedAt baseline and weight banding.
        self::assertSame($future, $transport->scoreFor($scheduled));
    }

    public function testNullDeliverAtMessagePopsImmediately(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer);

        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpNow', time() - 5, null)));

        $envelopes = iterator_to_array($transport->get());
        self::assertCount(1, $envelopes);
        self::assertSame('OpNow', $envelopes[0]->getMessage()->operationName);
    }

    public function testFutureDeliverAtMessageDoesNotPopUntilDue(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer);

        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpScheduled', time(), null, time() + 3600)));

        // The message is in the ZSET but future-scored, so get() must not return it.
        self::assertSame(1, $transport->getMessageCount());
        self::assertSame([], iterator_to_array($transport->get()));
    }

    public function testDueDeliverAtMessagePopsOnceWindowElapses(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer);

        // A scheduled message whose deliverAt is already in the past is due.
        $deliverAt = time() - 1;
        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpDue', time() - 7200, null, $deliverAt)));

        $envelopes = iterator_to_array($transport->get());
        self::assertCount(1, $envelopes);
        $decoded = $envelopes[0]->getMessage();
        self::assertSame('OpDue', $decoded->operationName);
        self::assertSame($deliverAt, $decoded->deliverAt);
    }

    public function testDueMessagesAmongMixedScheduledOrderByScore(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer);

        // Two due (past-scored) messages and one future-scheduled; the due ones
        // drain lowest-score-first and the future one stays invisible.
        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpDueRecent', time() - 10, null)));
        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpDueOldest', time() - 100, null)));
        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpFuture', time(), null, time() + 3600)));

        $first = iterator_to_array($transport->get());
        self::assertSame('OpDueOldest', $first[0]->getMessage()->operationName);
        $transport->ack($first[0]);

        $second = iterator_to_array($transport->get());
        self::assertSame('OpDueRecent', $second[0]->getMessage()->operationName);
        $transport->ack($second[0]);

        // The future-scheduled message is still not due.
        self::assertSame([], iterator_to_array($transport->get()));
        self::assertSame(1, $transport->getMessageCount());
    }

    public function testReapedScheduledMessageRetainsDeliverAt(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer, 60, 5);

        $deliverAt = time() + 3600;
        $scheduled = new PersistentRefreshMessage('c1', '{}', 'OpScheduled', time(), null, $deliverAt);
        $encoded = $serializer->encode(new Envelope($scheduled));
        $redis->hashes[self::MESSAGES] = ['stuck-id' => $encoded['body']];
        $redis->hashes[self::INFLIGHT] = ['stuck-id' => json_encode(['poppedAt' => time() - 600])];

        // The reaper re-queues stuck-id with its intrinsic score; an absolute
        // deliverAt must survive so the message stays future-dated, not visible.
        iterator_to_array($transport->get());

        self::assertArrayHasKey('stuck-id', $redis->zsets[self::ZSET]);
        self::assertSame($deliverAt, (int)$redis->zsets[self::ZSET]['stuck-id']);
        self::assertArrayNotHasKey('stuck-id', $redis->hashes[self::INFLIGHT]);
    }

    public function testReadTriggeredMessageRoundTripsThroughSerializer(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer);

        $sent = new PersistentRefreshMessage('client-x', '{"query":"{__typename}"}', 'OpRead', 1700000000, null, null, true);
        $transport->send(new Envelope($sent));

        $received = $transport->get();
        $envelopes = is_array($received) ? $received : iterator_to_array($received);
        self::assertCount(1, $envelopes);
        $decoded = $envelopes[0]->getMessage();
        self::assertInstanceOf(PersistentRefreshMessage::class, $decoded);
        self::assertTrue($decoded->readTriggered, 'readTriggered must survive serialize → deserialize');
    }

    public function testReapedReadRetainsReadTriggerOffsetInScore(): void
    {
        // Requeue-stays-in-class: the reaper re-derives the score through
        // scoreFor(deserialized message), so a reaped read keeps its offset. A
        // future-dated refreshedAt keeps the re-queued read above `now`, so it
        // stays in the ZSET (not immediately re-popped) and the score is
        // observable directly.
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer, 60, 5, 'oldest_refreshed_at_first', 60, 86400);

        $refreshedAt = time() + 200000;
        $read = new PersistentRefreshMessage('c1', '{}', 'OpRead', $refreshedAt, null, null, true);
        $encoded = $serializer->encode(new Envelope($read));
        $redis->hashes[self::MESSAGES] = ['stuck-read' => $encoded['body']];
        $redis->hashes[self::INFLIGHT] = ['stuck-read' => json_encode(['poppedAt' => time() - 600])];

        iterator_to_array($transport->get());

        self::assertArrayHasKey('stuck-read', $redis->zsets[self::ZSET]);
        self::assertSame($refreshedAt - 86400, (int)$redis->zsets[self::ZSET]['stuck-read'], 'reaped read must re-derive a score that still carries the offset');
    }

    public function testMessageScoredAtExactlyNowPopsImmediately(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer);

        $now = time();
        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpExactNow', $now, null, $now)));

        $envelopes = iterator_to_array($transport->get());
        self::assertCount(1, $envelopes);
        self::assertSame('OpExactNow', $envelopes[0]->getMessage()->operationName);
    }
}
