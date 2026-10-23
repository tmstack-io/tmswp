<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Spy;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Core\Tests\Support\RecordedComponent;

/**
 * Initializer の呼び出しの記録用。初期化は RecordingInitializer が記録してから PostTypeInitializer に委ねる。
 */
#[RecordedComponent(AsPostType::class)]
final class SpiedPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_spied')
            ->label('Spied', 'Spied');
    }
}
