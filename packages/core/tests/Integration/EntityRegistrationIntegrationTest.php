<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Label\LabelGenerator;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tests\Support\IntegrationTestCase;
use WP_Post_Type;
use WP_Role;
use WP_Taxonomy;

final class EntityRegistrationIntegrationTest extends IntegrationTestCase
{
    public function testPostTypeIsRegisteredOnInit(): void
    {
        self::assertTrue(post_type_exists('tmswp_product'));
        $postType = get_post_type_object('tmswp_product');
        self::assertInstanceOf(WP_Post_Type::class, $postType);
        self::assertTrue($postType->has_archive);
        self::assertTrue($postType->show_in_rest);
        self::assertTrue(post_type_supports('tmswp_product', 'thumbnail'));
    }

    public function testTaxonomyIsRegisteredForPostTypeOnInit(): void
    {
        self::assertTrue(taxonomy_exists('tmswp_product_cat'));
        $taxonomy = get_taxonomy('tmswp_product_cat');
        self::assertInstanceOf(WP_Taxonomy::class, $taxonomy);
        self::assertTrue($taxonomy->hierarchical);
        self::assertTrue($taxonomy->show_in_rest);
        self::assertContains('tmswp_product', $taxonomy->object_type);
    }

    /**
     * WordPress の読み込み(1リクエスト)で、fixture のテーマのタクソノミーはすべて init 9 に、投稿タイプはすべて
     * init 10 に、タクソノミーを先にしてそれぞれ1回ずつ登録される(tests/fixtures/_boot.php の記録で確かめる)。
     */
    public function testTaxonomiesAreRegisteredOnInit9BeforePostTypesOnInit10(): void
    {
        $taxonomies = [];
        $postTypes = [];

        foreach (self::themeKernel()->components() as $component) {
            if ($component->attributeClass === AsTaxonomy::class) {
                $taxonomies[] = self::fixtureClass($component->class, Taxonomy::class)::slug();
            } elseif ($component->attributeClass === AsPostType::class) {
                $postTypes[] = self::fixtureClass($component->class, PostType::class)::slug();
            }
        }

        // 空どうしの比較にしない
        self::assertContains('tmswp_product_cat', $taxonomies);
        self::assertContains('tmswp_product_tag', $taxonomies);
        self::assertContains('tmswp_product', $postTypes);

        // 同じ priority の中は components() の順(FQCN の昇順)に登録される
        $expected = [
            ...array_map(static fn(string $name): array => ['kind' => 'taxonomy', 'name' => $name, 'initPriority' => 9], $taxonomies),
            ...array_map(static fn(string $name): array => ['kind' => 'post_type', 'name' => $name, 'initPriority' => 10], $postTypes),
        ];
        $fixtureRegistrations = array_values(array_filter(
            self::loadingRegistrations(),
            static fn(array $registration): bool => \in_array($registration['name'], $registration['kind'] === 'taxonomy' ? $taxonomies : $postTypes, true),
        ));

        self::assertSame($expected, $fixtureRegistrations);
    }

    /**
     * ProductTagTaxonomy は objectType() を持たず、ProductPost の PostTypeDefinition::taxonomies() だけで関連づく。
     * register_post_type() の taxonomies は、その時点で登録済みのタクソノミーだけを関連づけるため、タクソノミーが
     * 先(init 9)に登録されていることが前提になる。
     */
    public function testPostTypeDefinitionTaxonomiesLinksTaxonomyToPostType(): void
    {
        self::assertContains('tmswp_product_tag', get_object_taxonomies('tmswp_product'));
        $taxonomy = get_taxonomy('tmswp_product_tag');
        self::assertInstanceOf(WP_Taxonomy::class, $taxonomy);
        self::assertSame(['tmswp_product'], $taxonomy->object_type);
    }

    /** 生成するラベルのキーが、実物の WordPress が組み立てるラベルのキーと過不足なく一致する */
    public function testGeneratedLabelKeysMatchTheLabelsBuiltByCore(): void
    {
        $postType = get_post_type_object('tmswp_product');
        self::assertInstanceOf(WP_Post_Type::class, $postType);
        $generated = array_keys(LabelGenerator::forPostType('Item', 'Items', LabelLanguage::En, $postType->hierarchical));
        self::assertEqualsCanonicalizing($generated, array_keys(get_object_vars($postType->labels)));

        $taxonomy = get_taxonomy('tmswp_product_cat');
        self::assertInstanceOf(WP_Taxonomy::class, $taxonomy);
        $generated = array_keys(LabelGenerator::forTaxonomy('Item', 'Items', LabelLanguage::En, $taxonomy->hierarchical));
        self::assertEqualsCanonicalizing($generated, array_keys(get_object_vars($taxonomy->labels)));
    }

    public function testUserRoleIsRegisteredOnInit(): void
    {
        $role = get_role('tmswp_member');
        self::assertInstanceOf(WP_Role::class, $role);
        self::assertTrue($role->has_cap('read'));
        self::assertTrue($role->has_cap('tmswp_member'));
    }

    /**
     * tests/fixtures/_boot.php が控えた、WordPress の読み込み中の投稿タイプ・タクソノミーの登録を返す。
     *
     * @return list<array{kind: string, name: string, initPriority: mixed}>
     */
    private static function loadingRegistrations(): array
    {
        $log = $GLOBALS['tmswp_tests_registrations'] ?? null;
        self::assertIsArray($log, 'tests/fixtures/_boot.php did not record the registrations');
        $registrations = [];

        foreach ($log as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['kind'] ?? null);
            self::assertIsString($entry['name'] ?? null);
            $registrations[] = ['kind' => $entry['kind'], 'name' => $entry['name'], 'initPriority' => $entry['initPriority'] ?? null];
        }

        return $registrations;
    }
}
