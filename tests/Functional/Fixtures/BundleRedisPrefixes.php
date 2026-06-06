<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures;

/**
 * Authoritative enumeration of the bundle's Redis prefix families. Kernel-free
 * value class so the contract can be pinned by a host-runnable unit sentinel
 * without dragging the Pimcore test-support base into the autoload graph.
 *
 * Adding a new bundle-owned prefix is a deliberate change here so the
 * inter-test flush in {@see KernelTestCase::flushBundleKeys()} stays exact.
 */
final class BundleRedisPrefixes
{
    private function __construct()
    {
    }

    /** @var list<string> */
    public const ALL = [
        'datahub_inprogress_',
        'datahub_inprogress:',
        'datahub_refresh_priority_',
        'datahub_persistent_refresh_lock_',
        'persistent_output_payload_',
        'persistent_output_meta_',
        'taginx_',
        'datahub_enqueue_req_',
        'datahub_graphql_obj_',
        'datahub_graphql_class_',
    ];
}
