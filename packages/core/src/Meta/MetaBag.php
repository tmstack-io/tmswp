<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use TMSWP\Core\Exception\InvalidArgumentException;

/**
 * @template TEntity of object
 */
final class MetaBag
{
    /**
     * @param class-string<TEntity> $entityClass
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly ObjectType $objectType,
        private readonly int $objectId,
        private readonly MetaStorageInterface $storage,
        private readonly MetaSchemaSet $schemas,
    ) {}

    /** @return class-string<TEntity> */
    public function entityClass(): string
    {
        return $this->entityClass;
    }

    public function objectType(): ObjectType
    {
        return $this->objectType;
    }

    public function objectId(): int
    {
        return $this->objectId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $schema = $this->schemas->get($key);

        if ($schema instanceof MetaSchemaDefinition && !$schema->single) {
            if ($this->storage->has($this->objectType, $this->objectId, $key)) {
                return $this->storage->getAll($this->objectType, $this->objectId, $key);
            }

            return $this->defaultValue($schema, $default);
        }

        return $this->singleValue($key, $schema, $default);
    }

    public function int(string $key, ?int $default = null): int
    {
        return ValueCaster::toInt($this->singleValue($key, $this->schemas->get($key), $default));
    }

    public function float(string $key, ?float $default = null): float
    {
        return ValueCaster::toFloat($this->singleValue($key, $this->schemas->get($key), $default));
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        return ValueCaster::toBool($this->singleValue($key, $this->schemas->get($key), $default));
    }

    public function string(string $key, ?string $default = null): string
    {
        return ValueCaster::toString($this->singleValue($key, $this->schemas->get($key), $default));
    }

    /**
     * @param list<mixed>|null $default
     *
     * @return list<mixed>
     */
    public function list(string $key, ?array $default = null): array
    {
        $schema = $this->schemas->get($key);

        if ($schema instanceof MetaSchemaDefinition && !$schema->single) {
            if ($this->storage->has($this->objectType, $this->objectId, $key)) {
                return $this->storage->getAll($this->objectType, $this->objectId, $key);
            }

            if ($default !== null) {
                return array_values($default);
            }

            return [];
        }

        return ValueCaster::toList($this->singleValue($key, $schema, $default));
    }

    /**
     * @param array<array-key, mixed>|null $default
     *
     * @return array<array-key, mixed>
     */
    public function json(string $key, ?array $default = null): array
    {
        return ValueCaster::toJsonArray($this->singleValue($key, $this->schemas->get($key), $default));
    }

    public function has(string $key): bool
    {
        return $this->storage->has($this->objectType, $this->objectId, $key);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $values = $this->storage->getMultiple($this->objectType, $this->objectId, $this->schemas->keys());
        $all = [];

        foreach ($this->schemas->all() as $key => $schema) {
            if (\array_key_exists($key, $values)) {
                $all[$key] = $values[$key];
                continue;
            }

            $all[$key] = $schema->hasDefault() ? $schema->default : null;
        }

        return $all;
    }

    public function set(string $key, mixed $value): bool
    {
        $schema = $this->schemas->get($key);

        if ($schema instanceof MetaSchemaDefinition && !$schema->single) {
            if (!\is_array($value) || !array_is_list($value)) {
                throw new InvalidArgumentException("single=false meta '{$key}' must be set with a list");
            }

            return $this->storage->setAll($this->objectType, $this->objectId, $key, $value);
        }

        return $this->storage->set($this->objectType, $this->objectId, $key, $value);
    }

    public function delete(string $key): bool
    {
        return $this->storage->delete($this->objectType, $this->objectId, $key);
    }

    private function singleValue(string $key, ?MetaSchemaDefinition $schema, mixed $default = null): mixed
    {
        if ($this->storage->has($this->objectType, $this->objectId, $key)) {
            return $this->storage->get($this->objectType, $this->objectId, $key);
        }

        return $this->defaultValue($schema, $default);
    }

    private function defaultValue(?MetaSchemaDefinition $schema, mixed $default): mixed
    {
        if ($default !== null) {
            return $default;
        }

        if ($schema instanceof MetaSchemaDefinition && $schema->hasDefault()) {
            return $schema->default;
        }

        return null;
    }
}
