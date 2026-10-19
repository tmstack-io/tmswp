<?php

declare(strict_types=1);

namespace TMSWP\Core\User;

use TMSWP\Core\Entity\WpBridge;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\EntityPersistenceException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaBag;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use WP_Error;
use WP_Role;
use WP_User;

abstract class UserRole
{
    /** @var array<class-string<self>, UserRoleDefinition> */
    private static array $definitions = [];

    abstract public static function definition(): UserRoleDefinition;

    final public static function role(): string
    {
        return self::definitionFor(static::class)->role();
    }

    final public static function register(): void
    {
        $definition = self::definitionFor(static::class);
        $role = get_role($definition->role());

        if ($definition->isBuiltin() && !$role instanceof WP_Role) {
            throw new EntityDefinitionException("builtin role does not exist: {$definition->role()}");
        }

        if (!$definition->isBuiltin() && !$role instanceof WP_Role) {
            $role = add_role($definition->role(), $definition->roleLabel());

            if (!$role instanceof WP_Role) {
                throw new EntityDefinitionException("failed to add user role: {$definition->role()}");
            }
        }

        if (!$role instanceof WP_Role) {
            throw new EntityDefinitionException("user role is unavailable: {$definition->role()}");
        }

        foreach ($definition->capabilityList() as $capability) {
            if (!$role->has_cap($capability)) {
                $role->add_cap($capability);
            }
        }
    }

    final public static function get(WP_User|int $user): ?WP_User
    {
        if ($user instanceof WP_User) {
            return \in_array(static::role(), (array) $user->roles, true) ? $user : null;
        }

        if ($user <= 0) {
            return null;
        }

        $resolved = get_user_by('id', $user);

        if (!$resolved instanceof WP_User || !\in_array(static::role(), (array) $resolved->roles, true)) {
            return null;
        }

        return $resolved;
    }

    final public static function findByEmail(string $email): ?WP_User
    {
        $user = get_user_by('email', $email);

        return $user instanceof WP_User ? static::get($user) : null;
    }

    final public static function findByLogin(string $login): ?WP_User
    {
        $user = get_user_by('login', $login);

        return $user instanceof WP_User ? static::get($user) : null;
    }

    final public static function current(): ?WP_User
    {
        if (!is_user_logged_in()) {
            return null;
        }

        return static::get(wp_get_current_user());
    }

    final public static function is(WP_User|int|null $user = null): bool
    {
        if ($user === null) {
            return static::current() instanceof WP_User;
        }

        return static::get($user) instanceof WP_User;
    }

    public static function query(): UserQuery
    {
        return UserQuery::of(static::class);
    }

    /** @return MetaBag<static> */
    final public static function meta(WP_User|int $user): MetaBag
    {
        $resolved = static::get($user);

        if (!$resolved instanceof WP_User) {
            throw new EntityDefinitionException('cannot create MetaBag for missing or mismatched user');
        }

        $registry = Kernel::forClass(static::class)->container()->get(MetaSchemaRegistry::class);

        if (!$registry instanceof MetaSchemaRegistry) {
            throw new KernelException('container entry MetaSchemaRegistry must resolve to MetaSchemaRegistry');
        }

        return $registry->bagFor(static::class, $resolved);
    }

    final public static function authorUrl(WP_User|int $user): ?string
    {
        $resolved = static::get($user);

        if (!$resolved instanceof WP_User) {
            return null;
        }

        $url = get_author_posts_url((int) $resolved->ID);

        return $url === '' ? null : $url;
    }

    /** @param array<string, mixed> $userdata */
    final public static function create(string $login, string $password, array $userdata = []): int
    {
        $userdata['user_login'] = $login;
        $userdata['user_pass'] = $password;
        $userdata['role'] = static::role();
        unset($userdata['ID']);
        $id = WpBridge::call('wp_insert_user', $userdata);

        if ($id instanceof WP_Error) {
            throw EntityPersistenceException::fromWpError($id);
        }

        if (!\is_int($id)) {
            throw new EntityPersistenceException('wp_insert_user did not return an integer ID');
        }

        return $id;
    }

    final public static function resetRuntimeCache(): void
    {
        unset(self::$definitions[static::class]);
    }

    /** @param class-string<self> $class */
    private static function definitionFor(string $class): UserRoleDefinition
    {
        if (!\array_key_exists($class, self::$definitions)) {
            self::$definitions[$class] = $class::definition();
        }

        return self::$definitions[$class];
    }
}
