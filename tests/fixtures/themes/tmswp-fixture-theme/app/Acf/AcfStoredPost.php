<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Acf;

use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
#[UseMetaStorage(AcfMetaStorage::class)]
final class AcfStoredPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_acf_post')
            ->label('ACF保存テスト')
            ->supports('title');
    }
}
