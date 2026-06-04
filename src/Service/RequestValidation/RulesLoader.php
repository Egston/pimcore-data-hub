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

namespace Pimcore\Bundle\DataHubBundle\Service\RequestValidation;

use Pimcore\Logger;

/**
 * Loads the request-validation rules from a JSON file mounted by a ConfigMap.
 *
 * The engine is a no-op until a real file is mounted: an empty/unset path or a
 * missing file (with no prior successful load) yields null. After a successful
 * load, a temporarily-missing or unreadable file retains the last-known-good set.
 * The file is re-stat'd on every call and re-parsed only when its mtime changed,
 * so live ConfigMap edits propagate without a restart while steady-state calls
 * cost a single stat. On a parse failure or schema-invalid file the loader keeps
 * the last-known-good set and logs loudly; a first-load failure fails to no-op
 * (null) rather than to deny — the engine stays inert instead of 400-ing all
 * traffic on a malformed first file.
 */
class RulesLoader
{
    public const LOG_SLUG = 'datahub.request_validation.rules_load_failed';

    private const KIND_ENUM = VariableConstraint::KIND_ENUM;

    private const KIND_CONST = VariableConstraint::KIND_CONST;

    private const KIND_NULL = VariableConstraint::KIND_NULL;

    private const KIND_INT = VariableConstraint::KIND_INT;

    private const KIND_STRING = VariableConstraint::KIND_STRING;

    private const KIND_CSV_INT = VariableConstraint::KIND_CSV_INT;

    private ?RulesSet $lastKnownGood = null;

    private ?int $parsedMtime = null;

    public function __construct(private readonly string $rulesFilePath)
    {
    }

    public function load(): ?RulesSet
    {
        clearstatcache(true, $this->rulesFilePath);

        if ($this->rulesFilePath === '' || !is_file($this->rulesFilePath)) {
            return $this->lastKnownGood;
        }

        $mtime = $this->readMtime($this->rulesFilePath);
        if ($mtime === false) {
            $this->logError(self::LOG_SLUG, [
                'path' => $this->rulesFilePath,
                'error' => 'filemtime() failed after is_file() passed',
                'retained_last_known_good' => $this->lastKnownGood !== null,
            ]);

            return $this->lastKnownGood;
        }

        if ($this->lastKnownGood !== null && $mtime === $this->parsedMtime) {
            return $this->lastKnownGood;
        }

        try {
            $set = $this->parseAndBuild();
        } catch (\Throwable $e) {
            $this->logError(self::LOG_SLUG, [
                'path' => $this->rulesFilePath,
                'error' => $e->getMessage(),
                'retained_last_known_good' => $this->lastKnownGood !== null,
            ]);

            return $this->lastKnownGood;
        }

        $this->lastKnownGood = $set;
        $this->parsedMtime = $mtime;

        return $set;
    }

    private function parseAndBuild(): RulesSet
    {
        $raw = @file_get_contents($this->rulesFilePath);
        if ($raw === false) {
            throw new \RuntimeException('unable to read rules file');
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['versions'] ?? null)) {
            throw new \RuntimeException('rules file must contain a "versions" object');
        }

        /** @var array<int, array<string, OperationRule>> $flattenedByVersion */
        $flattenedByVersion = [];
        /** @var array<int, array<string, mixed>> $rawByVersion */
        $rawByVersion = [];
        foreach ($decoded['versions'] as $versionKey => $versionBody) {
            if (!ctype_digit((string)$versionKey)) {
                throw new \RuntimeException('rules version keys must be non-negative integers');
            }
            if (!is_array($versionBody)) {
                throw new \RuntimeException('each rules version must be an object');
            }
            $rawByVersion[(int)$versionKey] = $versionBody;
        }

        ksort($rawByVersion);

        $versions = [];
        foreach ($rawByVersion as $version => $body) {
            $operations = $this->resolveOperations($version, $body, $flattenedByVersion);
            $flattenedByVersion[$version] = $operations;
            $versions[$version] = new RulesVersion(
                array_map(static fn (array $vars): OperationRule => new OperationRule($vars), $operations)
            );
        }

        return new RulesSet($versions);
    }

    /**
     * @param array<string, mixed>                          $body
     * @param array<int, array<string, array<string, VariableConstraint>>> $flattenedByVersion
     *
     * @return array<string, array<string, VariableConstraint>>
     */
    private function resolveOperations(int $version, array $body, array $flattenedByVersion): array
    {
        $operations = [];
        if (isset($body['inherits'])) {
            $parent = $body['inherits'];
            if (!is_int($parent) || !isset($flattenedByVersion[$parent])) {
                throw new \RuntimeException(sprintf('version %d inherits unknown version', $version));
            }
            $operations = $flattenedByVersion[$parent];
        }

        $ops = $body['operations'] ?? [];
        if (!is_array($ops)) {
            throw new \RuntimeException(sprintf('version %d "operations" must be an object', $version));
        }

        foreach ($ops as $operationName => $operationBody) {
            if (!is_string($operationName) || $operationName === '') {
                throw new \RuntimeException(sprintf('version %d has an invalid operation name', $version));
            }
            if ($operationBody === null) {
                unset($operations[$operationName]);

                continue;
            }
            if (!is_array($operationBody)) {
                throw new \RuntimeException(sprintf('operation %s must be an object or null', $operationName));
            }
            $operations[$operationName] = $this->buildVariables($operationName, $operationBody);
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $operationBody
     *
     * @return array<string, VariableConstraint>
     */
    private function buildVariables(string $operationName, array $operationBody): array
    {
        $varsSpec = $operationBody['variables'] ?? [];
        if (!is_array($varsSpec)) {
            throw new \RuntimeException(sprintf('operation %s "variables" must be an object', $operationName));
        }

        $variables = [];
        foreach ($varsSpec as $variableName => $spec) {
            if (!is_string($variableName) || $variableName === '') {
                throw new \RuntimeException(sprintf('operation %s has an invalid variable name', $operationName));
            }
            if (!is_array($spec)) {
                throw new \RuntimeException(sprintf('variable %s.%s must be an object', $operationName, $variableName));
            }
            $variables[$variableName] = $this->buildConstraint($operationName, $variableName, $spec);
        }

        return $variables;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function buildConstraint(string $operationName, string $variableName, array $spec): VariableConstraint
    {
        $kind = $spec['kind'] ?? null;
        $where = sprintf('%s.%s', $operationName, $variableName);

        return match ($kind) {
            self::KIND_ENUM => VariableConstraint::enum($this->requireScalarList($spec, $where)),
            self::KIND_CONST => VariableConstraint::constant($this->requireScalarOrNull($spec['value'] ?? null, $where)),
            self::KIND_NULL => VariableConstraint::null(),
            self::KIND_INT => VariableConstraint::int(
                isset($spec['min']) ? $this->requireInt($spec['min'], $where) : null,
                isset($spec['max']) ? $this->requireInt($spec['max'], $where) : null,
                (bool)($spec['nullable'] ?? false),
            ),
            self::KIND_STRING => VariableConstraint::string(
                (bool)($spec['nullable'] ?? false),
                isset($spec['prefix']) ? $this->requireString($spec['prefix'], $where) : null,
            ),
            self::KIND_CSV_INT => VariableConstraint::csvInt(),
            default => throw new \RuntimeException(sprintf('variable %s has unknown constraint kind %s', $where, json_encode($kind))),
        };
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return list<int|string|bool|float|null>
     */
    private function requireScalarList(array $spec, string $where): array
    {
        $values = $spec['values'] ?? null;
        if (!is_array($values) || $values === []) {
            throw new \RuntimeException(sprintf('enum %s requires a non-empty "values" array', $where));
        }
        $list = [];
        foreach ($values as $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new \RuntimeException(sprintf('enum %s values must be scalar', $where));
            }
            $list[] = $value;
        }

        return $list;
    }

    private function requireScalarOrNull(mixed $value, string $where): int|string|bool|float|null
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \RuntimeException(sprintf('const %s value must be scalar or null', $where));
        }

        return $value;
    }

    private function requireInt(mixed $value, string $where): int
    {
        if (!is_int($value)) {
            throw new \RuntimeException(sprintf('int bound for %s must be an integer', $where));
        }

        return $value;
    }

    private function requireString(mixed $value, string $where): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException(sprintf('prefix for %s must be a string', $where));
        }

        return $value;
    }

    /**
     * Overrideable in test seams to inject a forced failure without filesystem races.
     *
     */
    protected function readMtime(string $path): int|false
    {
        return @filemtime($path);
    }

    /**
     * Routes through Pimcore\Logger, which is a no-op without a booted container.
     * Subclasses (test seams) override this to capture calls without a container.
     *
     * @param array<string, mixed> $context
     */
    protected function logError(string $slug, array $context): void
    {
        Logger::error($slug, $context);
    }
}
