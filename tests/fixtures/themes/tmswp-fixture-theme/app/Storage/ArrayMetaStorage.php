<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Storage;

use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;

/**
 * #[UseMetaStorage] による保存先の差し替えを確認するための、プロセス内配列への保存実装。
 */
final class ArrayMetaStorage implements MetaStorageInterface
{
    /** @var array<string, mixed> */
    private static array $values = [];

    public function has(ObjectType $type, int $objectId, string $key): bool
    {
        return \array_key_exists($this->storageKey($type, $objectId, $key), self::$values);
    }

    public function get(ObjectType $type, int $objectId, string $key): mixed
    {
        return self::$values[$this->storageKey($type, $objectId, $key)] ?? null;
    }

    public function getAll(ObjectType $type, int $objectId, string $key): array
    {
        $value = $this->get($type, $objectId, $key);

        return \is_array($value) && array_is_list($value) ? $value : [];
    }

    public function getMultiple(ObjectType $type, int $objectId, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            if ($this->has($type, $objectId, $key)) {
                $values[$key] = $this->get($type, $objectId, $key);
            }
        }

        return $values;
    }

    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool
    {
        self::$values[$this->storageKey($type, $objectId, $key)] = $value;

        return true;
    }

    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        return $this->set($type, $objectId, $key, $values);
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        unset(self::$values[$this->storageKey($type, $objectId, $key)]);

        return true;
    }

    private function storageKey(ObjectType $type, int $objectId, string $key): string
    {
        return $type->value . ':' . $objectId . ':' . $key;
    }
}
