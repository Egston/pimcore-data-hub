<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service;

enum CooldownInvalidationDecisionKind: string
{
    case LEADING_EDGE = 'leading_edge';
    case COALESCE_ARMED = 'coalesce_armed';
    case OPEN_TRAILING = 'open_trailing';
}
