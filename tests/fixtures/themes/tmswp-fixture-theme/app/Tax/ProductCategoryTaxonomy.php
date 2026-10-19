<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Tax;

use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;
use TmswpFixtureTheme\Post\ProductPost;

#[AsTaxonomy]
final class ProductCategoryTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('tmswp_product_cat')
            ->label('製品カテゴリ')
            ->objectType(ProductPost::class)
            ->hierarchical()
            ->showInRest();
    }
}
