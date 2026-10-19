<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\User;

use TMSWP\Core\Attribute\AsUserRole;
use TMSWP\Core\User\UserRole;
use TMSWP\Core\User\UserRoleDefinition;

#[AsUserRole]
final class MemberRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('tmswp_member')
            ->label('会員')
            ->capabilities('read', 'tmswp_member');
    }
}
