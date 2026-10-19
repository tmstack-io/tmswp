<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use WP_Post;

#[AsPostType]
final class ProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 0, showInRest: true)]
    public const META_PRICE = 'price';

    #[MetaSchema(type: MetaValueType::String, default: '', showInRest: true)]
    public const META_SKU = 'sku';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')
            ->label('製品')
            ->labelLanguage(LabelLanguage::Ja)
            ->supports('title', 'editor', 'excerpt', 'thumbnail')
            ->hasArchive()
            ->showInRest()
            ->menuIcon('dashicons-products');
    }

    public static function price(WP_Post|int $post): int
    {
        return self::meta($post)->int(self::META_PRICE);
    }

    public static function sku(WP_Post|int $post): string
    {
        return self::meta($post)->string(self::META_SKU);
    }
}
