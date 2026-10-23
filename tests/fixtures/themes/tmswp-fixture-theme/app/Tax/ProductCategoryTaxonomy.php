<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Tax;

use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;
use TmswpFixtureTheme\Post\ProductPost;

#[AsTaxonomy]
final class ProductCategoryTaxonomy extends Taxonomy
{
    #[MetaSchema(type: MetaValueType::String, default: 'gray')]
    public const META_COLOR = 'tmswp_term_color';

    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('tmswp_product_cat')
            ->label('製品カテゴリ', '製品カテゴリ')
            ->objectType(ProductPost::class)
            ->hierarchical()
            ->showInRest();
    }
}
