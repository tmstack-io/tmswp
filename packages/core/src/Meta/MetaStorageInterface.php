<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

interface MetaStorageInterface
{
    public function has(ObjectType $type, int $objectId, string $key): bool;

    public function get(ObjectType $type, int $objectId, string $key): mixed;

    /** @return list<mixed> */
    public function getAll(ObjectType $type, int $objectId, string $key): array;

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(ObjectType $type, int $objectId, array $keys): array;

    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool;

    /** @param list<mixed> $values */
    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool;

    public function delete(ObjectType $type, int $objectId, string $key): bool;
}
