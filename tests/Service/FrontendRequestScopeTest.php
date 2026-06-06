<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\FrontendRequestScope;
use Pimcore\Http\RequestHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class FrontendRequestScopeTest extends TestCase
{
    public function testMarksRequestFrontendAndPushesItAsMainRequestDuringCallable(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/datahub/graphql', 'POST');

        $observedMain = null;
        $result = FrontendRequestScope::run($stack, $request, function () use ($stack, &$observedMain) {
            $observedMain = $stack->getMainRequest();

            return 'value';
        });

        self::assertSame('value', $result);
        self::assertSame($request, $observedMain, 'request must be the stack main request during the callable');
        self::assertTrue(
            $request->attributes->get(RequestHelper::ATTRIBUTE_FRONTEND_REQUEST),
            'request must be marked frontend so Asset::getFullPath() urlencodes'
        );
        self::assertNull($stack->getMainRequest(), 'stack must be popped back to empty after the callable');
    }

    public function testPopsRequestWhenCallableThrows(): void
    {
        $stack = new RequestStack();
        $request = Request::create('/datahub/graphql', 'POST');

        $caught = null;

        try {
            FrontendRequestScope::run($stack, $request, static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'callable exceptions must propagate');
        self::assertNull($stack->getMainRequest(), 'stack must be popped even when the callable throws');
    }

    public function testNullStackStillRunsCallableAndMarksRequest(): void
    {
        $request = Request::create('/datahub/graphql', 'POST');

        $result = FrontendRequestScope::run(null, $request, static fn (): string => 'ran');

        self::assertSame('ran', $result);
        self::assertTrue($request->attributes->get(RequestHelper::ATTRIBUTE_FRONTEND_REQUEST));
    }
}
