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

namespace Pimcore\Bundle\DataHubBundle\Service;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Printer;

/**
 * Single source of truth for canonicalising a raw GraphQL request body into
 * the byte-stable form fed to every cache-key / lock-key hash in the bundle.
 *
 * The standard-cache key path (see {@see OutputCacheService::computeKey}) and
 * the persistent-cache key path (see
 * {@see PersistentOutputCacheService::canonicalizePayloadString}) MUST agree
 * byte-for-byte for the same input, otherwise the no-double-write contract
 * between the two cache tiers regresses (a request canonicalised one way on
 * the read path and another on the write path produces two cache entries for
 * the same logical query). Holding both paths against one helper makes that
 * agreement structural rather than convention-by-copy-paste.
 *
 * Pure-static; no constructor, no DI surface, no kernel-touching paths.
 */
final class GraphQLRequestCanonicalizer
{
    /**
     * Canonicalise a raw request body string. Returns `'[]'` when the body
     * does not decode to a JSON array (decode failure path); returns `'{}'`
     * on json_encode failure. Both fallbacks are carried verbatim from the
     * pre-refactor implementation and pinned by tests so the failure modes
     * cannot drift.
     */
    public static function canonicalize(string $body): string
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        if (!empty($payload['query']) && is_string($payload['query'])) {
            $payload['query'] = self::normalizeQueryAst($payload['query']);
        }

        $payload = self::ksortRecursive($payload);

        $canonical = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        return is_string($canonical) ? $canonical : '{}';
    }

    private static function normalizeQueryAst(string $query): string
    {
        try {
            /** @var DocumentNode $ast */
            $ast = Parser::parse($query);

            return Printer::doPrint($ast);
        } catch (\Throwable $e) {
            return trim($query);
        }
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function ksortRecursive(array $value): array
    {
        $isAssoc = static function (array $a): bool {
            $i = 0;
            foreach ($a as $k => $_) {
                if ($k !== $i++) {
                    return true;
                }
            }

            return false;
        };

        if ($isAssoc($value)) {
            ksort($value);
        }

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::ksortRecursive($v);
            }
        }

        return $value;
    }
}
