<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Spy;

use TMSWP\Core\Attribute\AsUserRole;
use TMSWP\Core\Tests\Support\RecordedComponent;
use TMSWP\Core\User\UserRole;
use TMSWP\Core\User\UserRoleDefinition;

/**
 * Initializer の呼び出しの記録用。初期化は RecordingInitializer が記録してから UserRoleInitializer に委ねる。
 */
#[RecordedComponent(AsUserRole::class)]
final class SpiedRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('tmswp_spied')
            ->label('Spied')
            ->capabilities('read');
    }
}
