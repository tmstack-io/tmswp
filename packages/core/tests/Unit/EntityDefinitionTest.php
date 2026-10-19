<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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
            ->label('Product', 'Products')
            ->labels(['menu_name' => 'Product Catalog'])
            ->hasArchive()
            ->supports('title', 'thumbnail')
            ->taxonomies(DefinitionTaxonomy::class)
            ->toArgs();

        $labels = $args['labels'];
        self::assertIsArray($labels);

        self::assertTrue($args['public']);
        self::assertSame('Products', $args['label']);
        self::assertSame('Product', $labels['singular_name']);
        self::assertSame('Add Product', $labels['add_new_item']);
        self::assertSame('All Products', $labels['all_items']);
        self::assertSame('Product Catalog', $labels['menu_name']);
        // 非階層の投稿タイプでは、コアと同じく parent_item_colon が null
        self::assertArrayHasKey('parent_item_colon', $labels);
        self::assertNull($labels['parent_item_colon']);
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

        self::assertSame('事例', $args['label']);
        self::assertSame('事例', $labels['singular_name']);
        self::assertSame('新規事例を追加', $labels['add_new_item']);
        self::assertSame('事例が見つかりませんでした', $labels['not_found']);
    }

    public function testEnglishPostTypeLabelWithoutPluralIsRejectedAtArgsBuild(): void
    {
        $definition = PostTypeDefinition::create('book')->label('Book');

        $this->expectException(EntityDefinitionException::class);
        $this->expectExceptionMessage("post type 'book' requires a plural label for LabelLanguage::En: pass it as label('Book', '<plural>')");

        $definition->toArgs();
    }

    public function testEnglishTaxonomyLabelWithoutPluralIsRejectedAtArgsBuild(): void
    {
        $definition = TaxonomyDefinition::create('genre')->label('Genre');

        $this->expectException(EntityDefinitionException::class);
        $this->expectExceptionMessage("taxonomy 'genre' requires a plural label for LabelLanguage::En: pass it as label('Genre', '<plural>')");

        $definition->toArgs();
    }

    /** @return iterable<string, array{PostTypeDefinition|TaxonomyDefinition, string}> */
    public static function definitionsWithEmptyPlural(): iterable
    {
        foreach (LabelLanguage::cases() as $language) {
            yield "post type {$language->value}" => [PostTypeDefinition::create('person')->label('Person', '')->labelLanguage($language), "post type 'person'"];
            yield "taxonomy {$language->value}" => [TaxonomyDefinition::create('person_tag')->label('Person', '')->labelLanguage($language), "taxonomy 'person_tag'"];
        }
    }

    #[DataProvider('definitionsWithEmptyPlural')]
    public function testEmptyPluralLabelIsRejectedRegardlessOfLanguage(PostTypeDefinition|TaxonomyDefinition $definition, string $subject): void
    {
        $this->expectException(EntityDefinitionException::class);
        $this->expectExceptionMessage("{$subject} got an empty plural label: pass a non-empty plural, or omit the second argument of label() (null) to omit it");

        $definition->toArgs();
    }

    /** @return iterable<string, array{PostTypeDefinition|TaxonomyDefinition, string}> */
    public static function definitionsWithEmptySingular(): iterable
    {
        yield 'post type' => [PostTypeDefinition::create('book')->label('', 'Books'), "post type 'book'"];
        yield 'post type with labels name' => [PostTypeDefinition::create('book')->label('', 'Books')->labels(['name' => 'X']), "post type 'book'"];
        yield 'taxonomy' => [TaxonomyDefinition::create('genre')->label('', 'Books'), "taxonomy 'genre'"];
        yield 'taxonomy with labels name' => [TaxonomyDefinition::create('genre')->label('', 'Books')->labels(['name' => 'X']), "taxonomy 'genre'"];
    }

    #[DataProvider('definitionsWithEmptySingular')]
    public function testEmptySingularLabelIsRejectedEvenWithLabelsName(PostTypeDefinition|TaxonomyDefinition $definition, string $subject): void
    {
        $this->expectException(EntityDefinitionException::class);
        $this->expectExceptionMessage("{$subject} got an empty singular label: pass a non-empty singular to label()");

        $definition->toArgs();
    }

    public function testJapaneseTaxonomyLabelUsesSingularWhenPluralIsOmitted(): void
    {
        $args = TaxonomyDefinition::create('genre')
            ->label('ジャンル')
            ->labelLanguage(LabelLanguage::Ja)
            ->labels(['back_to_items' => '← ジャンルへ'])
            ->toArgs();

        $labels = $args['labels'];
        self::assertIsArray($labels);

        self::assertSame('ジャンル', $args['label']);
        self::assertSame('ジャンル一覧', $labels['all_items']);
        self::assertSame('ジャンルを編集', $labels['edit_item']);
        self::assertSame('← ジャンルへ', $labels['back_to_items']);
        // 非階層のタクソノミーでは、コアと同じく親に関するキーが null
        self::assertNull($labels['parent_item']);
        self::assertSame('よく使われているジャンル', $labels['popular_items']);
    }

    public function testLabelsWithNameAloneAreUsedWithoutGeneration(): void
    {
        $args = PostTypeDefinition::create('book')
            ->labels(['name' => 'Books', 'singular_name' => 'Book'])
            ->toArgs();

        self::assertSame('Books', $args['label']);
        self::assertSame(['name' => 'Books', 'singular_name' => 'Book'], $args['labels']);
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
            ->label('製品カテゴリ', '製品カテゴリ')
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
        return PostTypeDefinition::create('product')->label('製品', '製品');
    }
}

final class DefinitionTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('product_category')->label('製品カテゴリ', '製品カテゴリ');
    }
}
