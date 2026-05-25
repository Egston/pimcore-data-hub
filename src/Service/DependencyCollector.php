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

namespace Pimcore\Bundle\DataHubBundle\Service;

use Pimcore\Model\Element\ElementInterface;

/**
 * Request-scoped collector of Pimcore element dependencies touched while
 * resolving a GraphQL operation. The POST_LOAD subscriber funnels every loaded
 * element here; the persistent-cache write path then asks
 * tagsForGranularity() to convert the collected set into either per-object or
 * per-class cache tags, which are merged with the existing categorical tags
 * (TAG_COMMON, TAG_CLIENT_PREFIX, TAG_OP_PREFIX) into the tag list passed to
 * Pimcore\Cache::save().
 *
 * Tag-character invariant: tag names use `_` as the only separator. Class
 * FQCN backslashes are substituted with `_` so the generated tags survive
 * PSR-6 validation, which rejects `{}()/\@:` in either keys or tags.
 *
 * Lifecycle: registered as a `shared: true` Symfony service for autowiring
 * convenience; per-request scoping is enforced by an explicit reset on
 * `kernel.request` (Symfony removed request_scope in 4+). The reset listener
 * runs at priority 4096 to precede this bundle's other request-time listeners.
 */
class DependencyCollector
{
    public const TAG_OBJECT_PREFIX = 'datahub_graphql_obj_';

    public const TAG_CLASS_PREFIX = 'datahub_graphql_class_';

    /** @var array<string, true> Keyed by "<sanitized-class>_<id>"; the value is unused. */
    private array $perObject = [];

    /** @var array<string, true> Keyed by sanitized class FQCN. */
    private array $perClass = [];

    public function recordObject(ElementInterface $element): void
    {
        $id = $element->getId();
        if ($id === null) {
            return;
        }
        $class = self::sanitizeClass(get_class($element));
        $this->perClass[$class] = true;
        $this->perObject[$class . '_' . $id] = true;
    }

    /**
     * @return list<string>
     */
    public function tagsForGranularity(Granularity $granularity): array
    {
        if ($granularity === Granularity::SINGLE) {
            $tags = [];
            foreach (array_keys($this->perObject) as $suffix) {
                $tags[] = self::TAG_OBJECT_PREFIX . $suffix;
            }

            return $tags;
        }

        $tags = [];
        foreach (array_keys($this->perClass) as $class) {
            $tags[] = self::TAG_CLASS_PREFIX . $class;
        }

        return $tags;
    }

    public function reset(): void
    {
        $this->perObject = [];
        $this->perClass = [];
    }

    public function hasRecordedAny(): bool
    {
        return $this->perObject !== [];
    }

    private static function sanitizeClass(string $fqcn): string
    {
        return str_replace('\\', '_', ltrim($fqcn, '\\'));
    }
}
