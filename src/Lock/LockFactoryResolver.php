<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Lock;

use Pimcore\Logger;

/**
 * Shared Symfony LockFactory resolver. Prefers a Redis-backed factory built
 * from REDIS_DSN — auto-expiry, no row-leak, faster than DBAL UPDATEs each
 * refresh tick — and falls back to whatever Pimcore wires as the default
 * (typically DoctrineDbalStore-backed). Returns null when Symfony Lock is
 * not installed at all, so callers can degrade gracefully.
 */
class LockFactoryResolver
{
    private ?object $cachedFactory = null;

    private bool $resolved = false;

    public function resolve(): ?object
    {
        if ($this->resolved) {
            return $this->cachedFactory;
        }
        $this->resolved = true;

        if (!class_exists('Symfony\\Component\\Lock\\LockFactory')) {
            return null;
        }

        try {
            $dsn = getenv('REDIS_DSN') ?: ($_ENV['REDIS_DSN'] ?? null);
            if (is_string($dsn) && $dsn !== ''
                && class_exists('Symfony\\Component\\Lock\\Store\\StoreFactory')) {
                $store = \Symfony\Component\Lock\Store\StoreFactory::createStore($dsn);
                $this->cachedFactory = new \Symfony\Component\Lock\LockFactory($store);

                return $this->cachedFactory;
            }
        } catch (\Throwable $e) {
            Logger::warning(
                'DataHub LockFactoryResolver: Redis-backed factory unavailable, '
                . 'falling back to Pimcore default: ' . $e->getMessage()
            );
        }

        try {
            $container = \Pimcore::getContainer();
            if ($container && $container->has('lock.factory')) {
                $this->cachedFactory = $container->get('lock.factory');

                return $this->cachedFactory;
            }
            if ($container && $container->has('Symfony\\Component\\Lock\\LockFactory')) {
                $this->cachedFactory = $container->get('Symfony\\Component\\Lock\\LockFactory');

                return $this->cachedFactory;
            }
        } catch (\Throwable $e) {
            Logger::warning(
                'DataHub LockFactoryResolver: Pimcore default lock factory unavailable; '
                . 'cold-miss and herd-guard locks disabled this request: ' . $e->getMessage()
            );
        }

        return null;
    }
}
