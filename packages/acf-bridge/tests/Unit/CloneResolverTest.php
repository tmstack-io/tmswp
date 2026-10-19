<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use TMSWP\AcfBridge\Generate\CloneResolver;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class CloneResolverTest extends UnitTestCase
{
    public function testItExpandsSeamlessCloneGroups(): void
    {
        $errors = [];
        $groups = (new CloneResolver())->resolve([
            $this->group('group_common', [
                ['key' => 'field_headline', 'name' => 'headline', 'label' => '見出し', 'type' => 'text'],
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number'],
            ]),
            $this->group('group_product', [
                [
                    'key' => 'field_common_clone',
                    'name' => 'common',
                    'label' => '共通',
                    'type' => 'clone',
                    'clone' => ['group_common'],
                    'display' => 'seamless',
                ],
            ]),
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame(['headline', 'price'], $this->fieldNames($groups[1]));
    }

    public function testItPrefixesSeamlessCloneNamesWhenRequested(): void
    {
        $errors = [];
        $groups = (new CloneResolver())->resolve([
            $this->group('group_common', [
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number'],
            ]),
            $this->group('group_product', [
                [
                    'key' => 'field_common_clone',
                    'name' => 'common',
                    'label' => '共通',
                    'type' => 'clone',
                    'clone' => ['group_common'],
                    'display' => 'seamless',
                    'prefix_name' => 1,
                ],
            ]),
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame(['common_price'], $this->fieldNames($groups[1]));
    }

    public function testItConvertsGroupDisplayCloneToGroupField(): void
    {
        $errors = [];
        $groups = (new CloneResolver())->resolve([
            $this->group('group_common', [
                ['key' => 'field_price', 'name' => 'price', 'label' => '価格', 'type' => 'number'],
            ]),
            $this->group('group_product', [
                [
                    'key' => 'field_common_clone',
                    'name' => 'common',
                    'label' => '共通',
                    'type' => 'clone',
                    'clone' => ['group_common'],
                    'display' => 'group',
                ],
            ]),
        ], $errors);

        self::assertSame([], $errors);
        self::assertSame('common', $groups[1]->fields[0]->name);
        self::assertSame('group', $groups[1]->fields[0]->type);
        self::assertSame(['price'], $this->subFieldNames($groups[1]->fields[0]));
    }

    public function testItReportsMissingCloneReferences(): void
    {
        $errors = [];

        (new CloneResolver())->resolve([
            $this->group('group_product', [
                [
                    'key' => 'field_missing_clone',
                    'name' => 'missing',
                    'label' => '不足',
                    'type' => 'clone',
                    'clone' => ['field_missing'],
                    'display' => 'seamless',
                ],
            ]),
        ], $errors);

        self::assertSame(["E004: clone field 'missing' in group 'group_product' references missing field 'field_missing'"], $errors);
    }

    public function testItReportsCircularCloneReferences(): void
    {
        $errors = [];

        (new CloneResolver())->resolve([
            $this->group('group_product', [
                [
                    'key' => 'field_a',
                    'name' => 'a',
                    'label' => 'A',
                    'type' => 'clone',
                    'clone' => ['field_b'],
                    'display' => 'seamless',
                ],
                [
                    'key' => 'field_b',
                    'name' => 'b',
                    'label' => 'B',
                    'type' => 'clone',
                    'clone' => ['field_a'],
                    'display' => 'seamless',
                ],
            ]),
        ], $errors);

        self::assertContains("E005: clone field 'a' in group 'group_product' has a circular reference", $errors);
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function group(string $key, array $fields): FieldGroup
    {
        return FieldGroup::fromArray([
            'key' => $key,
            'title' => $key,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]],
            'fields' => $fields,
        ], $key . '.json');
    }

    /** @return list<string> */
    private function fieldNames(FieldGroup $group): array
    {
        return array_map(static fn($field): string => $field->name, $group->fields);
    }

    /** @return list<string> */
    private function subFieldNames(\TMSWP\AcfBridge\Json\FieldDefinition $field): array
    {
        return array_map(static fn($subField): string => $subField->name, $field->subFields);
    }
}
