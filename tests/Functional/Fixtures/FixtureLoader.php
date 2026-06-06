<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures;

use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Folder;

/**
 * Loads the bundle's L3 fixture data files into the running Pimcore instance.
 * Idempotent: deletes the fixture parent folder's children before recreating.
 *
 * Failure contract: throws on the first failure with a structured \RuntimeException
 * naming the failed file / class / item.
 *
 * @phpstan-type FixtureItem array{key: string, localized?: array<string, array<string, string>>, category?: string, language?: string}
 * @phpstan-type FixtureFile array{class: string, parentFolder: string, items: list<FixtureItem>}
 */
final class FixtureLoader
{
    private const FIXTURE_DATA_DIR = __DIR__ . '/fixture-data';

    /**
     * The three L3 fixture data files in load order.
     *
     * @var list<string>
     */
    private const FIXTURE_FILES = [
        'swr-guarded-items.json',
        'swr-only-items.json',
        'uncached-items.json',
    ];

    /**
     * Loads every fixture file under {@see FIXTURE_FILES} into Pimcore.
     *
     * @return array<string, list<int>> map of class-name to list of created object ids
     *
     * @throws \RuntimeException when any individual fixture step fails
     */
    public function loadAll(): array
    {
        $created = [];
        foreach (self::FIXTURE_FILES as $filename) {
            $path = self::FIXTURE_DATA_DIR . '/' . $filename;
            if (!is_file($path)) {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: file not found: %s',
                    $path
                ));
            }
            $raw = file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: file empty or unreadable: %s',
                    $path
                ));
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: file is not valid JSON: %s',
                    $path
                ));
            }
            $className = (string)($decoded['class'] ?? '');
            $parentFolder = (string)($decoded['parentFolder'] ?? '');
            $items = $decoded['items'] ?? [];
            if ($className === '' || $parentFolder === '' || !is_array($items)) {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: file %s missing class / parentFolder / items',
                    $filename
                ));
            }

            $ids = $this->loadFile($filename, $className, $parentFolder, $items);
            $created[$className] = $ids;
        }

        return $created;
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<int>
     */
    private function loadFile(string $filename, string $className, string $parentFolder, array $items): array
    {
        $classDefinition = ClassDefinition::getByName($className);
        if (!$classDefinition instanceof ClassDefinition) {
            throw new \RuntimeException(sprintf(
                'datahub.fixtures: class %s not installed (run pimcore:class-definitions:import first); fixture file %s aborted',
                $className,
                $filename
            ));
        }

        $folder = $this->ensureFolderTree($parentFolder, $filename);
        $this->purgeChildren($folder);

        $modelFqcn = '\\Pimcore\\Model\\DataObject\\' . $className;
        if (!class_exists($modelFqcn)) {
            throw new \RuntimeException(sprintf(
                'datahub.fixtures: generated model %s does not exist; class %s was imported but classes-rebuild has not run',
                $modelFqcn,
                $className
            ));
        }

        $ids = [];
        foreach ($items as $position => $item) {
            if (!is_array($item)) {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: fixture %s item at index %d is not an object',
                    $filename,
                    $position
                ));
            }
            $key = (string)($item['key'] ?? '');
            if ($key === '') {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: fixture %s item at index %d missing key',
                    $filename,
                    $position
                ));
            }

            /** @var Concrete $object */
            $object = new $modelFqcn();
            $object->setKey($key);
            $object->setParent($folder);
            $object->setPublished(true);

            $this->applyFields($object, $item, $filename, $key);

            try {
                $object->save();
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: save failed for %s/%s: %s',
                    $className,
                    $key,
                    $e->getMessage()
                ), 0, $e);
            }

            $id = $object->getId();
            if ($id === null) {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: %s/%s saved but no id assigned',
                    $className,
                    $key
                ));
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function applyFields(Concrete $object, array $item, string $filename, string $key): void
    {
        $localized = $item['localized'] ?? [];
        if (is_array($localized)) {
            foreach ($localized as $locale => $values) {
                if (!is_string($locale) || !is_array($values)) {
                    throw new \RuntimeException(sprintf(
                        'datahub.fixtures: fixture %s/%s has malformed localized block',
                        $filename,
                        $key
                    ));
                }
                foreach ($values as $field => $value) {
                    if (!is_string($field)) {
                        throw new \RuntimeException(sprintf(
                            'datahub.fixtures: fixture %s/%s localized block for locale %s has a non-string field key',
                            $filename,
                            $key,
                            $locale
                        ));
                    }
                    $setter = 'set' . ucfirst($field);
                    if (!method_exists($object, $setter)) {
                        throw new \RuntimeException(sprintf(
                            'datahub.fixtures: fixture %s/%s references unknown localized field %s',
                            $filename,
                            $key,
                            $field
                        ));
                    }
                    $object->{$setter}($value, $locale);
                }
            }
        }

        foreach ($item as $field => $value) {
            if (in_array($field, ['key', 'localized'], true)) {
                continue;
            }
            if (!is_string($field)) {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: fixture %s/%s has a non-string field key',
                    $filename,
                    $key
                ));
            }
            $setter = 'set' . ucfirst($field);
            if (!method_exists($object, $setter)) {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: fixture %s/%s references unknown field %s',
                    $filename,
                    $key,
                    $field
                ));
            }
            $object->{$setter}($value);
        }
    }

    private function ensureFolderTree(string $path, string $filename): Folder
    {
        if ($path === '' || $path[0] !== '/') {
            throw new \RuntimeException(sprintf(
                'datahub.fixtures: parentFolder %s in %s must be an absolute Pimcore path starting with /',
                $path,
                $filename
            ));
        }

        $segments = array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));
        $current = '/';
        $parent = DataObject::getByPath($current);
        if (!$parent instanceof AbstractObject) {
            throw new \RuntimeException('datahub.fixtures: root DataObject folder / not loadable');
        }

        foreach ($segments as $segment) {
            $current = rtrim($current, '/') . '/' . $segment;
            $node = DataObject::getByPath($current);
            if ($node instanceof Folder) {
                $parent = $node;

                continue;
            }
            if ($node !== null && !$node instanceof Folder) {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: %s exists but is not a folder',
                    $current
                ));
            }
            $created = new Folder();
            $created->setKey($segment);
            $created->setParent($parent);

            try {
                $created->save();
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: failed to create folder %s: %s',
                    $current,
                    $e->getMessage()
                ), 0, $e);
            }
            $parent = $created;
        }

        if (!$parent instanceof Folder) {
            throw new \RuntimeException(sprintf(
                'datahub.fixtures: final node %s is not a folder',
                $path
            ));
        }

        return $parent;
    }

    private function purgeChildren(Folder $folder): void
    {
        $listing = $folder->getChildren([
            AbstractObject::OBJECT_TYPE_OBJECT,
            AbstractObject::OBJECT_TYPE_FOLDER,
            AbstractObject::OBJECT_TYPE_VARIANT,
        ], true);
        $children = iterator_to_array($listing, false);
        foreach ($children as $child) {
            try {
                $child->delete();
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf(
                    'datahub.fixtures: failed to purge existing child %s before reload: %s',
                    $child->getRealFullPath(),
                    $e->getMessage()
                ), 0, $e);
            }
        }
    }
}
