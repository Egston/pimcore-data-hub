<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Service;

enum CooldownTrailingDecisionKind: string
{
    case CANCEL = 'cancel';
    case FIRE = 'fire';
    case REARM = 'rearm';
}
