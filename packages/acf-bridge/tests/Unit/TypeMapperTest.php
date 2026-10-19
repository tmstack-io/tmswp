<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\AcfBridge\Generate\TypeMapper;
use TMSWP\AcfBridge\Generate\TypeMapping;
use TMSWP\AcfBridge\Json\FieldDefinition;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class TypeMapperTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $field
     */
    #[DataProvider('mappingProvider')]
    public function testItMapsDocumentedFieldRows(
        array $field,
        string $getterNativeType,
        string $getterDocType,
        string $coercionHelper,
        string $setterNativeType,
        string $setterDocType = '',
        bool $dateTimeAccessor = false,
        string $dateRawFormat = '',
    ): void {
        $this->assertMapping(
            new TypeMapping($getterNativeType, $getterDocType, $coercionHelper, $setterNativeType, $setterDocType, $dateTimeAccessor, $dateRawFormat),
            (new TypeMapper())->map($this->field($field)),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string, string, string, string, 5?: string, 6?: bool, 7?: string}> */
    public static function mappingProvider(): iterable
    {
        foreach (['text', 'textarea', 'email', 'url', 'password', 'wysiwyg', 'oembed'] as $type) {
            yield $type => [['type' => $type], 'string', 'string', 'acfString', 'string'];
        }

        yield 'number' => [['type' => 'number'], 'int|float|null', 'int|float|null', 'acfNumber', 'int|float|null'];
        yield 'range' => [['type' => 'range'], 'int|float|null', 'int|float|null', 'acfNumber', 'int|float|null'];
        yield 'true_false' => [['type' => 'true_false'], 'bool', 'bool', 'acfBool', 'bool'];
        yield 'select value' => [['type' => 'select', 'return_format' => 'value'], '?string', 'string|null', 'acfNullableString', '?string'];
        yield 'select array' => [['type' => 'select', 'return_format' => 'array'], '?array', 'AcfChoice|null', 'acfChoice', '?string'];
        yield 'select multiple value' => [['type' => 'select', 'multiple' => true, 'return_format' => 'value'], 'array', 'list<string>', 'acfStringList', 'array', 'list<string>'];
        yield 'select multiple array' => [['type' => 'select', 'multiple' => true, 'return_format' => 'array'], 'array', 'list<AcfChoice>', 'acfChoiceList', 'array', 'list<string>'];
        yield 'checkbox value' => [['type' => 'checkbox', 'return_format' => 'value'], 'array', 'list<string>', 'acfStringList', 'array', 'list<string>'];
        yield 'checkbox array' => [['type' => 'checkbox', 'return_format' => 'array'], 'array', 'list<AcfChoice>', 'acfChoiceList', 'array', 'list<string>'];
        yield 'radio value' => [['type' => 'radio', 'return_format' => 'value'], '?string', 'string|null', 'acfNullableString', '?string'];
        yield 'radio array' => [['type' => 'radio', 'return_format' => 'array'], '?array', 'AcfChoice|null', 'acfChoice', '?string'];
        yield 'button_group value' => [['type' => 'button_group', 'return_format' => 'value'], '?string', 'string|null', 'acfNullableString', '?string'];
        yield 'button_group array' => [['type' => 'button_group', 'return_format' => 'array'], '?array', 'AcfChoice|null', 'acfChoice', '?string'];
        yield 'image array' => [['type' => 'image', 'return_format' => 'array'], '?array', 'AcfImage|null', 'acfImage', '?int'];
        yield 'image id' => [['type' => 'image', 'return_format' => 'id'], '?int', 'int|null', 'acfInt', '?int'];
        yield 'image url' => [['type' => 'image', 'return_format' => 'url'], '?string', 'string|null', 'acfNullableString', '?int'];
        yield 'file array' => [['type' => 'file', 'return_format' => 'array'], '?array', 'AcfFile|null', 'acfFile', '?int'];
        yield 'file id' => [['type' => 'file', 'return_format' => 'id'], '?int', 'int|null', 'acfInt', '?int'];
        yield 'file url' => [['type' => 'file', 'return_format' => 'url'], '?string', 'string|null', 'acfNullableString', '?int'];
        yield 'gallery array' => [['type' => 'gallery', 'return_format' => 'array'], 'array', 'list<AcfImage>', 'acfImageList', 'array', 'list<int>'];
        yield 'gallery id' => [['type' => 'gallery', 'return_format' => 'id'], 'array', 'list<int>', 'acfIntList', 'array', 'list<int>'];
        yield 'gallery url' => [['type' => 'gallery', 'return_format' => 'url'], 'array', 'list<string>', 'acfStringList', 'array', 'list<int>'];
        yield 'link array' => [['type' => 'link', 'return_format' => 'array'], '?array', 'AcfLink|null', 'acfLink', '?array', 'AcfLink|null'];
        yield 'link url' => [['type' => 'link', 'return_format' => 'url'], '?string', 'string|null', 'acfNullableString', '?array', 'AcfLink|null'];
        yield 'post_object object' => [['type' => 'post_object', 'return_format' => 'object'], '?object', '\\WP_Post|null', 'value', '?int'];
        yield 'post_object id' => [['type' => 'post_object', 'return_format' => 'id'], '?int', 'int|null', 'acfInt', '?int'];
        yield 'post_object multiple object' => [['type' => 'post_object', 'multiple' => true, 'return_format' => 'object'], 'array', 'list<\\WP_Post>', 'acfList', 'array', 'list<int>'];
        yield 'post_object multiple id' => [['type' => 'post_object', 'multiple' => true, 'return_format' => 'id'], 'array', 'list<int>', 'acfIntList', 'array', 'list<int>'];
        yield 'page_link single' => [['type' => 'page_link'], '?string', 'string|null', 'acfNullableString', 'int|string|null'];
        yield 'page_link multiple' => [['type' => 'page_link', 'multiple' => true], 'array', 'list<string>', 'acfStringList', 'array', 'list<int|string>'];
        yield 'relationship object' => [['type' => 'relationship', 'return_format' => 'object'], 'array', 'list<\\WP_Post>', 'acfList', 'array', 'list<int>'];
        yield 'relationship id' => [['type' => 'relationship', 'return_format' => 'id'], 'array', 'list<int>', 'acfIntList', 'array', 'list<int>'];
        yield 'taxonomy object single' => [['type' => 'taxonomy', 'field_type' => 'select', 'return_format' => 'object'], '?object', '\\WP_Term|null', 'value', '?int'];
        yield 'taxonomy id single' => [['type' => 'taxonomy', 'field_type' => 'radio', 'return_format' => 'id'], '?int', 'int|null', 'acfInt', '?int'];
        yield 'taxonomy object multiple' => [['type' => 'taxonomy', 'field_type' => 'checkbox', 'return_format' => 'object'], 'array', 'list<\\WP_Term>', 'acfList', 'array', 'list<int>'];
        yield 'taxonomy id multiple' => [['type' => 'taxonomy', 'field_type' => 'multi_select', 'return_format' => 'id'], 'array', 'list<int>', 'acfIntList', 'array', 'list<int>'];
        yield 'user array' => [['type' => 'user', 'return_format' => 'array'], '?array', 'AcfUserArray|null', 'acfUserArray', '?int'];
        yield 'user object' => [['type' => 'user', 'return_format' => 'object'], '?object', '\\WP_User|null', 'value', '?int'];
        yield 'user id' => [['type' => 'user', 'return_format' => 'id'], '?int', 'int|null', 'acfInt', '?int'];
        yield 'user multiple object' => [['type' => 'user', 'multiple' => true, 'return_format' => 'object'], 'array', 'list<\\WP_User>', 'acfList', 'array', 'list<int>'];
        yield 'date_picker' => [['type' => 'date_picker'], 'string', 'string', 'acfString', 'string|\\DateTimeInterface|null', '', true, 'Ymd'];
        yield 'date_time_picker' => [['type' => 'date_time_picker'], 'string', 'string', 'acfString', 'string|\\DateTimeInterface|null', '', true, 'Y-m-d H:i:s'];
        yield 'time_picker' => [['type' => 'time_picker'], 'string', 'string', 'acfString', 'string|\\DateTimeInterface|null', '', false, 'H:i:s'];
        yield 'color string' => [['type' => 'color_picker', 'return_format' => 'string'], 'string', 'string', 'acfString', 'string'];
        yield 'color array' => [['type' => 'color_picker', 'return_format' => 'array'], '?array', 'AcfColorRgba|null', 'acfColorRgba', 'string'];
    }

    public function testItMapsRepresentativeAcfTypes(): void
    {
        $mapper = new TypeMapper();

        $this->assertMapping(
            new TypeMapping('?int', 'int|null', 'acfInt', '?int'),
            $mapper->map($this->field(['type' => 'image', 'return_format' => 'id'])),
        );
        $this->assertMapping(
            new TypeMapping('?string', 'string|null', 'acfNullableString', 'int|string|null'),
            $mapper->map($this->field(['type' => 'page_link'])),
        );
        $this->assertMapping(
            new TypeMapping('array', 'list<array{shop_name: string, tel: string}>', 'acfList', 'array', 'list<array<string, mixed>>'),
            $mapper->map($this->field([
                'type' => 'repeater',
                'sub_fields' => [
                    ['key' => 'field_shop', 'name' => 'shop_name', 'label' => '店舗名', 'type' => 'text'],
                    ['key' => 'field_tel', 'name' => 'tel', 'label' => 'TEL', 'type' => 'text'],
                ],
            ])),
        );
        $this->assertMapping(
            new TypeMapping(
                'array',
                "list<array{acf_fc_layout: 'body', body: string}|array{acf_fc_layout: 'hero', title: string, image: int|null}>",
                'acfList',
                'array',
                'list<array<string, mixed>>',
            ),
            $mapper->map($this->field([
                'type' => 'flexible_content',
                'layouts' => [
                    [
                        'name' => 'hero',
                        'sub_fields' => [
                            ['key' => 'field_title', 'name' => 'title', 'label' => '見出し', 'type' => 'text'],
                            ['key' => 'field_image', 'name' => 'image', 'label' => '画像', 'type' => 'image', 'return_format' => 'id'],
                        ],
                    ],
                    [
                        'name' => 'body',
                        'sub_fields' => [
                            ['key' => 'field_body', 'name' => 'body', 'label' => '本文', 'type' => 'wysiwyg'],
                        ],
                    ],
                ],
            ])),
        );
    }

    public function testUnknownTypesBecomeMixed(): void
    {
        $mapping = (new TypeMapper())->map($this->field(['type' => 'google_map']));

        self::assertSame('mixed', $mapping->getterNativeType);
        self::assertSame('mixed', $mapping->getterDocType);
        self::assertSame('value', $mapping->coercionHelper);
    }

    /** @param array<string, mixed> $overrides */
    private function field(array $overrides): FieldDefinition
    {
        return FieldDefinition::fromArray(array_merge([
            'key' => 'field_test',
            'name' => 'test_field',
            'label' => 'テスト',
            'type' => 'text',
        ], $overrides));
    }

    private function assertMapping(TypeMapping $expected, TypeMapping $actual): void
    {
        self::assertSame($expected->getterNativeType, $actual->getterNativeType);
        self::assertSame($expected->getterDocType, $actual->getterDocType);
        self::assertSame($expected->coercionHelper, $actual->coercionHelper);
        self::assertSame($expected->setterNativeType, $actual->setterNativeType);
        self::assertSame($expected->setterDocType, $actual->setterDocType);
        self::assertSame($expected->dateTimeAccessor, $actual->dateTimeAccessor);
        self::assertSame($expected->dateRawFormat, $actual->dateRawFormat);
    }
}
