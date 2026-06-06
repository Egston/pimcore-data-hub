<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service;

/**
 * Payload-shape granularity of a classified GraphQL operation; drives the
 * per-granularity TTL default lookup in OperationClassifier::getTtl() when
 * no per-operation ttl_override is set.
 */
enum Granularity: string
{
    case SINGLE = 'single';
    case LIST = 'list';
}
