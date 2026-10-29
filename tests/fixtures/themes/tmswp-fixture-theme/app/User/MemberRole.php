<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\User;

use TMSWP\Core\Attribute\AsUserRole;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\User\UserRole;
use TMSWP\Core\User\UserRoleDefinition;

#[AsUserRole]
final class MemberRole extends UserRole
{
    #[MetaSchema(type: MetaValueType::Integer, default: 0)]
    public const META_MEMBER_NO = 'tmswp_member_no';

    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('tmswp_member')
            ->label('会員')
            ->capabilities('read', 'tmswp_member');
    }
}
