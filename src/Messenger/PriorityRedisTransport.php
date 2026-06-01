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

namespace Pimcore\Bundle\DataHubBundle\Messenger;

use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Logger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Priority-ordered Redis transport for refresh-queue messages.
 *
 * Three distinct key-spaces back the queue, all under the
 * `datahub_refresh_priority_*` prefix family (third in the bundle's prefix
 * set alongside `datahub_persistent_*` cache markers and the Symfony Lock
 * resource space). Member ids are opaque UUID-v4.
 *
 * - ZSET `<zset_key>` — score: a unix-seconds baseline sourced from
 *   `PersistentRefreshMessage::$scoreBaseline` (or `time()` when unset), or an
 *   absolute `deliverAt` due-time for scheduled refreshes; member: message id.
 *   `get()` reads the lowest-scored member whose score is `<= now` via
 *   ZRANGEBYSCORE, so future-dated (scheduled) members stay invisible until
 *   due while past-dated members drain longest-stale-first.
 * - HASH `<messages_key>` — id to serialized envelope bytes (framework default
 *   transport serializer).
 * - HASH `<inflight_key>` — id to `{"poppedAt": <unix-ts>}`. Reaper scans this
 *   set on each `get()` and re-queues entries whose poppedAt is older than
 *   the visibility timeout.
 *
 * Atomic invariants:
 * - `send()`: ZADD + HSET in MULTI/EXEC. On retry (RedeliveryStamp present)
 *   the ZSET score is bumped by the requeue-score knob so contended messages
 *   sink behind fresher arrivals. MULTI/EXEC is N-consumer-safe here: send
 *   only ever adds, so concurrent senders cannot lose a write to a racing pop.
 * - `get()`: a single Lua script does ZRANGEBYSCORE (lowest due member),
 *   ZREM, HGET messages, HSET inflight in one uninterruptible step. Folding
 *   the read and the claim into one EVAL is what makes exactly-one-consumer
 *   wins each message hold at N≥2 — a non-atomic ZRANGEBYSCORE+ZREM could
 *   hand the same id to two consumers between the read and the remove.
 * - `reapStuckInflight()`: candidate selection (HGETALL snapshot, score
 *   re-derivation) is PHP-side, but each reclaim runs a Lua script that
 *   re-asserts the id is still inflight and still stale before ZADD + HDEL,
 *   so two reapers cannot resurrect the same id twice.
 * - `ack()`/`reject()`: HDEL on both messages and inflight in MULTI/EXEC.
 *   Deletes are idempotent, so concurrent acks of distinct ids are safe.
 *
 * The watermark, dedupe/cooldown sentinels, and herd-guard/cold-miss locks
 * that the persistent-cache layer relies on do not live in this transport:
 * they use SET NX PX (Symfony Lock) and single-key SET/GETSET (sentinels),
 * which are individually atomic Redis commands and need no Lua wrapping here.
 *
 * Queue-score derivation lives in {@see scoreFor()}.
 */
class PriorityRedisTransport implements TransportInterface, MessageCountAwareInterface
{
    // Script-identity token (first ARGV element / Lua `ARGV[1]`): the FakeRedis
    // double dispatches on it; inert against a real Redis (the Lua body ignores it).
    private const POP_SCRIPT_TAG = 'pop';

    private const RECLAIM_SCRIPT_TAG = 'reclaim';

    // Marker strings returned by POP_SCRIPT — keep in sync with self::POP_SCRIPT.
    private const POP_EMPTY    = 'empty';

    private const POP_ZREM_MISS = 'zrem_miss';

    private const POP_TORN     = 'torn';

    private const POP_OK       = 'ok';

    private const POP_SCRIPT = <<<'LUA'
        local id = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[2], 'LIMIT', 0, 1)[1]
        if id == nil then
            return {'empty'}
        end
        if redis.call('ZREM', KEYS[1], id) == 0 then
            return {'zrem_miss'}
        end
        local body = redis.call('HGET', KEYS[2], id)
        redis.call('HSET', KEYS[3], id, ARGV[3])
        if body == false then
            return {'torn', id}
        end
        return {'ok', id, body}
        LUA;

    private const RECLAIM_SCRIPT = <<<'LUA'
        local meta = redis.call('HGET', KEYS[2], ARGV[2])
        if meta == false then
            return 0
        end
        local ok, decoded = pcall(cjson.decode, meta)
        if not ok or type(decoded) ~= 'table' then
            return 0
        end
        local poppedAt = tonumber(decoded.poppedAt)
        if poppedAt == nil or poppedAt >= tonumber(ARGV[4]) then
            return 0
        end
        redis.call('ZADD', KEYS[1], ARGV[3], ARGV[2])
        redis.call('HDEL', KEYS[2], ARGV[2])
        return 1
        LUA;

    public function __construct(
        private \Redis $redis,
        private SerializerInterface $serializer,
        private string $zsetKey,
        private string $messagesKey,
        private string $inflightKey,
        private int $visibilityTimeout,
        private int $requeueScoreBump,
        private string $priorityStrategy,
        private int $weightBandSeconds,
        private int $readTriggerOffsetSeconds
    ) {
    }

    public function get(): iterable
    {
        $this->reapStuckInflight();

        // The Lua pop reads the lowest due member (ZRANGEBYSCORE upper-bounded
        // at `now` — the scheduled-delivery gate: a future-scored member dated
        // via PersistentRefreshMessage::$deliverAt stays invisible until due),
        // then ZREM + HGET + HSET-inflight in one uninterruptible step so no
        // second consumer can claim the same id between read and remove.
        $now = time();

        try {
            $result = $this->redis->eval(
                self::POP_SCRIPT,
                [
                    $this->zsetKey,
                    $this->messagesKey,
                    $this->inflightKey,
                    self::POP_SCRIPT_TAG,
                    (string)$now,
                    (string)json_encode(['poppedAt' => $now], JSON_UNESCAPED_SLASHES),
                ],
                3
            );
        } catch (\Throwable $e) {
            throw new TransportException('datahub.priority_transport: get pipeline failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($result) || $result === []) {
            return [];
        }

        $marker = (string)($result[0] ?? '');

        if ($marker === self::POP_EMPTY) {
            return [];
        }

        // ZREM removing nothing inside an atomic EVAL means the member was removed
        // externally before the EVAL ran — manual ZREM, FLUSHDB, or a non-transport
        // client bypassing the Lua path. Surface it loudly rather than silently
        // returning [].
        if ($marker === self::POP_ZREM_MISS) {
            $this->logWarning('datahub.priority_transport: zRem == 0 — member removed externally before the pop EVAL ran');

            return [];
        }

        if ($marker !== self::POP_TORN && $marker !== self::POP_OK) {
            $this->logError('datahub.priority_transport: unknown pop marker \'' . $marker . '\'');

            return [];
        }

        $id = (string)($result[1] ?? '');

        if ($marker === self::POP_TORN || !is_string($result[2] ?? null)) {
            $this->logWarning('datahub.priority_transport: torn write — id ' . $id . ' in ZSET but absent from messages HASH; discarding');

            try {
                $this->redis->hDel($this->inflightKey, $id);
            } catch (\Throwable) {
                // best effort
            }

            return [];
        }

        $body = (string)$result[2];

        try {
            $envelope = $this->serializer->decode(['body' => $body]);
        } catch (\Throwable $e) {
            $this->logError('datahub.priority_transport: decode failed for id ' . $id . ': ' . $e->getMessage());

            try {
                $this->redis->multi();
                $this->redis->hDel($this->messagesKey, $id);
                $this->redis->hDel($this->inflightKey, $id);
                $this->redis->exec();
            } catch (\Throwable) {
                // best effort
            }

            return [];
        }

        return [$envelope->with(new TransportMessageIdStamp($id))];
    }

    /**
     * MULTI-atomic and N-consumer-safe: each consumer acks only the exclusive
     * id it won via the atomic ZREM in get(), so two acks always operate on
     * distinct ids. HDEL is idempotent, so a duplicate ack of the same id is
     * also safe.
     */
    public function ack(Envelope $envelope): void
    {
        $id = $this->extractId($envelope);
        if ($id === null) {
            return;
        }

        try {
            $this->redis->multi();
            $this->redis->hDel($this->messagesKey, $id);
            $this->redis->hDel($this->inflightKey, $id);
            $ackResult = $this->redis->exec();
        } catch (\Throwable $e) {
            throw new TransportException('datahub.priority_transport: ack failed: ' . $e->getMessage(), 0, $e);
        }

        if ($ackResult === false) {
            throw new TransportException('datahub.priority_transport: ack MULTI/EXEC returned false for id ' . $id);
        }
    }

    /**
     * MULTI-atomic and N-consumer-safe as-is, same idempotent-HDEL reasoning
     * as ack().
     */
    public function reject(Envelope $envelope): void
    {
        $id = $this->extractId($envelope);
        if ($id === null) {
            return;
        }

        try {
            $this->redis->multi();
            $this->redis->hDel($this->messagesKey, $id);
            $this->redis->hDel($this->inflightKey, $id);
            $rejectResult = $this->redis->exec();
        } catch (\Throwable $e) {
            throw new TransportException('datahub.priority_transport: reject failed: ' . $e->getMessage(), 0, $e);
        }

        if ($rejectResult === false) {
            throw new TransportException('datahub.priority_transport: reject MULTI/EXEC returned false for id ' . $id);
        }

        $this->logError('datahub.priority_transport: message rejected (id ' . $id . ')');
    }

    /**
     * MULTI-atomic and N-consumer-safe as-is: send only adds (ZADD + HSET), so
     * a concurrent pop cannot tear an in-progress send — at worst the pop runs
     * just before the ZADD and the message waits for the next get().
     */
    public function send(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();

        // Always mint a fresh queue id, even when the envelope arrives with a
        // TransportMessageIdStamp. Messenger's retry flow re-sends the received
        // envelope (carrying that stamp) and *then* reject()s the original by
        // the same id; reusing the inbound id would let that reject() HDEL the
        // body of the just-re-queued retry copy, discarding it as a torn write
        // — the retry would be silently dropped instead of redelivered. A fresh
        // id keeps the retry copy distinct from the original the worker rejects.
        $id = $this->newMessageId();

        $score = $this->scoreFor($message);

        // A RedeliveryStamp marks a retry re-send; bump its score so a contended
        // message sinks behind fresher arrivals instead of busy-looping ahead of
        // them.
        if ($envelope->last(RedeliveryStamp::class) !== null) {
            $score += $this->requeueScoreBump;
        }

        // Honor Messenger's DelayStamp as a ZSET visibility floor: a re-queued
        // message (e.g. a retry after herd-lock contention) stays invisible
        // until now + delay instead of re-popping immediately and tight-spinning
        // the worker. Applied as a score floor — never via PersistentRefreshMessage
        // deliverAt — so once due it still sorts by intrinsic staleness and is not
        // misread as a scheduled cooldown pop. A genuinely-scheduled message owns
        // its deliverAt score verbatim and is exempt.
        $delayStamp = $envelope->last(DelayStamp::class);
        if ($delayStamp !== null && !($message instanceof PersistentRefreshMessage && $message->deliverAt !== null)) {
            $score = max($score, time() + (int)ceil($delayStamp->getDelay() / 1000));
        }

        try {
            $encoded = $this->serializer->encode($envelope);
        } catch (\Throwable $e) {
            throw new TransportException('datahub.priority_transport: encode failed: ' . $e->getMessage(), 0, $e);
        }

        // This transport persists only the body — correct solely for the
        // header-less PhpSerializer (the framework default), which packs every
        // stamp into the body. A header-carrying serializer (e.g. the Symfony
        // serializer) puts stamps in headers; dropping them would silently lose
        // RedeliveryStamp (retry counting → no dead-lettering) and any custom
        // stamp. Fail loud rather than ship that silently.
        if (!empty($encoded['headers'])) {
            throw new TransportException(
                'datahub.priority_transport: serializer emitted transport headers, but this transport persists only the '
                . 'body and is coupled to the header-less PhpSerializer. Keep the default PhpSerializer for this transport, '
                . 'or extend send()/get() to persist headers — otherwise message stamps would be silently dropped.'
            );
        }

        $body = (string)($encoded['body'] ?? '');
        if ($body === '') {
            throw new TransportException('datahub.priority_transport: encoded envelope has empty body');
        }

        try {
            $this->redis->multi();
            $this->redis->zAdd($this->zsetKey, $score, $id);
            $this->redis->hSet($this->messagesKey, $id, $body);
            $sendResult = $this->redis->exec();
        } catch (\Throwable $e) {
            throw new TransportException('datahub.priority_transport: send pipeline failed: ' . $e->getMessage(), 0, $e);
        }

        if ($sendResult === false) {
            throw new TransportException('datahub.priority_transport: send MULTI/EXEC returned false for id ' . $id);
        }

        return $envelope->with(new TransportMessageIdStamp($id));
    }

    public function getMessageCount(): int
    {
        try {
            $count = $this->redis->zCard($this->zsetKey);
        } catch (\Throwable $e) {
            throw new TransportException('datahub.priority_transport: zCard failed: ' . $e->getMessage(), 0, $e);
        }

        return is_int($count) ? $count : 0;
    }

    /**
     * Score-extraction hook for the priority queue.
     *
     * Under `oldest_refreshed_at_first` (the default), the canonical source of
     * truth is `PersistentRefreshMessage::$scoreBaseline`; otherwise falls back
     * to `time()` so non-Persistent messages and Persistent messages with no
     * per-entry context are still ordered against now.
     *
     * Under `oldest_refreshed_at_first_with_weight_bands`, the same baseline
     * is offset by `priorityWeight * weightBandSeconds` so higher-weight
     * messages drop into an earlier (smaller-score) band. A missing
     * `priorityWeight` falls back to the classifier's neutral default `1`
     * — never `0`, which would collide with an explicit `priority_weight: 0`
     * declaration and silently merge an unclassified op into the "no-bias"
     * band an operator picked for a classified op.
     *
     * A non-null `PersistentRefreshMessage::$deliverAt` short-circuits both
     * branches: the score is the absolute due-time verbatim. Weight banding is
     * a priority among due messages; a scheduled message's due-time is when it
     * becomes eligible at all, so banding must not perturb it.
     *
     * A read-triggered message (`PersistentRefreshMessage::$readTriggered` and
     * no deliverAt) has a read-trigger offset (`$readTriggerOffsetSeconds`)
     * subtracted from its score under both banded/timestamp strategies, so every
     * demand-driven read sorts strictly below every speculative warm of the same
     * scoreBaseline. The hard-guarantee constraint `offset > priority_weight ×
     * $weightBandSeconds` (enforced by the config default) keeps a read below
     * even the highest-weight warm; the offset is sourced from config so it stays
     * coupled to the weight-band tuning rather than hardcoded.
     *
     * @internal test seam — called directly by PriorityRedisTransportScoreTest
     */
    public function scoreFor(object $message): int
    {
        if ($message instanceof PersistentRefreshMessage && $message->deliverAt !== null) {
            return $message->deliverAt;
        }

        return match ($this->priorityStrategy) {
            'oldest_refreshed_at_first' => $this->baseScore($message) - $this->readTriggerOffsetFor($message),
            'oldest_refreshed_at_first_with_weight_bands' => $this->baseScore($message) - ($this->weightFor($message) * $this->weightBandSeconds) - $this->readTriggerOffsetFor($message),
            'disabled' => $this->baseScore($message),
            default => throw new \LogicException('datahub.priority_transport: unsupported priority strategy "' . $this->priorityStrategy . '"'),
        };
    }

    private function readTriggerOffsetFor(object $message): int
    {
        if ($message instanceof PersistentRefreshMessage && $message->readTriggered) {
            return $this->readTriggerOffsetSeconds;
        }

        return 0;
    }

    private function baseScore(object $message): int
    {
        if ($message instanceof PersistentRefreshMessage && $message->scoreBaseline !== null) {
            return $message->scoreBaseline;
        }

        return time();
    }

    private function weightFor(object $message): int
    {
        if ($message instanceof PersistentRefreshMessage && $message->priorityWeight !== null) {
            return $message->priorityWeight;
        }

        return 1;
    }

    /**
     * Reap stuck inflight entries: messages that were popped but never
     * acked/rejected within the visibility-timeout window get re-queued
     * with their intrinsic score (read back from the stored envelope) so
     * the queue ordering invariant survives consumer restarts and OOMs.
     */
    private function reapStuckInflight(): void
    {
        try {
            $entries = $this->redis->hGetAll($this->inflightKey);
        } catch (\Throwable) {
            return;
        }

        if (!is_array($entries) || $entries === []) {
            return;
        }

        $now = time();
        $threshold = $now - $this->visibilityTimeout;
        $reaped = 0;
        $scanned = 0;

        foreach ($entries as $rawId => $meta) {
            ++$scanned;
            if ($scanned > 10) {
                break;
            }
            $id = (string)$rawId;
            $poppedAt = 0;
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                if (is_array($decoded) && isset($decoded['poppedAt']) && is_int($decoded['poppedAt'])) {
                    $poppedAt = $decoded['poppedAt'];
                }
            }
            // Pre-EVAL candidate skip only — RECLAIM_SCRIPT re-asserts staleness
            // atomically and is the authoritative TOCTOU-closing check.
            if ($poppedAt === 0 || $poppedAt >= $threshold) {
                continue;
            }

            try {
                $body = $this->redis->hGet($this->messagesKey, $id);
            } catch (\Throwable) {
                continue;
            }

            $score = $now;
            if (is_string($body) && $body !== '') {
                try {
                    $envelope = $this->serializer->decode(['body' => $body]);
                    // Re-deriving the score from the envelope preserves an
                    // absolute deliverAt across a reap, so a reaped scheduled
                    // refresh keeps its original due-time instead of drifting
                    // later each reap.
                    $score = $this->scoreFor($envelope->getMessage());
                } catch (\Throwable) {
                    $score = $now;
                }
            }

            // Re-assert under the script that the id is still inflight and
            // still older than threshold before reclaiming: two reapers racing
            // the same snapshot must not both ZADD it. The script fails closed
            // (returns 0, no ZADD) when the entry is gone or already fresh.
            try {
                $reclaimed = $this->redis->eval(
                    self::RECLAIM_SCRIPT,
                    [
                        $this->zsetKey,
                        $this->inflightKey,
                        self::RECLAIM_SCRIPT_TAG,
                        $id,
                        (string)$score,
                        (string)$threshold,
                    ],
                    2
                );
                if ($reclaimed === 1) {
                    ++$reaped;
                }
            } catch (\Throwable $e) {
                $this->logError('datahub.priority_transport: reclaim script failed for id ' . $id . ': ' . $e->getMessage());
            }
        }

        if ($reaped > 0) {
            $this->logWarning('datahub.priority_transport: reaper returned ' . $reaped . ' stuck message(s)');
        }
    }

    private function extractId(Envelope $envelope): ?string
    {
        $stamp = $envelope->last(TransportMessageIdStamp::class);
        if (!$stamp instanceof TransportMessageIdStamp) {
            return null;
        }
        $id = (string)$stamp->getId();

        return $id === '' ? null : $id;
    }

    /**
     * Logger seam — overridable in tests so transport behaviour can be
     * exercised without a booted Pimcore kernel.
     */
    protected function logWarning(string $message): void
    {
        Logger::warning($message);
    }

    protected function logError(string $message): void
    {
        Logger::error($message);
    }

    /**
     * UUID-v4 message id generator — separated for test seams. PHP's
     * random_bytes is cryptographically strong; collisions across the
     * lifetime of this transport are statistically impossible.
     */
    protected function newMessageId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6))
        );
    }
}
