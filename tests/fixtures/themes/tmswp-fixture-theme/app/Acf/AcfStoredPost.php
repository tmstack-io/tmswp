<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Acf;

use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

/**
 * ACFのフィールドグループ(acf-json/group_tmswp_acf_post.json)で管理するキーpriceを、#[MetaSchema]と
 * #[UseMetaStorage(AcfMetaStorage::class)]で宣言するエンティティ。acf-bridgeの管理キーの申告と衝突しない。
 * 同じグループのsubtitleは宣言せず、生成アクセサだけで扱う。
 */
#[AsPostType]
#[UseMetaStorage(AcfMetaStorage::class)]
final class AcfStoredPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_acf_post')
            ->label('ACF保存テスト', 'ACF保存テスト')
            ->supports('title');
    }
}
