<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service\RequestValidation;

/**
 * The whole loaded ruleset, keyed by integer version, with each version's
 * inheritance already flattened. Kernel-free value object.
 */
final class RulesSet
{
    /** @var array<int, RulesVersion> */
    private array $versions;

    private ?int $latestVersion;

    /**
     * @param array<int, RulesVersion> $versions
     */
    public function __construct(array $versions)
    {
        foreach (array_keys($versions) as $key) {
            if (!is_int($key)) {
                throw new \InvalidArgumentException(sprintf('RulesSet version keys must be integers, got %s', gettype($key)));
            }
        }
        ksort($versions);
        $this->versions = $versions;
        $keys = array_keys($versions);
        $this->latestVersion = $keys === [] ? null : max($keys);
    }

    public function latestVersion(): ?int
    {
        return $this->latestVersion;
    }

    /**
     * Resolves the requested version, or the latest version for a
     * missing/unknown version. Returns null only when the set is empty.
     */
    public function forVersionOrLatest(?int $version): ?RulesVersion
    {
        if ($version !== null && isset($this->versions[$version])) {
            return $this->versions[$version];
        }
        if ($this->latestVersion === null) {
            return null;
        }

        return $this->versions[$this->latestVersion];
    }

    public function isEmpty(): bool
    {
        return $this->versions === [];
    }
}
