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
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
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
        public function eval($script, $args = [], $num_keys = 0) {}
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

    /** Forces the pop script's ZREM to report 0 removed, modelling a lost race. */
    public bool $simulateZremMiss = false;

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

    /**
     * Models the two transport Lua scripts as single uninterruptible in-memory
     * operations. A real Redis runs Lua server-side atomically; the double
     * dispatches on the script-identity token the production code passes as the
     * first ARGV element (never parsing the Lua source). Interleaving in the
     * harness happens *between* whole eval() calls, never inside one.
     *
     * @param list<string> $args
     */
    public function eval($script, $args = [], $num_keys = 0): mixed
    {
        $keys = array_slice($args, 0, (int)$num_keys);
        $argv = array_values(array_slice($args, (int)$num_keys));
        $tag = (string)($argv[0] ?? '');

        return match ($tag) {
            'pop' => $this->evalPop($keys, $argv),
            'reclaim' => $this->evalReclaim($keys, $argv),
            default => throw new \LogicException('FakeRedis::eval — unknown script tag "' . $tag . '"'),
        };
    }

    /**
     * @param list<string> $keys
     * @param list<string> $argv
     *
     * @return list<string>
     */
    private function evalPop(array $keys, array $argv): array
    {
        [$zsetKey, $messagesKey, $inflightKey] = $keys;
        $now = $argv[1];
        $poppedAtJson = $argv[2];

        $candidates = $this->zRangeByScore($zsetKey, '-inf', $now, ['limit' => [0, 1]]);
        $id = (string)($candidates[0] ?? '');
        if ($id === '') {
            return ['empty'];
        }

        if ($this->simulateZremMiss || (int)$this->zRem($zsetKey, $id) === 0) {
            return ['zrem_miss'];
        }

        $body = $this->hashes[$messagesKey][$id] ?? false;

        if (!isset($this->hashes[$inflightKey])) {
            $this->hashes[$inflightKey] = [];
        }
        $this->hashes[$inflightKey][$id] = (string)$poppedAtJson;

        if ($body === false) {
            return ['torn', $id];
        }

        return ['ok', $id, $body];
    }

    /**
     * @param list<string> $keys
     * @param list<string> $argv
     */
    private function evalReclaim(array $keys, array $argv): int
    {
        [$zsetKey, $inflightKey] = $keys;
        $id = (string)$argv[1];
        $score = $argv[2];
        $threshold = (int)$argv[3];

        $meta = $this->hashes[$inflightKey][$id] ?? false;
        if ($meta === false) {
            return 0;
        }
        $decoded = json_decode((string)$meta, true);
        $poppedAt = is_array($decoded) && isset($decoded['poppedAt']) && is_int($decoded['poppedAt'])
            ? $decoded['poppedAt']
            : null;
        if ($poppedAt === null || $poppedAt >= $threshold) {
            return 0;
        }

        $this->zAdd($zsetKey, $score, $id);
        unset($this->hashes[$inflightKey][$id]);

        return 1;
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

    public function testRetryBumpsScoreWhenRedeliveryStampPresent(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis, null, 600, 7);

        $message = new PersistentRefreshMessage('c1', '{}', 'Op1', 1000, null);

        // A retry re-send carries a RedeliveryStamp; the score is bumped so the
        // contended message sinks behind fresher arrivals.
        $transport->send(new Envelope($message, [new RedeliveryStamp(1)]));

        $scores = $redis->zsets[self::ZSET];
        self::assertCount(1, $scores);
        self::assertSame(1007, (int)reset($scores));
    }

    public function testSendMintsFreshIdIgnoringInboundTransportIdStamp(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis);

        $message = new PersistentRefreshMessage('c1', '{}', 'Op1', 1000, null);
        $transport->send(new Envelope($message, [new TransportMessageIdStamp('inbound-id')]));

        // The inbound id must not become the queue id — reusing it is what let a
        // later reject() of the original tear the re-queued retry copy.
        self::assertArrayNotHasKey('inbound-id', $redis->zsets[self::ZSET]);
        self::assertCount(1, $redis->zsets[self::ZSET]);
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

    public function testSendRejectsSerializerThatEmitsHeaders(): void
    {
        $redis = new FakeRedis();
        $headerSerializer = new class() implements SerializerInterface {
            public function decode(array $encodedEnvelope): Envelope
            {
                return new Envelope(new \stdClass());
            }

            public function encode(Envelope $envelope): array
            {
                return ['body' => 'irrelevant', 'headers' => ['X-Message-Stamp-Foo' => '[]']];
            }
        };
        $transport = $this->makeTransport($redis, $headerSerializer);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('persists only the body');
        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'Op1', 1700000000)));
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

    public function testRecoverableRetryReSendSurvivesRejectOfOriginal(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis);

        $message = new PersistentRefreshMessage('c1', '{"query":"x"}', 'OpRetry', 1700000000, null);
        $transport->send(new Envelope($message));

        $received = iterator_to_array($transport->get())[0];

        // Symfony's retry flow re-sends the received envelope (still carrying
        // its TransportMessageIdStamp) and then rejects the original. The retry
        // copy must not collide on the original's queue id, or reject()'s body
        // HDEL discards it as a torn write and the refresh is silently dropped.
        $transport->send($received->with(new RedeliveryStamp(1)));
        $transport->reject($received);

        $redelivered = iterator_to_array($transport->get());
        self::assertCount(1, $redelivered, 'retried message was lost as a torn-write discard');
        self::assertSame('OpRetry', $redelivered[0]->getMessage()->operationName);
        self::assertSame([], $transport->warnings, 'no torn-write warning should fire for a clean retry');
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

    public function testGetDiscardsTornWriteAndRemovesInflight(): void
    {
        $redis = new FakeRedis();
        $transport = $this->makeTransport($redis);

        $id = 'torn-id';
        $redis->zsets[self::ZSET][$id] = time() - 5;
        // id present in ZSET but absent from messages HASH — torn write
        $redis->hashes[self::INFLIGHT][$id] = json_encode(['poppedAt' => time()]);

        $envelopes = iterator_to_array($transport->get());

        self::assertSame([], $envelopes);
        self::assertArrayNotHasKey($id, $redis->hashes[self::INFLIGHT] ?? [], 'torn id must be removed from inflight');
        self::assertNotEmpty($transport->warnings);
        self::assertStringContainsString('torn write', $transport->warnings[0]);
    }

    public function testGetLoudBailsWhenZremRemovesNothing(): void
    {
        // A real Redis returns the zrem_miss marker when the script's ZREM
        // removes nothing — a racing consumer claimed the member between the
        // script's own ZRANGEBYSCORE and ZREM. The single-threaded fake can't
        // produce that interleaving inside one eval(), so the simulateZremMiss
        // flag forces the marker to drive the production loud-bail branch.
        $redis = new FakeRedis();
        $redis->simulateZremMiss = true;
        $transport = $this->makeTransport($redis);

        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpContended', time() - 5, null)));

        $envelopes = iterator_to_array($transport->get());

        self::assertSame([], $envelopes);
        self::assertNotEmpty($transport->warnings);
        self::assertStringContainsString('zRem == 0', $transport->warnings[0]);
    }

    public function testThreeConsumersNeverDoublePopUnderInterleavedGet(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();

        $consumerA = $this->makeTransport($redis, $serializer);
        $consumerB = $this->makeTransport($redis, $serializer);
        $consumerC = $this->makeTransport($redis, $serializer);

        $seeded = [];
        for ($i = 0; $i < 9; ++$i) {
            $envelope = $consumerA->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'Op' . $i, time() - (100 - $i), null)));
            $seeded[] = (string)$envelope->last(TransportMessageIdStamp::class)->getId();
        }

        $consumers = [$consumerA, $consumerB, $consumerC];
        $popped = [];
        $rounds = 0;

        // Interleaved round-robin draining: pins PHP fan-out and marker-parsing
        // correctness. Redis-level atomicity (no double-pop inside one EVAL) is
        // only verifiable against real Redis in the Functional suite.
        do {
            $progress = false;
            foreach ($consumers as $consumer) {
                $envelopes = iterator_to_array($consumer->get());
                if ($envelopes === []) {
                    continue;
                }
                $progress = true;
                foreach ($envelopes as $envelope) {
                    $id = (string)$envelope->last(TransportMessageIdStamp::class)->getId();
                    self::assertArrayNotHasKey($id, $popped, 'id ' . $id . ' popped by more than one consumer');
                    $popped[$id] = true;
                    $consumer->ack($envelope);
                }
            }
            ++$rounds;
        } while ($progress && $rounds < 100);

        self::assertCount(9, $popped, 'every seeded message popped exactly once');
        foreach ($seeded as $id) {
            self::assertArrayHasKey($id, $popped);
        }
        self::assertSame([], $redis->hashes[self::INFLIGHT] ?? [], 'inflight HASH fully drained after acks');
    }

    public function testThreeReapersNeverResurrectAStuckMessage(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();

        $reaperA = $this->makeTransport($redis, $serializer, 60, 5);
        $reaperB = $this->makeTransport($redis, $serializer, 60, 5);
        $reaperC = $this->makeTransport($redis, $serializer, 60, 5);

        $stuckIds = [];
        for ($i = 0; $i < 5; ++$i) {
            $id = 'stuck-' . $i;
            $stuckIds[] = $id;
            $encoded = $serializer->encode(new Envelope(new PersistentRefreshMessage('c1', '{}', 'Op' . $i, 500 + $i, null)));
            $redis->hashes[self::MESSAGES][$id] = $encoded['body'];
            $redis->hashes[self::INFLIGHT][$id] = (string)json_encode(['poppedAt' => time() - 600]);
        }

        // Three reapers share state sequentially. get() runs the reaper first;
        // each stuck id, once reclaimed by one reaper, is re-popped by whichever
        // consumer's get() next sees it as due. Pins PHP orchestration correctness
        // and the missing-entry idempotency guard; Redis-level atomicity belongs
        // to the Functional suite.
        $reapers = [$reaperA, $reaperB, $reaperC];
        $surfaced = [];
        $rounds = 0;

        do {
            $progress = false;
            foreach ($reapers as $reaper) {
                $envelopes = iterator_to_array($reaper->get());
                if ($envelopes === []) {
                    continue;
                }
                $progress = true;
                foreach ($envelopes as $envelope) {
                    $id = (string)$envelope->last(TransportMessageIdStamp::class)->getId();
                    self::assertArrayNotHasKey($id, $surfaced, 'stuck id ' . $id . ' resurrected more than once');
                    $surfaced[$id] = true;
                    $reaper->ack($envelope);
                }
            }
            ++$rounds;
        } while ($progress && $rounds < 100);

        self::assertCount(5, $surfaced, 'each stuck id reclaimed and popped exactly once');
        foreach ($stuckIds as $id) {
            self::assertArrayHasKey($id, $surfaced);
        }
        self::assertSame([], $redis->hashes[self::INFLIGHT] ?? [], 'inflight HASH fully drained');
        self::assertSame([], $redis->zsets[self::ZSET] ?? [], 'no stuck id left behind in the ZSET');
    }

    public function testFakeRedisEvalModelsAtomicReclaim(): void
    {
        $redis = new FakeRedis();
        $threshold = time() - 60;
        $score = 500;
        $id = 'reclaim-test-id';

        // Stale entry: poppedAt older than threshold — should reclaim (return 1).
        $redis->hashes[self::INFLIGHT][$id] = json_encode(['poppedAt' => $threshold - 1]);
        $result = $redis->eval(
            '',
            [self::ZSET, self::INFLIGHT, 'reclaim', $id, (string)$score, (string)$threshold],
            2
        );
        self::assertSame(1, $result, 'stale entry must be reclaimed');
        self::assertArrayHasKey($id, $redis->zsets[self::ZSET], 'reclaimed id must be re-added to ZSET');
        self::assertSame($score, (int)$redis->zsets[self::ZSET][$id]);
        self::assertArrayNotHasKey($id, $redis->hashes[self::INFLIGHT] ?? [], 'reclaimed id must be removed from inflight');

        // Fresh entry: poppedAt at or after threshold — must not reclaim (return 0).
        $redis->hashes[self::INFLIGHT][$id] = json_encode(['poppedAt' => $threshold]);
        $result = $redis->eval(
            '',
            [self::ZSET, self::INFLIGHT, 'reclaim', $id, (string)$score, (string)$threshold],
            2
        );
        self::assertSame(0, $result, 'fresh entry must not be reclaimed');

        // Absent entry: id not in inflight — must return 0.
        $result = $redis->eval(
            '',
            [self::ZSET, self::INFLIGHT, 'reclaim', 'no-such-id', (string)$score, (string)$threshold],
            2
        );
        self::assertSame(0, $result, 'absent entry must return 0');
    }

    public function testFakeRedisEvalModelsAtomicPop(): void
    {
        $redis = new FakeRedis();
        $serializer = new PhpSerializer();
        $transport = $this->makeTransport($redis, $serializer);

        $transport->send(new Envelope(new PersistentRefreshMessage('c1', '{}', 'OpAtomic', time() - 5, null)));
        $id = array_key_first($redis->zsets[self::ZSET]);

        // The fake dispatches on the ARGV identity token, not the Lua source,
        // so the $script argument is inert here.
        $result = $redis->eval(
            '',
            [self::ZSET, self::MESSAGES, self::INFLIGHT, 'pop', (string)time(), '{"poppedAt":' . time() . '}'],
            3
        );

        self::assertIsArray($result);
        self::assertSame('ok', $result[0]);
        self::assertSame($id, $result[1]);
        self::assertArrayNotHasKey($id, $redis->zsets[self::ZSET], 'pop ZREMs the member');
        self::assertArrayHasKey($id, $redis->hashes[self::INFLIGHT], 'pop records the id inflight');
    }
}
