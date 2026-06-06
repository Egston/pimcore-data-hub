<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service;

use Pimcore\Http\RequestHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Runs a callable with $request as the RequestStack main request, marked as a
 * frontend request.
 *
 * Out-of-request GraphQL executions (messenger worker, console command,
 * kernel.terminate — where the stack is already empty) otherwise serialize
 * asset paths unencoded: Asset::getFullPath() urlencodes only when
 * Tool::isFrontend(), which reads the stack's main request. Every persistent
 * cache writer must run inside this scope so cache content never depends on
 * which process wrote it.
 */
final class FrontendRequestScope
{
    public static function run(?RequestStack $requestStack, Request $request, callable $fn): mixed
    {
        $request->attributes->set(RequestHelper::ATTRIBUTE_FRONTEND_REQUEST, true);

        if ($requestStack === null) {
            return $fn();
        }

        $requestStack->push($request);

        try {
            return $fn();
        } finally {
            $requestStack->pop();
        }
    }
}
