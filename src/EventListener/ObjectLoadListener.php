<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\DataHubBundle\EventListener;

use Pimcore\Bundle\DataHubBundle\Service\DependencyCollector;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\DocumentEvents;
use Pimcore\Event\Model\AssetEvent;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Event\Model\DocumentEvent;
use Pimcore\Logger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Funnels every Pimcore element load (DataObject / Asset / Document) into
 * the request-scoped DependencyCollector. The collector's recordObject() is
 * safe to call outside GraphQL requests (admin UI, CLI) — it just records
 * into a request-local set that the reset listener wipes at the next
 * kernel.request boundary.
 *
 * Explicit priority pinning (0) keeps a stable insertion point for any
 * downstream listener that needs to run at a known boundary.
 */
class ObjectLoadListener implements EventSubscriberInterface
{
    public function __construct(private DependencyCollector $collector)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DataObjectEvents::POST_LOAD => ['onDataObjectPostLoad', 0],
            AssetEvents::POST_LOAD => ['onAssetPostLoad', 0],
            DocumentEvents::POST_LOAD => ['onDocumentPostLoad', 0],
        ];
    }

    public function onDataObjectPostLoad(DataObjectEvent $event): void
    {
        try {
            $this->collector->recordObject($event->getObject());
        } catch (\Throwable $e) {
            Logger::error('DataHub ObjectLoadListener: recordObject failed: ' . $e->getMessage());
        }
    }

    public function onAssetPostLoad(AssetEvent $event): void
    {
        try {
            $this->collector->recordObject($event->getAsset());
        } catch (\Throwable $e) {
            Logger::error('DataHub ObjectLoadListener: recordObject failed: ' . $e->getMessage());
        }
    }

    public function onDocumentPostLoad(DocumentEvent $event): void
    {
        try {
            $this->collector->recordObject($event->getDocument());
        } catch (\Throwable $e) {
            Logger::error('DataHub ObjectLoadListener: recordObject failed: ' . $e->getMessage());
        }
    }
}
