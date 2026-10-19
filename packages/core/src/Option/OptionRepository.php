<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use TMSWP\Core\Meta\ValueCaster;

final class OptionRepository
{
    /**
     * @param class-string $groupClass
     */
    public function __construct(
        private readonly string $groupClass,
        private readonly string $prefix,
        private readonly OptionSchemaSet $schemas,
        private readonly OptionStorageInterface $storage,
    ) {}

    /** @return class-string */
    public function groupClass(): string
    {
        return $this->groupClass;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function name(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->value($key, $default);
    }

    public function int(string $key, ?int $default = null): int
    {
        return ValueCaster::toInt($this->value($key, $default));
    }

    public function float(string $key, ?float $default = null): float
    {
        return ValueCaster::toFloat($this->value($key, $default));
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        return ValueCaster::toBool($this->value($key, $default));
    }

    public function string(string $key, ?string $default = null): string
    {
        return ValueCaster::toString($this->value($key, $default));
    }

    /**
     * @param list<mixed>|null $default
     *
     * @return list<mixed>
     */
    public function list(string $key, ?array $default = null): array
    {
        return ValueCaster::toList($this->value($key, $default));
    }

    /**
     * @param array<array-key, mixed>|null $default
     *
     * @return array<array-key, mixed>
     */
    public function json(string $key, ?array $default = null): array
    {
        return ValueCaster::toJsonArray($this->value($key, $default));
    }

    public function has(string $key): bool
    {
        return $this->storage->has($this->name($key));
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $values = [];

        foreach ($this->schemas->all() as $key => $schema) {
            $values[$key] = $this->value($key, $schema->hasDefault() ? $schema->default : null);
        }

        return $values;
    }

    public function set(string $key, mixed $value): bool
    {
        $schema = $this->schemas->get($key);

        return $this->storage->set($this->name($key), $value, $schema instanceof OptionSchemaDefinition ? $schema->autoload : null);
    }

    public function delete(string $key): bool
    {
        return $this->storage->delete($this->name($key));
    }

    /** @return list<string> */
    public function deleteAll(): array
    {
        $deleted = [];

        foreach ($this->schemas->keys() as $key) {
            $name = $this->name($key);

            if ($this->storage->delete($name)) {
                $deleted[] = $name;
            }
        }

        return $deleted;
    }

    private function value(string $key, mixed $default): mixed
    {
        $name = $this->name($key);

        if ($this->storage->has($name)) {
            return $this->storage->get($name);
        }

        if ($default !== null) {
            return $default;
        }

        $schema = $this->schemas->get($key);

        if ($schema instanceof OptionSchemaDefinition && $schema->hasDefault()) {
            return $schema->default;
        }

        return null;
    }
}
