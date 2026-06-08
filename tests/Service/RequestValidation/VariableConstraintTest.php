<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service\RequestValidation;

use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\VariableConstraint;

final class VariableConstraintTest extends TestCase
{
    public function testEnumMatchesMemberAndRejectsNonMember(): void
    {
        $constraint = VariableConstraint::enum(['en', 'de', 'ja', 'zh', 'zh_Hant_TW']);
        self::assertTrue($constraint->matches('en'));
        self::assertTrue($constraint->matches('zh_Hant_TW'));
        self::assertFalse($constraint->matches('fr'));
        self::assertFalse($constraint->matches('; DROP'));
        self::assertFalse($constraint->matches(null));
    }

    public function testEnumIsStrictlyTyped(): void
    {
        $constraint = VariableConstraint::enum([1, 2, 3]);
        self::assertTrue($constraint->matches(1));
        self::assertFalse($constraint->matches('1'));
    }

    public function testConstMatchesExactValueOnly(): void
    {
        $constraint = VariableConstraint::constant(1000);
        self::assertTrue($constraint->matches(1000));
        self::assertFalse($constraint->matches(999));
        self::assertFalse($constraint->matches(1001));
        self::assertFalse($constraint->matches('1000'));
    }

    public function testNullMatchesNullOnly(): void
    {
        $constraint = VariableConstraint::null();
        self::assertTrue($constraint->matches(null));
        self::assertFalse($constraint->matches(0));
        self::assertFalse($constraint->matches(''));
        self::assertFalse($constraint->matches('anything'));
    }

    public function testIntBoundsAndNullable(): void
    {
        $constraint = VariableConstraint::int(1, null, true);
        self::assertTrue($constraint->matches(1));
        self::assertTrue($constraint->matches(999));
        self::assertTrue($constraint->matches(null));
        self::assertFalse($constraint->matches(0));
        self::assertFalse($constraint->matches(-1));
        self::assertFalse($constraint->matches('5'));
        self::assertFalse($constraint->matches(1.5));
    }

    public function testIntNonNullableRejectsNull(): void
    {
        $constraint = VariableConstraint::int(1, null, false);
        self::assertTrue($constraint->matches(1));
        self::assertFalse($constraint->matches(null));
    }

    public function testIntUpperBound(): void
    {
        $constraint = VariableConstraint::int(1, 10, false);
        self::assertTrue($constraint->matches(10));
        self::assertFalse($constraint->matches(11));
    }

    public function testStringCharsetAndNullable(): void
    {
        $constraint = VariableConstraint::string(true);
        self::assertTrue($constraint->matches('Article/Some-Path.html'));
        self::assertTrue($constraint->matches(null));
        self::assertFalse($constraint->matches("line\nbreak"));
        self::assertFalse($constraint->matches("'; DROP TABLE--"));
        self::assertFalse($constraint->matches('has;semicolon'));
        self::assertFalse($constraint->matches(123));
    }

    public function testStringNonNullableRejectsNull(): void
    {
        $constraint = VariableConstraint::string(false);
        self::assertTrue($constraint->matches('safe'));
        self::assertFalse($constraint->matches(null));
    }

    public function testStringWithRequiredPrefix(): void
    {
        $constraint = VariableConstraint::string(false, '/Site Content/');
        self::assertTrue($constraint->matches('/Site Content/foo'));
        self::assertFalse($constraint->matches('/Other/foo'));
        self::assertFalse($constraint->matches("/Site Content/\n"));
    }

    public function testCsvIntValidAndInvalid(): void
    {
        $constraint = VariableConstraint::csvInt();
        self::assertTrue($constraint->matches('1,2,3'));
        self::assertTrue($constraint->matches('42'));
        self::assertFalse($constraint->matches('1,x'));
        self::assertFalse($constraint->matches('1,'));
        self::assertFalse($constraint->matches('-1,2'));
        self::assertFalse($constraint->matches('1, 2'));
        self::assertFalse($constraint->matches(''));
        self::assertFalse($constraint->matches(null));
        self::assertFalse($constraint->matches(123));
    }
}
