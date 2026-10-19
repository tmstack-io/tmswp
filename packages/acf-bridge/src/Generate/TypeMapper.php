<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

use TMSWP\AcfBridge\Json\FieldDefinition;

final class TypeMapper
{
    public function map(FieldDefinition $field): TypeMapping
    {
        $returnFormat = $field->returnFormat ?? $this->defaultReturnFormat($field);

        return match ($field->type) {
            'text', 'textarea', 'email', 'url', 'password', 'wysiwyg', 'oembed', 'date_picker', 'date_time_picker', 'time_picker' => $this->stringType($field),
            'number', 'range' => new TypeMapping('int|float|null', 'int|float|null', 'acfNumber', 'int|float|null'),
            'true_false' => new TypeMapping('bool', 'bool', 'acfBool', 'bool'),
            'select', 'checkbox', 'radio', 'button_group' => $this->choiceType($field, $returnFormat),
            'image' => $this->mediaType($returnFormat, 'AcfImage', 'acfImage'),
            'file' => $this->mediaType($returnFormat, 'AcfFile', 'acfFile'),
            'gallery' => $this->galleryType($returnFormat),
            'link' => $returnFormat === 'url'
                ? new TypeMapping('?string', 'string|null', 'acfNullableString', '?array', 'AcfLink|null')
                : new TypeMapping('?array', 'AcfLink|null', 'acfLink', '?array', 'AcfLink|null'),
            'post_object' => $field->multiple ? $this->listObjectType($returnFormat, '\\WP_Post') : $this->singleObjectType($returnFormat, '\\WP_Post'),
            'page_link' => $field->multiple
                ? new TypeMapping('array', 'list<string>', 'acfStringList', 'array', 'list<int|string>')
                : new TypeMapping('?string', 'string|null', 'acfNullableString', 'int|string|null'),
            'relationship' => $returnFormat === 'id'
                ? new TypeMapping('array', 'list<int>', 'acfIntList', 'array', 'list<int>')
                : new TypeMapping('array', 'list<\\WP_Post>', 'acfList', 'array', 'list<int>'),
            'taxonomy' => $this->taxonomyType($field, $returnFormat),
            'user' => $field->multiple ? $this->listObjectType($returnFormat, '\\WP_User', 'AcfUserArray') : $this->singleObjectType($returnFormat, '\\WP_User', 'AcfUserArray'),
            'color_picker' => $returnFormat === 'array'
                ? new TypeMapping('?array', 'AcfColorRgba|null', 'acfColorRgba', 'string')
                : new TypeMapping('string', 'string', 'acfString', 'string'),
            'group' => new TypeMapping('?array', $this->shape($field) . '|null', 'acfNullableArray', '?array', 'array<string, mixed>|null'),
            'repeater' => new TypeMapping('array', 'list<' . $this->shape($field) . '>', 'acfList', 'array', 'list<array<string, mixed>>'),
            'flexible_content' => new TypeMapping('array', 'list<' . $this->flexibleUnion($field) . '>', 'acfList', 'array', 'list<array<string, mixed>>'),
            default => new TypeMapping('mixed', 'mixed', 'value', 'mixed'),
        };
    }

    public function storesValue(FieldDefinition $field): bool
    {
        return !\in_array($field->type, ['message', 'tab', 'accordion'], true);
    }

    private function stringType(FieldDefinition $field): TypeMapping
    {
        if ($field->type === 'date_picker') {
            return new TypeMapping('string', 'string', 'acfString', 'string|\\DateTimeInterface|null', '', true, 'Ymd');
        }

        if ($field->type === 'date_time_picker') {
            return new TypeMapping('string', 'string', 'acfString', 'string|\\DateTimeInterface|null', '', true, 'Y-m-d H:i:s');
        }

        if ($field->type === 'time_picker') {
            return new TypeMapping('string', 'string', 'acfString', 'string|\\DateTimeInterface|null', '', false, 'H:i:s');
        }

        return new TypeMapping('string', 'string', 'acfString', 'string');
    }

    private function choiceType(FieldDefinition $field, string $returnFormat): TypeMapping
    {
        $isMultiple = $field->multiple || $field->type === 'checkbox';

        if ($isMultiple) {
            return $returnFormat === 'array'
                ? new TypeMapping('array', 'list<AcfChoice>', 'acfChoiceList', 'array', 'list<string>')
                : new TypeMapping('array', 'list<string>', 'acfStringList', 'array', 'list<string>');
        }

        return $returnFormat === 'array'
            ? new TypeMapping('?array', 'AcfChoice|null', 'acfChoice', '?string')
            : new TypeMapping('?string', 'string|null', 'acfNullableString', '?string');
    }

    private function mediaType(string $returnFormat, string $docType, string $arrayHelper): TypeMapping
    {
        return match ($returnFormat) {
            'id' => new TypeMapping('?int', 'int|null', 'acfInt', '?int'),
            'url' => new TypeMapping('?string', 'string|null', 'acfNullableString', '?int'),
            default => new TypeMapping('?array', "{$docType}|null", $arrayHelper, '?int'),
        };
    }

    private function galleryType(string $returnFormat): TypeMapping
    {
        return match ($returnFormat) {
            'id' => new TypeMapping('array', 'list<int>', 'acfIntList', 'array', 'list<int>'),
            'url' => new TypeMapping('array', 'list<string>', 'acfStringList', 'array', 'list<int>'),
            default => new TypeMapping('array', 'list<AcfImage>', 'acfImageList', 'array', 'list<int>'),
        };
    }

    private function singleObjectType(string $returnFormat, string $objectClass, string $arrayDoc = ''): TypeMapping
    {
        if ($returnFormat === 'id') {
            return new TypeMapping('?int', 'int|null', 'acfInt', '?int');
        }

        if ($returnFormat === 'array' && $arrayDoc !== '') {
            return new TypeMapping('?array', "{$arrayDoc}|null", 'acfUserArray', '?int');
        }

        return new TypeMapping('?object', "{$objectClass}|null", 'value', '?int');
    }

    private function listObjectType(string $returnFormat, string $objectClass, string $arrayDoc = ''): TypeMapping
    {
        if ($returnFormat === 'id') {
            return new TypeMapping('array', 'list<int>', 'acfIntList', 'array', 'list<int>');
        }

        if ($returnFormat === 'array' && $arrayDoc !== '') {
            return new TypeMapping('array', "list<{$arrayDoc}>", 'acfUserArrayList', 'array', 'list<int>');
        }

        return new TypeMapping('array', "list<{$objectClass}>", 'acfList', 'array', 'list<int>');
    }

    private function taxonomyType(FieldDefinition $field, string $returnFormat): TypeMapping
    {
        $isMultiple = \in_array($field->fieldType, ['checkbox', 'multi_select'], true);

        if ($isMultiple) {
            return $returnFormat === 'object'
                ? new TypeMapping('array', 'list<\\WP_Term>', 'acfList', 'array', 'list<int>')
                : new TypeMapping('array', 'list<int>', 'acfIntList', 'array', 'list<int>');
        }

        return $returnFormat === 'object'
            ? new TypeMapping('?object', '\\WP_Term|null', 'value', '?int')
            : new TypeMapping('?int', 'int|null', 'acfInt', '?int');
    }

    private function shape(FieldDefinition $field): string
    {
        $parts = [];

        foreach ($field->subFields as $subField) {
            if (!$this->storesValue($subField) || !NameConverter::isAccessorEligible($subField->name)) {
                continue;
            }

            $parts[] = $subField->name . ': ' . $this->map($subField)->getterDocType;
        }

        return 'array{' . implode(', ', $parts) . '}';
    }

    private function flexibleUnion(FieldDefinition $field): string
    {
        if ($field->layouts === []) {
            return 'array{acf_fc_layout: string}';
        }

        $shapes = [];

        foreach ($field->layouts as $layoutName => $layoutFields) {
            $parts = ["acf_fc_layout: '" . str_replace("'", "\\'", $layoutName) . "'"];

            foreach ($layoutFields as $subField) {
                if (!$this->storesValue($subField) || !NameConverter::isAccessorEligible($subField->name)) {
                    continue;
                }

                $parts[] = $subField->name . ': ' . $this->map($subField)->getterDocType;
            }

            $shapes[] = 'array{' . implode(', ', $parts) . '}';
        }

        return implode('|', $shapes);
    }

    private function defaultReturnFormat(FieldDefinition $field): string
    {
        return match ($field->type) {
            'image', 'file', 'link', 'user' => 'array',
            'post_object', 'relationship' => 'object',
            'taxonomy' => 'id',
            'color_picker', 'select', 'checkbox', 'radio', 'button_group' => 'value',
            default => '',
        };
    }
}
