<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service\RequestValidation;

use Pimcore\Bundle\DataHubBundle\GraphQL\Exception\ClientSafeException;
use Pimcore\Logger;

/**
 * Request-level, versioned, default-deny variable validator for the GraphQL
 * endpoint. Rejection raises a ClientSafeException, which the controller renders
 * as an HTTP 400 GraphQL-shaped errors body before any resolver or cache layer
 * runs.
 *
 * The engine rejects nothing with shipped defaults: a request is rejected only
 * when a rules file is mounted AND parses, the client is in the enforced set,
 * and the operation/variables fail a positive rule.
 */
class RequestVariableValidator
{
    public const REJECT_MESSAGE_PREFIX = 'request rejected by request-validation: ';

    public const LOG_SLUG = 'datahub.request_validation.rejected';

    public const REASON_OPERATION_NOT_ALLOWED = 'operation-not-allowed';

    public const REASON_UNKNOWN_VARIABLE = 'unknown-variable';

    public const REASON_CONSTRAINT_FAILED = 'constraint-failed';

    private const VALUE_LOG_LIMIT = 40;

    /**
     * @param list<string> $enforcedClients
     */
    public function __construct(
        private readonly RulesLoader $rulesLoader,
        private readonly array $enforcedClients,
    ) {
    }

    /** Whether request-validation rules are enforced for the given client. */
    public function isEnforced(string $clientName): bool
    {
        return in_array($clientName, $this->enforcedClients, true);
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @throws ClientSafeException on any rejection
     */
    public function assertRequest(string $clientName, ?int $version, ?string $operationName, array $variables): void
    {
        $rules = $this->rulesLoader->load();
        if ($rules === null) {
            return;
        }
        if (!in_array($clientName, $this->enforcedClients, true)) {
            return;
        }

        $rulesVersion = $rules->forVersionOrLatest($version);
        if ($rulesVersion === null) {
            return;
        }

        $rule = $operationName !== null ? $rulesVersion->operationRule($operationName) : null;
        if ($rule === null) {
            $this->reject($clientName, $version, $operationName, self::REASON_OPERATION_NOT_ALLOWED, null, null);
        }
        assert($rule !== null);

        foreach (array_keys($variables) as $name) {
            $name = (string)$name;
            if (!$rule->hasVariable($name)) {
                $this->reject($clientName, $version, $operationName, self::REASON_UNKNOWN_VARIABLE, $name, null);
            }
        }

        foreach ($rule->variables() as $name => $constraint) {
            $value = array_key_exists($name, $variables) ? $variables[$name] : null;
            if (!$constraint->matches($value)) {
                $this->reject($clientName, $version, $operationName, self::REASON_CONSTRAINT_FAILED, $name, $value);
            }
        }
    }

    /**
     * @throws ClientSafeException
     */
    private function reject(
        string $clientName,
        ?int $version,
        ?string $operationName,
        string $reason,
        ?string $variableName,
        mixed $value,
    ): never {
        $this->logWarning(self::LOG_SLUG, [
            'client' => $clientName,
            'version' => $version,
            'operation' => $operationName,
            'reason' => $reason,
            'variable' => $variableName,
            'value' => $value === null ? null : $this->truncate($value),
        ]);

        throw new ClientSafeException(self::REJECT_MESSAGE_PREFIX . $reason);
    }

    private function truncate(mixed $value): string
    {
        $string = is_scalar($value) ? (string)$value : gettype($value);

        return strlen($string) > self::VALUE_LOG_LIMIT
            ? substr($string, 0, self::VALUE_LOG_LIMIT) . '…'
            : $string;
    }

    /**
     * Routes through Pimcore\Logger, which is a no-op without a booted container.
     * Subclasses (test seams) override this to capture calls without a container.
     *
     * @param array<string, mixed> $context
     */
    protected function logWarning(string $slug, array $context): void
    {
        Logger::warning($slug, $context);
    }
}
