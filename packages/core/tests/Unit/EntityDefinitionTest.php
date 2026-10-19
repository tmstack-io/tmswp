<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\User\UserRoleDefinition;

final class EntityDefinitionTest extends UnitTestCase
{
    public function testPostTypeDefinitionBuildsArgsWithGeneratedLabels(): void
    {
        $args = PostTypeDefinition::create('product')
            ->label('製品')
            ->labels(['menu_name' => '製品管理'])
            ->hasArchive()
            ->supports('title', 'thumbnail')
            ->taxonomies(DefinitionTaxonomy::class)
            ->toArgs();

        $labels = $args['labels'];
        self::assertIsArray($labels);

        self::assertTrue($args['public']);
        self::assertSame('製品', $args['label']);
        self::assertSame('Add New 製品', $labels['add_new_item']);
        self::assertSame('製品管理', $labels['menu_name']);
        self::assertTrue($args['has_archive']);
        self::assertSame(['title', 'thumbnail'], $args['supports']);
        self::assertSame(['product_category'], $args['taxonomies']);
    }

    public function testPostTypeDefinitionCanGenerateJapaneseLabelsWhenExplicit(): void
    {
        $args = PostTypeDefinition::create('case')
            ->label('事例')
            ->labelLanguage(LabelLanguage::Ja)
            ->toArgs();

        $labels = $args['labels'];
        self::assertIsArray($labels);

        self::assertSame('新規事例を追加', $labels['add_new_item']);
        self::assertSame('事例が見つかりませんでした', $labels['not_found']);
    }

    public function testDefinitionRejectsInvalidSlugAndBuiltinSetterMisuse(): void
    {
        $this->expectException(EntityDefinitionException::class);

        PostTypeDefinition::create('Invalid Slug!');
    }

    public function testBuiltinPostTypeRejectsUnsupportedSetter(): void
    {
        $this->expectException(EntityDefinitionException::class);

        PostTypeDefinition::builtin('page')->label('固定ページ');
    }

    public function testTaxonomyDefinitionResolvesPostTypeClassReferences(): void
    {
        $args = TaxonomyDefinition::create('product_category')
            ->label('製品カテゴリ')
            ->objectType(DefinitionPostType::class, DefinitionPostType::class)
            ->hierarchical()
            ->toArgs();

        self::assertTrue($args['hierarchical']);
        self::assertSame(['product'], TaxonomyDefinition::create('product_category')->objectType(DefinitionPostType::class, DefinitionPostType::class)->resolvedObjectTypes());
    }

    public function testUserRoleDefinitionDeduplicatesCapabilities(): void
    {
        $definition = UserRoleDefinition::create('member')
            ->label('会員')
            ->capabilities('read', 'read', 'upload_files');

        self::assertSame('member', $definition->role());
        self::assertSame('会員', $definition->roleLabel());
        self::assertSame(['read', 'upload_files'], $definition->capabilityList());
    }
}

final class DefinitionPostType extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')->label('製品');
    }
}

final class DefinitionTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('product_category')->label('製品カテゴリ');
    }
}
