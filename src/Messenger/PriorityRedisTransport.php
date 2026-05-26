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
 * - ZSET `<zset_key>` — score: refreshedAt unix-seconds, member: message id.
 *   ZPOPMIN returns the lowest score first (longest-stale wins).
 * - HASH `<messages_key>` — id to serialized envelope bytes (framework default
 *   transport serializer).
 * - HASH `<inflight_key>` — id to `{"poppedAt": <unix-ts>}`. Reaper scans this
 *   set on each `get()` and re-queues entries whose poppedAt is older than
 *   the visibility timeout.
 *
 * Atomic invariants:
 * - `send()`: ZADD + HSET in MULTI/EXEC. On retry (id already in inflight)
 *   the ZSET score is bumped by the requeue-score knob so contended messages
 *   sink behind fresher arrivals.
 * - `get()`: ZPOPMIN + HGET messages + HSET inflight in MULTI/EXEC.
 * - `ack()`/`reject()`: HDEL on both messages and inflight in MULTI/EXEC.
 *
 * Score source rule: under the default `oldest_refreshed_at_first` strategy,
 * `scoreFor()` reads `PersistentRefreshMessage::$refreshedAt` when non-null,
 * else `time()`. Under `oldest_refreshed_at_first_with_weight_bands`, the
 * score is `refreshedAt - (priorityWeight * weightBandSeconds)` so higher-weight
 * messages drop into an earlier band and ZPOPMIN pops them first among
 * same-aged peers; `priorityWeight = null` falls back to the classifier's
 * neutral default of `1`. No other message types are special-cased;
 * non-Persistent messages get `time()` and behave FIFO-equivalent.
 */
class PriorityRedisTransport implements TransportInterface, MessageCountAwareInterface
{
    public function __construct(
        private \Redis $redis,
        private SerializerInterface $serializer,
        private string $zsetKey,
        private string $messagesKey,
        private string $inflightKey,
        private int $visibilityTimeout,
        private int $requeueScoreBump,
        private string $priorityStrategy,
        private int $weightBandSeconds
    ) {
    }

    public function get(): iterable
    {
        $this->reapStuckInflight();

        try {
            $popped = $this->redis->zPopMin($this->zsetKey, 1);
        } catch (\Throwable $e) {
            throw new TransportException('datahub.priority_transport: zPopMin failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($popped) || $popped === []) {
            return [];
        }

        $id = (string)array_key_first($popped);
        if ($id === '') {
            return [];
        }

        try {
            $this->redis->multi();
            $this->redis->hGet($this->messagesKey, $id);
            $this->redis->hSet(
                $this->inflightKey,
                $id,
                (string)json_encode(['poppedAt' => time()], JSON_UNESCAPED_SLASHES)
            );
            $pipelineResult = $this->redis->exec();
        } catch (\Throwable $e) {
            throw new TransportException('datahub.priority_transport: get pipeline failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($pipelineResult) || $pipelineResult === [] || $pipelineResult[0] === false || !is_string($pipelineResult[0])) {
            $this->logWarning('datahub.priority_transport: torn write — id ' . $id . ' in ZSET but absent from messages HASH; discarding');

            try {
                $this->redis->hDel($this->inflightKey, $id);
            } catch (\Throwable) {
                // best effort
            }

            return [];
        }

        $body = $pipelineResult[0];

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

    public function send(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();

        $existingIdStamp = $envelope->last(TransportMessageIdStamp::class);
        $id = $existingIdStamp instanceof TransportMessageIdStamp
            ? (string)$existingIdStamp->getId()
            : $this->newMessageId();

        $score = $this->scoreFor($message);

        try {
            $isRetry = $this->redis->hExists($this->inflightKey, $id) === true;
        } catch (\Throwable $e) {
            throw new TransportException('datahub.priority_transport: hExists failed: ' . $e->getMessage(), 0, $e);
        }

        if ($isRetry) {
            $score += $this->requeueScoreBump;
        }

        try {
            $encoded = $this->serializer->encode($envelope);
        } catch (\Throwable $e) {
            throw new TransportException('datahub.priority_transport: encode failed: ' . $e->getMessage(), 0, $e);
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
     * truth is `PersistentRefreshMessage::$refreshedAt`; otherwise falls back
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
     */
    public function scoreFor(object $message): int
    {
        return match ($this->priorityStrategy) {
            'oldest_refreshed_at_first' => $this->baseScore($message),
            'oldest_refreshed_at_first_with_weight_bands' => $this->baseScore($message) - ($this->weightFor($message) * $this->weightBandSeconds),
            'disabled' => $this->baseScore($message),
            default => throw new \LogicException('datahub.priority_transport: unsupported priority strategy "' . $this->priorityStrategy . '"'),
        };
    }

    private function baseScore(object $message): int
    {
        if ($message instanceof PersistentRefreshMessage && $message->refreshedAt !== null) {
            return $message->refreshedAt;
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
                    $score = $this->scoreFor($envelope->getMessage());
                } catch (\Throwable) {
                    $score = $now;
                }
            }

            try {
                $this->redis->multi();
                $this->redis->zAdd($this->zsetKey, $score, $id);
                $this->redis->hDel($this->inflightKey, $id);
                $this->redis->exec();
                ++$reaped;
            } catch (\Throwable) {
                // best effort — next reaper pass will retry
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
