<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use Pimcore\Bundle\DataHubBundle\Service\PersistentOutputCacheService;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\DocumentEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Marks the persistent GraphQL cache as potentially stale when content changes.
 *
 * This is a best-effort fallback to track a last-invalidation timestamp that
 * we compare against cached responses. It does not depend on Pimcore's 'output'
 * tag invalidation directly, but on relevant content change events instead.
 */
class PersistentCacheInvalidationListener implements EventSubscriberInterface
{
    public function __construct(private PersistentOutputCacheService $persistentCache)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DataObjectEvents::POST_UPDATE => 'mark',
            DataObjectEvents::POST_DELETE => 'mark',
            DocumentEvents::POST_UPDATE => 'mark',
            DocumentEvents::POST_DELETE => 'mark',
            AssetEvents::POST_UPDATE => 'mark',
            AssetEvents::POST_DELETE => 'mark',
        ];
    }

    public function mark(): void
    {
        $this->persistentCache->markOutputInvalidated();
    }
}

