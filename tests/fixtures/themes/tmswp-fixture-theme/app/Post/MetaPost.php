<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\WithMetaRevisions;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
#[WithMetaRevisions]
final class MetaPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 1200, showInRest: true, description: '価格', sanitize: [self::class, 'sanitizePrice'])]
    public const META_PRICE = 'tmswp_price';

    #[MetaSchema(type: MetaValueType::String, single: false, showInRest: true)]
    public const META_CODES = 'tmswp_codes';

    public static function sanitizePrice(mixed $value): int
    {
        return (int) $value;
    }

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_meta_post')
            ->label('メタ確認')
            ->showInRest()
            ->supports('title', 'revisions');
    }
}
