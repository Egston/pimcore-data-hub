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
use Pimcore\Bundle\DataHubBundle\Service\GraphQLRequestCanonicalizer;

final class GraphQLRequestCanonicalizerTest extends TestCase
{
    public function testCanonicalizeProducesByteEqualOutputForReorderedJsonKeys(): void
    {
        $a = json_encode(['query' => '{ a }', 'variables' => ['x' => 1], 'operationName' => 'Q']);
        $b = json_encode(['operationName' => 'Q', 'variables' => ['x' => 1], 'query' => '{ a }']);

        self::assertSame(
            GraphQLRequestCanonicalizer::canonicalize((string)$a),
            GraphQLRequestCanonicalizer::canonicalize((string)$b)
        );
    }

    public function testCanonicalizeProducesByteEqualOutputForReorderedNestedKeys(): void
    {
        $a = json_encode(['variables' => ['z' => 3, 'a' => 1, 'm' => 2]]);
        $b = json_encode(['variables' => ['a' => 1, 'm' => 2, 'z' => 3]]);

        self::assertSame(
            GraphQLRequestCanonicalizer::canonicalize((string)$a),
            GraphQLRequestCanonicalizer::canonicalize((string)$b)
        );
    }

    public function testCanonicalizeNormalizesQueryAstWhitespace(): void
    {
        $a = json_encode(['query' => 'query Q { a b }']);
        $b = json_encode(['query' => "query Q {\n    a\n    b\n}"]);

        self::assertSame(
            GraphQLRequestCanonicalizer::canonicalize((string)$a),
            GraphQLRequestCanonicalizer::canonicalize((string)$b)
        );
    }

    public function testCanonicalizeReturnsEmptyArrayForInvalidJson(): void
    {
        // json_decode('not json') → null → payload coerced to [] → json_encode([]) → '[]'.
        // The '{}' fallback documented in the helper applies to json_encode failure,
        // not to decode failure — modern PHP rejects malformed-UTF8 at decode time
        // so the encode-side guard is effectively unreachable via the public API,
        // but the guard stays in source as defensive coding.
        self::assertSame('[]', GraphQLRequestCanonicalizer::canonicalize('not json'));
    }

    public function testCanonicalizeReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame('[]', GraphQLRequestCanonicalizer::canonicalize(''));
    }

    public function testCanonicalizePreservesZeroFraction(): void
    {
        $body = '{"variables":{"v":1.0}}';
        $canonical = GraphQLRequestCanonicalizer::canonicalize($body);
        self::assertStringContainsString('1.0', $canonical);
    }

    public function testCanonicalizePreservesUnicodeUnescaped(): void
    {
        $body = json_encode(['variables' => ['v' => '日本']]);
        $canonical = GraphQLRequestCanonicalizer::canonicalize((string)$body);
        self::assertStringContainsString('日本', $canonical);
        self::assertStringNotContainsString('\\u', $canonical);
    }

    public function testCanonicalizePreservesSlashesUnescaped(): void
    {
        $body = json_encode(['variables' => ['v' => 'a/b']]);
        $canonical = GraphQLRequestCanonicalizer::canonicalize((string)$body);
        self::assertStringContainsString('a/b', $canonical);
        self::assertStringNotContainsString('a\\/b', $canonical);
    }

    public function testCanonicalizeHandlesQueryWithGraphQLParseFailureGracefully(): void
    {
        $body = json_encode(['query' => '   query{...   ']);
        $canonical = GraphQLRequestCanonicalizer::canonicalize((string)$body);
        // The trimmed-fallback path keeps the malformed query but strips
        // surrounding whitespace.
        self::assertStringContainsString('query{...', $canonical);
        self::assertStringNotContainsString('"   query{...   "', $canonical);
    }

    public function testCanonicalizeIsIdempotent(): void
    {
        $body = json_encode(['query' => 'query Q { a }', 'variables' => ['z' => 3, 'a' => 1]]);
        $once = GraphQLRequestCanonicalizer::canonicalize((string)$body);
        $twice = GraphQLRequestCanonicalizer::canonicalize($once);
        self::assertSame($once, $twice);
    }

    public function testCanonicalizeIndexedArraysPreserveOrder(): void
    {
        $body = '{"variables":{"ids":[3,1,2]}}';
        $canonical = GraphQLRequestCanonicalizer::canonicalize($body);
        // ksort would re-sort the values; we must NOT do that for list arrays.
        self::assertStringContainsString('[3,1,2]', $canonical);
    }

    public function testNormalizeQueryAstAndKsortRecursiveAreNotPubliclyCallable(): void
    {
        $rc = new \ReflectionClass(GraphQLRequestCanonicalizer::class);

        self::assertTrue($rc->hasMethod('normalizeQueryAst'));
        self::assertTrue($rc->getMethod('normalizeQueryAst')->isPrivate());

        self::assertTrue($rc->hasMethod('ksortRecursive'));
        self::assertTrue($rc->getMethod('ksortRecursive')->isPrivate());
    }
}
