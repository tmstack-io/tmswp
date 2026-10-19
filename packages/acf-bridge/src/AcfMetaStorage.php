<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

use TMSWP\AcfBridge\Exception\AcfNotActiveException;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;

final class AcfMetaStorage implements MetaStorageInterface
{
    public function has(ObjectType $type, int $objectId, string $key): bool
    {
        $this->assertAcfActive(__METHOD__);

        return get_field($key, $this->acfId($type, $objectId), false) !== null;
    }

    public function get(ObjectType $type, int $objectId, string $key): mixed
    {
        $this->assertAcfActive(__METHOD__);

        return get_field($key, $this->acfId($type, $objectId), false);
    }

    public function getAll(ObjectType $type, int $objectId, string $key): array
    {
        $value = $this->get($type, $objectId, $key);

        return \is_array($value) && array_is_list($value) ? $value : ($value === null ? [] : [$value]);
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
        $this->assertAcfActive(__METHOD__);

        return update_field($key, $value, $this->acfId($type, $objectId)) !== false;
    }

    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        return $this->set($type, $objectId, $key, $values);
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        $this->assertAcfActive(__METHOD__);

        return delete_field($key, $this->acfId($type, $objectId));
    }

    private function acfId(ObjectType $type, int $objectId): int|string
    {
        return match ($type) {
            ObjectType::Post => $objectId,
            ObjectType::Term => "term_{$objectId}",
            ObjectType::User => "user_{$objectId}",
        };
    }

    private function assertAcfActive(string $callable): void
    {
        if (!\function_exists('get_field')) {
            throw AcfNotActiveException::forCall($callable);
        }
    }
}
