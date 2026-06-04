<?php

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

use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\HttpFoundation\Request;

class CheckConsumerPermissionsService
{
    public const TOKEN_HEADER = 'X-API-Key';

    public function performSecurityCheck(Request $request, Configuration $configuration): bool
    {
        // Background SWR refresh sub-requests are synthesized server-side from a
        // request that already passed this check; they carry no apikey of their
        // own. The attribute is not user-controllable (it lives in the request
        // attribute bag, never populated from HTTP input).
        if ($request->attributes->get('_datahub_persistent_refresh')) {
            return true;
        }
        $securityConfig = $configuration->getSecurityConfig();
        if ($securityConfig['method'] === Configuration::SECURITYCONFIG_AUTH_APIKEY) {
            $apiKey = $this->resolveApiKey($request) ?? '';
            if (is_array($securityConfig['apikey'])) {
                return in_array($apiKey, $securityConfig['apikey']);
            } else {
                return $apiKey === $securityConfig['apikey'];
            }
        }

        return false;
    }

    /**
     * Single source of truth for the request's apikey read order:
     * `apikey` header → `X-API-Key` header → `apikey` query param.
     * Shared by the security check and the development bypass gate so a future
     * read-order change can never drift between the two.
     *
     * Returns null only when none of the three sources carry a non-empty value.
     */
    public function resolveApiKey(Request $request): ?string
    {
        $apiKey = $request->headers->get('apikey');
        if (empty($apiKey)) {
            $apiKey = $request->headers->get(static::TOKEN_HEADER);
        }
        if (empty($apiKey)) {
            $apiKey = $request->query->getString('apikey');
        }

        return $apiKey === '' ? null : $apiKey;
    }
}
