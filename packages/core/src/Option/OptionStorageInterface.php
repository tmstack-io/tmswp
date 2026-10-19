<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

interface OptionStorageInterface
{
    public function has(string $name): bool;

    public function get(string $name): mixed;

    public function set(string $name, mixed $value, ?bool $autoload = null): bool;

    public function delete(string $name): bool;
}
