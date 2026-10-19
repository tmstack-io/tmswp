<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

final class WpMetaStorage implements MetaStorageInterface
{
    public function has(ObjectType $type, int $objectId, string $key): bool
    {
        return metadata_exists($type->metaType(), $objectId, $key);
    }

    public function get(ObjectType $type, int $objectId, string $key): mixed
    {
        return get_metadata_raw($type->metaType(), $objectId, $key, true);
    }

    public function getAll(ObjectType $type, int $objectId, string $key): array
    {
        $values = get_metadata_raw($type->metaType(), $objectId, $key, false);

        if (!\is_array($values)) {
            return [];
        }

        return array_values($values);
    }

    public function getMultiple(ObjectType $type, int $objectId, array $keys): array
    {
        $all = get_metadata($type->metaType(), $objectId);

        if (!\is_array($all)) {
            return [];
        }

        $values = [];

        foreach ($keys as $key) {
            if (!\array_key_exists($key, $all) || !\is_array($all[$key]) || !\array_key_exists(0, $all[$key])) {
                continue;
            }

            $raw = $all[$key][0];
            $values[$key] = \is_string($raw) ? maybe_unserialize($raw) : $raw;
        }

        return $values;
    }

    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool
    {
        return update_metadata($type->metaType(), $objectId, $key, $value) !== false;
    }

    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        delete_metadata($type->metaType(), $objectId, $key);

        foreach ($values as $value) {
            if (add_metadata($type->metaType(), $objectId, $key, $value) === false) {
                return false;
            }
        }

        return true;
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        if (!$this->has($type, $objectId, $key)) {
            return true;
        }

        return delete_metadata($type->metaType(), $objectId, $key);
    }
}
