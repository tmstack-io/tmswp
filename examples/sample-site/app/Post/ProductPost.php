<?php

declare(strict_types=1);

namespace SampleSite\Post;

use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use WP_Post;

/**
 * 投稿タイプ「製品」。ラベルはtmswp-kernel.phpのlabelLanguage(日本語)で作る。
 *
 * 価格(price)はACFのフィールドグループ「製品情報」(acf-json/group_product.json)のnumberのフィールドで、
 * 単純な値なので#[MetaSchema]で宣言し、MetaBagで読み書きする。#[UseMetaStorage(AcfMetaStorage::class)]により、
 * 保存先はACFのフィールドと同じメタになり、ACFの画面で編集した値をMetaBagで読める。
 * 同じグループの仕様(spec。groupの型)は複合型なので、生成アクセサ(SampleSite\Acf\ProductFields)で読む。
 */
#[AsPostType]
#[UseMetaStorage(AcfMetaStorage::class)]
final class ProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 0)]
    public const string META_PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')
            ->label('製品')
            ->supports('title', 'editor', 'excerpt', 'thumbnail')
            ->hasArchive()
            ->showInRest()
            ->menuIcon('dashicons-products')
            // 製品の本文の編集画面で使えるブロックを限る(rules/blocks.md)
            ->allowedBlocks('core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/image');
    }

    /** 価格(円)。未設定は0 */
    public static function price(WP_Post|int $post): int
    {
        return self::meta($post)->int(self::META_PRICE);
    }
}
