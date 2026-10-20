<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use TMSWP\Core\Exception\InvalidArgumentException;

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
        self::assertStorable($key, $value);
        $metaType = $type->metaType();

        // update_metadata() はキーと値を wp_unslash() するため、slash して渡して元の値を保存させる。
        // false(同じ値・フィルタによる中止)とデータベースのエラーの区別は WpDatabaseGuard に従う
        return WpDatabaseGuard::write(
            static fn(): mixed => update_metadata($metaType, $objectId, wp_slash($key), self::slash($value)),
            "failed to update {$metaType} meta '{$key}' for object {$objectId}",
        );
    }

    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        foreach ($values as $value) {
            self::assertStorable($key, $value);
        }

        $metaType = $type->metaType();
        $before = self::storedForms(WpDatabaseGuard::read(
            fn(): array => $this->getAll($type, $objectId, $key),
            "failed to read {$metaType} meta '{$key}' for object {$objectId} before replacing it",
        ));

        // データベースのエラーは途中でも例外にする。wpdb にトランザクションは無いため巻き戻さない。
        // フィルタが削除を止めた場合は、行を足すと置き換えにならないため、何も変えずに終える
        if ($before !== [] && !WpDatabaseGuard::write(
            static fn(): mixed => delete_metadata($metaType, $objectId, wp_slash($key)),
            "failed to delete {$metaType} meta '{$key}' for object {$objectId} before replacing it",
        )) {
            return false;
        }

        foreach ($values as $value) {
            WpDatabaseGuard::write(
                static fn(): mixed => add_metadata($metaType, $objectId, wp_slash($key), self::slash($value)),
                "failed to add {$metaType} meta '{$key}' for object {$objectId}",
            );
        }

        $after = WpDatabaseGuard::read(
            fn(): array => $this->getAll($type, $objectId, $key),
            "failed to read {$metaType} meta '{$key}' for object {$objectId} after replacing it",
        );

        return self::storedForms($after) !== $before;
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        $metaType = $type->metaType();
        $exists = WpDatabaseGuard::read(
            fn(): bool => $this->has($type, $objectId, $key),
            "failed to read {$metaType} meta '{$key}' for object {$objectId} before deleting it",
        );

        if (!$exists) {
            return false;
        }

        return WpDatabaseGuard::write(
            static fn(): mixed => delete_metadata($metaType, $objectId, wp_slash($key)),
            "failed to delete {$metaType} meta '{$key}' for object {$objectId}",
        );
    }

    /**
     * null と object を拒否する。WP のメタは null を保存できず(空文字になる)、object は wp_slash() の対象外のまま
     * update_metadata() の wp_unslash() でプロパティの文字列が書き換えられるため、元の値として往復しない。
     */
    private static function assertStorable(string $key, mixed $value): void
    {
        if ($value === null) {
            throw new InvalidArgumentException("meta '{$key}' cannot store null; use delete() to remove it");
        }

        self::assertNoObject($key, $value);
    }

    private static function assertNoObject(string $key, mixed $value): void
    {
        if (\is_object($value)) {
            throw new InvalidArgumentException("meta '{$key}' cannot store an object (" . $value::class . ')');
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                self::assertNoObject($key, $item);
            }
        }
    }

    private static function slash(mixed $value): mixed
    {
        return \is_string($value) || \is_array($value) ? wp_slash($value) : $value;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function storedForms(array $values): array
    {
        return array_map(self::storedForm(...), $values);
    }

    /** DB の meta_value 列に書かれる文字列の形(配列は serialize、真偽は '1' と '')。 */
    private static function storedForm(mixed $value): string
    {
        return ValueCaster::toString(maybe_serialize($value));
    }
}
