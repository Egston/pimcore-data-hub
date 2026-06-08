<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service\RequestValidation;

/**
 * One positive constraint on a single request variable. A request value passes
 * only when it matches the declared kind; anything else is rejected by
 * construction (default-deny). Pure value object, kernel-free.
 */
final class VariableConstraint
{
    public const KIND_ENUM = 'enum';

    public const KIND_CONST = 'const';

    public const KIND_NULL = 'null';

    public const KIND_INT = 'int';

    public const KIND_STRING = 'string';

    public const KIND_CSV_INT = 'csv-int';

    /**
     * Allowlist safe charset for `string` variables. Anchored and explicit:
     * letters, digits, space, and the path/punctuation set the frontend actually
     * sends in fullpath-style values. The allowlist is exhaustive; anything not
     * listed — including semicolons, quotes, control characters — is denied by
     * construction.
     */
    private const SAFE_STRING_PATTERN = '/\A[A-Za-z0-9 _\-\/.]+\z/';

    /**
     * @param list<int|string|bool|float|null> $enumValues
     */
    private function __construct(
        private readonly string $kind,
        private readonly array $enumValues = [],
        private readonly int|string|bool|float|null $constValue = null,
        private readonly ?int $intMin = null,
        private readonly ?int $intMax = null,
        private readonly bool $nullable = false,
        private readonly ?string $requiredPrefix = null,
    ) {
    }

    /**
     * Unlike `int()` / `string()`, this factory has no `$nullable` parameter:
     * null is accepted only when `null` is itself a member of `$values`
     * (strict in-array). The nullability lives in the value list, not a flag.
     *
     * @param list<int|string|bool|float|null> $values
     */
    public static function enum(array $values): self
    {
        return new self(self::KIND_ENUM, enumValues: $values);
    }

    public static function constant(int|string|bool|float|null $value): self
    {
        return new self(self::KIND_CONST, constValue: $value);
    }

    public static function null(): self
    {
        return new self(self::KIND_NULL);
    }

    public static function int(?int $min, ?int $max, bool $nullable): self
    {
        return new self(self::KIND_INT, intMin: $min, intMax: $max, nullable: $nullable);
    }

    public static function string(bool $nullable, ?string $requiredPrefix = null): self
    {
        return new self(self::KIND_STRING, nullable: $nullable, requiredPrefix: $requiredPrefix);
    }

    /**
     * Unlike `int()` / `string()`, this factory has no `$nullable` parameter:
     * a CSV-int value is never nullable — null (and the empty string) are always
     * rejected; only a non-empty comma-separated list of digit runs passes.
     */
    public static function csvInt(): self
    {
        return new self(self::KIND_CSV_INT);
    }

    public function matches(mixed $value): bool
    {
        return match ($this->kind) {
            self::KIND_ENUM => in_array($value, $this->enumValues, true),
            self::KIND_CONST => $value === $this->constValue,
            self::KIND_NULL => $value === null,
            self::KIND_INT => $this->matchesInt($value),
            self::KIND_STRING => $this->matchesString($value),
            self::KIND_CSV_INT => $this->matchesCsvInt($value),
            default => throw new \LogicException('unhandled constraint kind ' . $this->kind),
        };
    }

    private function matchesInt(mixed $value): bool
    {
        if ($value === null) {
            return $this->nullable;
        }
        if (!is_int($value)) {
            return false;
        }
        if ($this->intMin !== null && $value < $this->intMin) {
            return false;
        }
        if ($this->intMax !== null && $value > $this->intMax) {
            return false;
        }

        return true;
    }

    private function matchesString(mixed $value): bool
    {
        if ($value === null) {
            return $this->nullable;
        }
        if (!is_string($value)) {
            return false;
        }
        if (preg_match(self::SAFE_STRING_PATTERN, $value) !== 1) {
            return false;
        }
        if ($this->requiredPrefix !== null && !str_starts_with($value, $this->requiredPrefix)) {
            return false;
        }

        return true;
    }

    private function matchesCsvInt(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        foreach (explode(',', $value) as $part) {
            if (!ctype_digit($part)) {
                return false;
            }
        }

        return true;
    }
}
