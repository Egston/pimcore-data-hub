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

namespace Pimcore\Bundle\DataHubBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Message\PersistentRefreshMessage;
use Pimcore\Bundle\DataHubBundle\Service\CooldownWindowDispatcher;
use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CooldownWindowDispatcherTest extends TestCase
{
    public function testOpenArmsAndDispatchesWithExactDeliverAt(): void
    {
        $hash = 'abc123';
        $cooldownTtl = 21600;
        $deliverAt = time() + $cooldownTtl;

        $template = new PersistentRefreshMessage(
            client: 'c1',
            bodyJson: '{"operationName":"OpTest"}',
            operationName: 'OpTest',
            refreshedAt: time(),
            priorityWeight: 5,
        );

        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->expects(self::once())
            ->method('armOperationCooldown')
            ->with($hash, $cooldownTtl);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$dispatched) {
            $dispatched[] = $msg;

            return new Envelope($msg);
        });

        $dispatcher = new CooldownWindowDispatcher($bus, $persistentCache);
        $dispatcher->open($hash, $cooldownTtl, $deliverAt, $template);

        self::assertCount(1, $dispatched, 'open() must dispatch exactly one message');

        $msg = $dispatched[0];
        self::assertInstanceOf(PersistentRefreshMessage::class, $msg);
        self::assertSame('c1', $msg->client);
        self::assertSame('{"operationName":"OpTest"}', $msg->bodyJson);
        self::assertSame('OpTest', $msg->operationName);
        self::assertSame(5, $msg->priorityWeight);
        self::assertSame($deliverAt, $msg->deliverAt, 'dispatcher must use the caller-supplied deliverAt verbatim');
        self::assertFalse($msg->readTriggered, 'a dispatcher-created message is a warm, never a read');
    }

    public function testOpenArmsBeforeDispatch(): void
    {
        $callOrder = [];

        $persistentCache = $this->createMock(PersistentOutputCacheService::class);
        $persistentCache->method('armOperationCooldown')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'arm';
            });

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$callOrder) {
            $callOrder[] = 'dispatch';

            return new Envelope($msg);
        });

        $template = new PersistentRefreshMessage('c1', '{"operationName":"Op"}', 'Op');

        $dispatcher = new CooldownWindowDispatcher($bus, $persistentCache);
        $dispatcher->open('h', 60, time() + 60, $template);

        self::assertSame(['arm', 'dispatch'], $callOrder, 'arm must happen before dispatch (ML-003 ordering)');
    }

    public function testSummariseVariablesCharacterisation(): void
    {
        $body = '{"query":"{ __typename }","variables":{"lang":"en","page":1}}';

        $result = PersistentOutputCacheService::summariseVariables($body);

        self::assertSame('{"lang":"en","page":1}', $result, 'summariseVariables must produce the canonical variables JSON');
    }

    public function testSummariseVariablesEmptyVars(): void
    {
        self::assertSame('{}', PersistentOutputCacheService::summariseVariables('{"query":"q","variables":{}}'));
    }

    public function testSummariseVariablesUnparseable(): void
    {
        self::assertSame('?', PersistentOutputCacheService::summariseVariables('not-json'));
    }

    public function testSummariseVariablesTruncates(): void
    {
        $longValue = str_repeat('x', 300);
        $body = json_encode(['variables' => ['k' => $longValue]]);
        $result = PersistentOutputCacheService::summariseVariables((string)$body);

        self::assertSame(200, strlen($result));
        self::assertStringEndsWith('...', $result);
    }
}
