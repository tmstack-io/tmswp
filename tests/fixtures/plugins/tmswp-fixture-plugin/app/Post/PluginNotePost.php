<?php

declare(strict_types=1);

namespace TmswpFixturePlugin\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

/**
 * 統合テスト用: プラグインのKernelがallowedBlocks()で使えるブロックを限る投稿タイプ。テーマのKernelの
 * ProductPostの指定と互いに上書きしないことを確かめる。
 */
#[AsPostType]
final class PluginNotePost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_plugin_note')
            ->label('Plugin note', 'Plugin notes')
            ->showInRest()
            ->allowedBlocks('core/heading');
    }
}
