<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use stdClass;

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

        return $value === $sentinel ? null : $this->decode($value);
    }

    public function set(string $name, mixed $value, ?bool $autoload = null): bool
    {
        $value = $this->encode($value);

        if (!$this->has($name)) {
            if ($autoload === null) {
                return add_option($name, $value);
            }

            return add_option($name, $value, '', $autoload);
        }

        return update_option($name, $value, $autoload);
    }

    public function delete(string $name): bool
    {
        if (!$this->has($name)) {
            return true;
        }

        return delete_option($name);
    }

    private function encode(mixed $value): mixed
    {
        return $value === null ? new WpOptionNullValue() : $value;
    }

    private function decode(mixed $value): mixed
    {
        return $value instanceof WpOptionNullValue ? null : $value;
    }
}

/**
 * @internal
 */
final class WpOptionNullValue {}
