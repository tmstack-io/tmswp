<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TmswpFixtureTheme\Storage\ArrayMetaStorage;

#[AsPostType]
#[UseMetaStorage(ArrayMetaStorage::class)]
final class StoredPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_CUSTOM = 'tmswp_custom';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_stored_post')
            ->label('保存先差し替え', '保存先差し替え')
            ->supports('title');
    }
}
