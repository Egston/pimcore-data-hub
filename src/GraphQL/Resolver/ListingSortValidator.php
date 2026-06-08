<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\GraphQL\Resolver;

use Pimcore\Bundle\DataHubBundle\GraphQL\Exception\ClientSafeException;

/**
 * Validates the sortBy/sortOrder argument pair shared by the listing
 * resolvers, rejecting invalid input with a client-safe GraphQL error.
 *
 * The resolvers only read sortOrder inside their sortBy branch, so a
 * sortOrder arriving without sortBy used to be silently ignored: the query
 * executed as a vanilla listing and returned a valid response. Combined with
 * the persistent cache hashing the full variables JSON, every junk sortOrder
 * variant (scanner probes, typos) minted a distinct cache entry with an
 * identical payload — each then re-resolved on every invalidation for its
 * whole payload TTL. When the validator throws, the resolver field is nulled
 * and the response contains only errors; the cache admission gate refuses
 * all-null-data responses, so junk variants never enter the cache.
 */
final class ListingSortValidator
{
    private const VALID_ORDERS = ['ASC', 'DESC'];

    private const MESSAGE_VALUE_LIMIT = 40;

    /**
     * @param array<string, mixed> $args
     *
     * @throws ClientSafeException
     */
    public static function assertValid(array $args): void
    {
        // Mirror the resolvers' !empty() gate: null, '' and [] mean "not provided".
        if (empty($args['sortOrder'])) {
            return;
        }

        if (empty($args['sortBy'])) {
            throw new ClientSafeException('sortOrder requires sortBy');
        }

        $orders = is_array($args['sortOrder']) ? $args['sortOrder'] : [$args['sortOrder']];

        foreach ($orders as $order) {
            if (!is_string($order) || !in_array(strtoupper($order), self::VALID_ORDERS, true)) {
                throw new ClientSafeException(sprintf(
                    'invalid sortOrder value %s; expected ASC or DESC',
                    json_encode(self::truncate($order))
                ));
            }
        }
    }

    private static function truncate(mixed $value): string
    {
        $string = is_scalar($value) ? (string)$value : gettype($value);

        return strlen($string) > self::MESSAGE_VALUE_LIMIT
            ? substr($string, 0, self::MESSAGE_VALUE_LIMIT) . '…'
            : $string;
    }
}
