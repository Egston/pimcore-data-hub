<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Message;

final class PersistentRefreshMessage
{
    public function __construct(
        public readonly string $client,
        public readonly string $bodyJson,
        public readonly ?string $operationName = null
    ) {
    }
}

