<?php

declare(strict_types=1);

namespace TMSWP\Core\Entity;

use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Tax\Taxonomy;

/**
 * @internal
 */
final class EntityReference
{
    private function __construct() {}

    public static function postTypeSlug(string $reference): string
    {
        if (!class_exists($reference)) {
            return $reference;
        }

        if (!is_subclass_of($reference, PostType::class)) {
            throw new EntityDefinitionException("class '{$reference}' must extend " . PostType::class);
        }

        return $reference::slug();
    }

    public static function taxonomySlug(string $reference): string
    {
        if (!class_exists($reference)) {
            return $reference;
        }

        if (!is_subclass_of($reference, Taxonomy::class)) {
            throw new EntityDefinitionException("class '{$reference}' must extend " . Taxonomy::class);
        }

        return $reference::slug();
    }
}
