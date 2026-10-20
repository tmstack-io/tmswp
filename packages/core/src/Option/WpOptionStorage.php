<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use stdClass;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Meta\WpDatabaseGuard;

final class WpOptionStorage implements OptionStorageInterface
{
    public function has(string $name): bool
    {
        $sentinel = new stdClass();

        return get_option($name, $sentinel) !== $sentinel;
    }

    public function get(string $name): mixed
    {
        $sentinel = new stdClass();
        $value = get_option($name, $sentinel);

        return $value === $sentinel ? null : $value;
    }

    public function set(string $name, mixed $value, ?bool $autoload = null): bool
    {
        if ($value === null) {
            throw new InvalidArgumentException("option '{$name}' cannot store null; use delete() to remove it");
        }

        // false(同じ値・フィルタによる中止)とデータベースのエラーの区別は WpDatabaseGuard に従う
        $exists = WpDatabaseGuard::read(
            fn(): bool => $this->has($name),
            "failed to read option '{$name}' before writing it",
        );

        if (!$exists) {
            return WpDatabaseGuard::write(
                static fn(): mixed => add_option($name, $value, '', $autoload),
                "failed to add option '{$name}'",
            );
        }

        return WpDatabaseGuard::write(
            static fn(): mixed => update_option($name, $value, $autoload),
            "failed to update option '{$name}'",
        );
    }

    public function delete(string $name): bool
    {
        $exists = WpDatabaseGuard::read(
            fn(): bool => $this->has($name),
            "failed to read option '{$name}' before deleting it",
        );

        if (!$exists) {
            return false;
        }

        return WpDatabaseGuard::write(
            static fn(): mixed => delete_option($name),
            "failed to delete option '{$name}'",
        );
    }
}
