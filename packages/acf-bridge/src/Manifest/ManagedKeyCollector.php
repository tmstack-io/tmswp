<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Manifest;

use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Generate\TypeMapper;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Mapping\LocationResolver;
use TMSWP\AcfBridge\Mapping\TargetKind;
use TMSWP\Core\Meta\ObjectType;

/**
 * フィールドグループからACFの管理キー(生成物AcfManifestが返すもの。§7.1)を集める。`wp tmswp acf check`のE101の
 * 検査がこれを使う。
 */
final class ManagedKeyCollector
{
    public function __construct(
        private readonly LocationResolver $locations = new LocationResolver(),
        private readonly TypeMapper $types = new TypeMapper(),
    ) {}

    /**
     * 有効なグループの、locationで解決したターゲットごとのトップレベルのフィールドを返す。cloneは解決済みの
     * グループを渡す(seamlessのcloneが展開したフィールドも管理キーになる)。並びはAcfManifestの行と同じ
     * (ObjectTypeのcase名の順でoptionsは最後、次にサブタイプ、メタキー)。
     *
     * 同じ(ObjectType, サブタイプ, メタキー)は1つにする。テンプレート別のクラスと投稿タイプのクラス、別のテンプレートの
     * クラスが同じ名前のフィールドを持つと、同じ投稿のメタの同じキーになるため。残すのは、グループのkey・field keyの
     * 昇順で最初のもの。
     *
     * @param list<FieldGroup> $groups CloneResolverでcloneを解決したグループ
     *
     * @return list<ManagedKey>
     */
    public function collect(array $groups, BridgeConfig $config): array
    {
        $keys = [];

        foreach ($groups as $group) {
            if (!$group->active) {
                continue;
            }

            foreach ($this->locations->resolve($group, $config) as $target) {
                $objectType = match ($target->kind) {
                    TargetKind::Post => ObjectType::Post,
                    TargetKind::Term => ObjectType::Term,
                    TargetKind::User => ObjectType::User,
                    TargetKind::Options => null,
                };

                foreach ($group->fields as $field) {
                    if (!$this->types->storesValue($field) || $field->name === '' || $field->type === 'clone') {
                        continue;
                    }

                    $keys[] = new ManagedKey($objectType, $target->subtype, $field->name, $field->key, $group->key);
                }
            }
        }

        usort($keys, static fn(ManagedKey $a, ManagedKey $b): int => [self::sortKey($a), $a->groupKey, $a->fieldKey] <=> [self::sortKey($b), $b->groupKey, $b->fieldKey]);
        $unique = [];

        foreach ($keys as $key) {
            $unique[self::sortKey($key)] ??= $key;
        }

        return array_values($unique);
    }

    private static function sortKey(ManagedKey $key): string
    {
        $type = $key->objectType instanceof ObjectType ? 'ObjectType::' . $key->objectType->name : 'options';

        return $type . "\0" . $key->objectSubtype . "\0" . $key->metaKey;
    }
}
