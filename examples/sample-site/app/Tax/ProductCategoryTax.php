<?php

declare(strict_types=1);

namespace App\Tax;

use App\Post\ProductPost;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;

#[AsTaxonomy]
final class ProductCategoryTax extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('product_category')
            ->label('製品カテゴリー')
            ->labelLanguage(LabelLanguage::Ja)
            ->objectType(ProductPost::class)
            ->hierarchical()
            ->showAdminColumn()
            ->showInRest()
            ->rewriteSlug('product-category');
    }
}
