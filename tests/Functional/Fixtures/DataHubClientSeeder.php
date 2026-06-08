<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures;

use Pimcore\Bundle\DataHubBundle\Configuration;

/**
 * Seeds the `default` DataHub GraphQL client config into the running Pimcore
 * instance for the L3 Functional suite.
 *
 * Idempotent: save() overwrites by name on every call, so the bootstrap can
 * be re-run safely against a warm namespace without a destructive delete.
 *
 * Failure contract: throws \RuntimeException on the first failure. No
 * try/catch-and-continue; the bootstrap script that drives this halts on any
 * exception.
 */
final class DataHubClientSeeder
{
    private const CLIENT_NAME = 'default';

    /**
     * Both keys must be ≥16 characters (Configuration::save() enforces this).
     *
     * The primary key is attached by KernelTestCase::sendGraphQL() on every
     * dispatch. The bypass key matches the overlay's bypass_apikey so the
     * bypass-path test clears security even though the client enforces apikey.
     */
    private const APIKEYS = [
        'l3-test-security-apikey-0001',
        'test-bypass-key-do-not-use',
    ];

    private const QUERY_ENTITY_SWR_GUARDED  = 'TestSwrGuardedItem';

    private const QUERY_ENTITY_SWR_ONLY     = 'TestSwrOnlyItem';

    private const QUERY_ENTITY_UNCACHED     = 'TestUncachedItem';

    public function seedDefaultClient(): void
    {
        $payload = [
            'general' => [
                'active' => true,
                'type' => 'graphql',
                'name' => self::CLIENT_NAME,
                'path' => null,
            ],
            'schema' => [
                'queryEntities' => [
                    self::QUERY_ENTITY_SWR_GUARDED => ['id' => self::QUERY_ENTITY_SWR_GUARDED, 'name' => self::QUERY_ENTITY_SWR_GUARDED],
                    self::QUERY_ENTITY_SWR_ONLY    => ['id' => self::QUERY_ENTITY_SWR_ONLY,    'name' => self::QUERY_ENTITY_SWR_ONLY],
                    self::QUERY_ENTITY_UNCACHED    => ['id' => self::QUERY_ENTITY_UNCACHED,    'name' => self::QUERY_ENTITY_UNCACHED],
                ],
                'mutationEntities' => [],
                'specialEntities' => [
                    'object_folder' => [
                        'read' => true,
                        'create' => false,
                        'update' => false,
                        'delete' => false,
                    ],
                ],
            ],
            'security' => [
                'method' => Configuration::SECURITYCONFIG_AUTH_APIKEY,
                'apikey' => self::APIKEYS,
                'skipPermissionCheck' => true,
            ],
            'workspaces' => [
                'object' => [
                    [
                        'read' => true,
                        'create' => false,
                        'update' => false,
                        'delete' => false,
                        'cpath' => '/',
                        'id' => 'l3-seed-1',
                    ],
                ],
                'asset' => [],
                'document' => [],
            ],
            'permissions' => [
                'user' => [],
                'role' => [],
            ],
        ];

        $config = new Configuration('graphql', null, self::CLIENT_NAME, $payload);

        try {
            $config->save();
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'datahub.client-seeder: save failed for client "%s": %s',
                self::CLIENT_NAME,
                $e->getMessage()
            ), 0, $e);
        }

        $persisted = Configuration::getByName(self::CLIENT_NAME);
        if (!$persisted instanceof Configuration) {
            throw new \RuntimeException(sprintf(
                'datahub.client-seeder: client "%s" saved but could not be re-read — silent write failure',
                self::CLIENT_NAME
            ));
        }
        if (!$persisted->isActive()) {
            throw new \RuntimeException(sprintf(
                'datahub.client-seeder: client "%s" persisted but isActive() returned false — configuration not applied',
                self::CLIENT_NAME
            ));
        }

        $securityConfig = $persisted->getSecurityConfig();
        if (($securityConfig['method'] ?? null) !== Configuration::SECURITYCONFIG_AUTH_APIKEY) {
            throw new \RuntimeException(sprintf(
                'datahub.client-seeder: client "%s" security method is "%s", expected "%s" — security subtree not persisted',
                self::CLIENT_NAME,
                $securityConfig['method'] ?? '(null)',
                Configuration::SECURITYCONFIG_AUTH_APIKEY
            ));
        }

        $persistedKeys = (array) ($securityConfig['apikey'] ?? []);
        if (!in_array(self::APIKEYS[0], $persistedKeys, true)) {
            throw new \RuntimeException(sprintf(
                'datahub.client-seeder: client "%s" apikey list does not contain the primary test key — security.apikey subtree not persisted',
                self::CLIENT_NAME
            ));
        }

        $persistedEntities = $persisted->getQueryEntities();
        foreach ([self::QUERY_ENTITY_SWR_GUARDED, self::QUERY_ENTITY_SWR_ONLY, self::QUERY_ENTITY_UNCACHED] as $expectedEntity) {
            if (!in_array($expectedEntity, $persistedEntities, true)) {
                throw new \RuntimeException(sprintf(
                    'datahub.client-seeder: client "%s" queryEntities missing "%s" — schema subtree not persisted',
                    self::CLIENT_NAME,
                    $expectedEntity
                ));
            }
        }
    }
}
