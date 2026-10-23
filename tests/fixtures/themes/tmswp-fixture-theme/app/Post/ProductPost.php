<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TmswpFixtureTheme\Tax\ProductTagTaxonomy;

#[AsPostType]
final class ProductPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_product')
            ->label('製品', '製品')
            ->hasArchive()
            ->showInRest()
            ->supports('title', 'thumbnail')
            ->taxonomies(ProductTagTaxonomy::class)
            ->rewriteSlug('tmswp_product');
    }
}
