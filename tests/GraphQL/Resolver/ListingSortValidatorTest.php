<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\GraphQL\Resolver;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\GraphQL\Exception\ClientSafeException;
use Pimcore\Bundle\DataHubBundle\GraphQL\Resolver\ListingSortValidator;

final class ListingSortValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validArgsProvider(): iterable
    {
        yield 'no sort args at all' => [[]];
        yield 'null sortBy and sortOrder' => [['sortBy' => null, 'sortOrder' => null]];
        yield 'empty-string sortOrder is skipped like the resolver does' => [['sortOrder' => '']];
        yield 'empty-array sortOrder' => [['sortBy' => ['name'], 'sortOrder' => []]];
        yield 'sortBy without sortOrder' => [['sortBy' => 'name']];
        yield 'string ASC' => [['sortBy' => 'name', 'sortOrder' => 'ASC']];
        yield 'lowercase desc' => [['sortBy' => 'name', 'sortOrder' => 'desc']];
        yield 'list of valid orders' => [['sortBy' => ['a', 'b'], 'sortOrder' => ['asc', 'DESC']]];
        // empty() treats '0', 0, and false as absent — mirror the resolver gate exactly.
        yield 'string zero sortOrder treated as not-provided' => [['sortOrder' => '0']];
        yield 'integer zero sortOrder treated as not-provided' => [['sortOrder' => 0]];
        yield 'boolean false sortOrder treated as not-provided' => [['sortOrder' => false]];
    }

    /**
     * @dataProvider validArgsProvider
     *
     * @param array<string, mixed> $args
     */
    public function testValidArgsPass(array $args): void
    {
        ListingSortValidator::assertValid($args);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidArgsProvider(): iterable
    {
        yield 'sortOrder without sortBy' => [['sortOrder' => 'ASC']];
        yield 'sortOrder with null sortBy' => [['sortBy' => null, 'sortOrder' => 'DESC']];
        yield 'junk sortOrder without sortBy' => [['sortOrder' => '-1 OR 5*5=25']];
        yield 'junk sortOrder with sortBy' => [['sortBy' => 'name', 'sortOrder' => "nullX' OR 281=(SELECT 281 FROM PG_SLEEP(15))--"]];
        yield 'junk value among valid ones' => [['sortBy' => ['a', 'b'], 'sortOrder' => ['ASC', 'sleep(15)']]];
        yield 'non-string sortOrder value' => [['sortBy' => 'name', 'sortOrder' => [12]]];
        // boolean true passes empty() and wraps to [true], then fails is_string.
        yield 'boolean true sortOrder' => [['sortBy' => 'name', 'sortOrder' => true]];
        // strtoupper does not trim — whitespace-padded values are invalid.
        yield 'whitespace-padded sortOrder' => [['sortBy' => 'name', 'sortOrder' => ' ASC ']];
    }

    /**
     * @dataProvider invalidArgsProvider
     *
     * @param array<string, mixed> $args
     */
    public function testInvalidArgsThrowClientSafe(array $args): void
    {
        $this->expectException(ClientSafeException::class);

        ListingSortValidator::assertValid($args);
    }

    public function testInvalidValueIsTruncatedInMessage(): void
    {
        try {
            ListingSortValidator::assertValid(['sortBy' => 'name', 'sortOrder' => str_repeat('x', 200)]);
            self::fail('expected ClientSafeException');
        } catch (ClientSafeException $e) {
            self::assertLessThan(120, strlen($e->getMessage()));
        }
    }
}
