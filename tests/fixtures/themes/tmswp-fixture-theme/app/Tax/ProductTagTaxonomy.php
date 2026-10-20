<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Tax;

use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;

/**
 * 投稿タイプとの関連づけを持たないタクソノミー。ProductPost が PostTypeDefinition::taxonomies() で関連づける。
 */
#[AsTaxonomy]
final class ProductTagTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('tmswp_product_tag')
            ->label('製品タグ', '製品タグ');
    }
}
