<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Generate\CloneResolver;
use TMSWP\AcfBridge\Json\AcfJsonRepository;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\AcfBridge\Manifest\ManagedKey;
use TMSWP\AcfBridge\Manifest\ManagedKeyCollector;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Tests\Support\UnitTestCase;

/**
 * 管理キーの収集(`wp tmswp acf check`のE101の入力)。
 */
final class ManagedKeyCollectorTest extends UnitTestCase
{
    /**
     * fixtureのacf-jsonから集めた管理キーは、Generatorの期待する生成物(expected-appのAcfManifest。GeneratorTestが
     * 生成結果とバイト単位で照合する)の行と同じキーを同じ順に並べる。GeneratorのAcfManifestとcheckのE101は、
     * どちらもこの収集を使う。
     */
    public function testItCollectsTheKeysOfTheExpectedManifestInTheSameOrder(): void
    {
        $groups = (new AcfJsonRepository([__DIR__ . '/../fixtures/acf-json']))->load()->fieldGroups;
        $config = new BridgeConfig([], '/tmp/acf', 'App\Acf', methodNameMap: ['group_product.店舗名' => 'storeName']);
        $errors = [];

        $keys = (new ManagedKeyCollector())->collect((new CloneResolver())->resolve($groups, $errors), $config);
        $manifest = file_get_contents(__DIR__ . '/../fixtures/expected-app/Acf/AcfManifest.php');
        self::assertIsString($manifest);

        self::assertSame([], $errors);
        $rows = self::rows($keys);
        self::assertCount(74, $rows);

        foreach ([
            ['post', 'product', 'main_visual', 'field_visual', 'group_product'],
            ['post', 'product', '店舗名', 'field_store_name', 'group_product'],
            ['post', 'product', 'map_value', 'field_t_map_value', 'group_product_types'],
            ['post', 'product', 'sections', 'field_t_sections', 'group_product_types'],
            ['term', 'genre', 'genre_color', 'field_genre_color', 'group_genre'],
            ['user', '', 'twitter', 'field_user_twitter', 'group_user'],
            [null, 'theme-settings', 'site_logo', 'field_logo', 'group_options'],
        ] as $row) {
            self::assertContains($row, $rows);
        }

        foreach (['message_note', 'tab_basic', 'accordion_more', 'title'] as $name) {
            self::assertNotContains($name, array_column($rows, 2), $name);
        }

        self::assertSame(self::manifestRows($keys), self::manifestLinesOf($manifest));
    }

    /**
     * seamlessのcloneが展開したフィールドも管理キーになり、cloneフィールド自体・無効なグループ・値を持たない型は
     * 管理キーにしない。
     */
    public function testItCollectsFieldsExpandedFromSeamlessClone(): void
    {
        $groups = [
            FieldGroup::fromArray([
                'key' => 'group_source',
                'title' => 'Source',
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'source']]],
                'fields' => [['key' => 'field_note', 'name' => 'note', 'label' => 'Note', 'type' => 'text']],
            ], 'group_source.json'),
            FieldGroup::fromArray([
                'key' => 'group_target',
                'title' => 'Target',
                'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'all']]],
                'fields' => [
                    ['key' => 'field_clone', 'name' => 'cloned', 'label' => 'Cloned', 'type' => 'clone', 'clone' => ['field_note'], 'display' => 'seamless'],
                    ['key' => 'field_tab', 'name' => '', 'label' => 'Tab', 'type' => 'tab'],
                ],
            ], 'group_target.json'),
            FieldGroup::fromArray([
                'key' => 'group_inactive',
                'title' => 'Inactive',
                'active' => false,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'source']]],
                'fields' => [['key' => 'field_inactive', 'name' => 'inactive', 'label' => 'Inactive', 'type' => 'text']],
            ], 'group_inactive.json'),
        ];
        $errors = [];

        $keys = (new ManagedKeyCollector())->collect((new CloneResolver())->resolve($groups, $errors), new BridgeConfig([], '/tmp/acf', 'App\Acf'));

        self::assertSame([], $errors);
        self::assertSame([
            ['post', 'source', 'note', 'field_note', 'group_source'],
            ['term', 'all', 'note', 'field_note', 'group_target'],
        ], self::rows($keys));
    }

    /**
     * 同じ(ObjectType, サブタイプ, メタキー)の管理キーは1つにする。テンプレート別のクラス・その投稿タイプのクラス・
     * 別のテンプレートのクラスの同じ名前のフィールドは、同じ投稿のメタの同じキーだからである。残すのは、グループの
     * key・field keyの昇順で最初のもの。1つのテンプレートを使う投稿タイプごとの管理キーは、それぞれ出す。
     */
    public function testKeysOfTheSameMetaAreCollectedOnce(): void
    {
        $groups = [
            self::subtitleGroup('group_b_contact', [['page_template', 'templates/contact.php']], 'field_contact_subtitle'),
            self::subtitleGroup('group_a_about', [['page_template', 'templates/about.php']], 'field_about_subtitle'),
            self::subtitleGroup('group_c_page', [['post_type', 'page']], 'field_page_subtitle'),
            self::subtitleGroup('group_d_product', [['post_type', 'product'], ['post_template', 'templates/about.php']], 'field_about_subtitle'),
        ];

        self::assertSame([
            ['post', 'page', 'subtitle', 'field_about_subtitle', 'group_a_about'],
            ['post', 'product', 'subtitle', 'field_about_subtitle', 'group_d_product'],
        ], self::rows((new ManagedKeyCollector())->collect($groups, new BridgeConfig([], '/tmp/acf', 'App\Acf'))));
    }

    /**
     * locationが1つのANDグループ$conditions([param, value]の`==`)で、textのフィールドsubtitle(field keyは$fieldKey)を持つグループ。
     *
     * @param list<array{string, string}> $conditions
     */
    private static function subtitleGroup(string $key, array $conditions, string $fieldKey): FieldGroup
    {
        return FieldGroup::fromArray([
            'key' => $key,
            'title' => $key,
            'location' => [array_map(static fn(array $condition): array => ['param' => $condition[0], 'operator' => '==', 'value' => $condition[1]], $conditions)],
            'fields' => [['key' => $fieldKey, 'name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text']],
        ], "{$key}.json");
    }

    /**
     * @param list<ManagedKey> $keys
     *
     * @return list<array{?string, string, string, string, string}>
     */
    private static function rows(array $keys): array
    {
        return array_map(static fn(ManagedKey $key): array => [$key->objectType?->value, $key->objectSubtype, $key->metaKey, $key->fieldKey, $key->groupKey], $keys);
    }

    /**
     * @param list<ManagedKey> $keys
     *
     * @return list<string>
     */
    private static function manifestRows(array $keys): array
    {
        return array_map(
            static fn(ManagedKey $key): string => 'new ManagedKey(' . ($key->objectType instanceof ObjectType ? 'ObjectType::' . $key->objectType->name : 'null')
                . ", '{$key->objectSubtype}', '{$key->metaKey}', '{$key->fieldKey}', '{$key->groupKey}'),",
            $keys,
        );
    }

    /** @return list<string> */
    private static function manifestLinesOf(string $manifest): array
    {
        self::assertGreaterThan(0, preg_match_all('/^\s+(new ManagedKey\(.*\),)$/m', $manifest, $matches), 'AcfManifestにManagedKeyの行がありません');

        return $matches[1];
    }
}
