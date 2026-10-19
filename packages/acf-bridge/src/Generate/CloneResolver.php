<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

use TMSWP\AcfBridge\Json\FieldDefinition;
use TMSWP\AcfBridge\Json\FieldGroup;

final class CloneResolver
{
    /** @var array<string, FieldGroup> */
    private array $groups = [];

    /** @var array<string, FieldDefinition> */
    private array $fields = [];

    /**
     * @param list<FieldGroup> $groups
     * @param list<string> $errors
     *
     * @return list<FieldGroup>
     */
    public function resolve(array $groups, array &$errors): array
    {
        $this->index($groups);
        $resolvedGroups = [];

        foreach ($groups as $group) {
            $resolvedGroups[] = new FieldGroup(
                key: $group->key,
                title: $group->title,
                fields: $this->resolveFields($group->fields, $group->key, [$group->key], $errors),
                location: $group->location,
                active: $group->active,
                sourceFile: $group->sourceFile,
                raw: $group->raw,
            );
        }

        return $resolvedGroups;
    }

    /** @param list<FieldGroup> $groups */
    private function index(array $groups): void
    {
        $this->groups = [];
        $this->fields = [];

        foreach ($groups as $group) {
            $this->groups[$group->key] = $group;

            foreach ($group->fields as $field) {
                $this->indexField($field);
            }
        }
    }

    private function indexField(FieldDefinition $field): void
    {
        if ($field->key !== '') {
            $this->fields[$field->key] = $field;
        }

        foreach ($field->subFields as $subField) {
            $this->indexField($subField);
        }

        foreach ($field->layouts as $layoutFields) {
            foreach ($layoutFields as $layoutField) {
                $this->indexField($layoutField);
            }
        }
    }

    /**
     * @param list<FieldDefinition> $fields
     * @param list<string> $stack
     * @param list<string> $errors
     *
     * @return list<FieldDefinition>
     */
    private function resolveFields(array $fields, string $groupKey, array $stack, array &$errors): array
    {
        $resolved = [];

        foreach ($fields as $field) {
            foreach ($this->resolveField($field, $groupKey, $stack, $errors) as $resolvedField) {
                $resolved[] = $resolvedField;
            }
        }

        return $resolved;
    }

    /**
     * @param list<string> $stack
     * @param list<string> $errors
     *
     * @return list<FieldDefinition>
     */
    private function resolveField(FieldDefinition $field, string $groupKey, array $stack, array &$errors): array
    {
        if ($field->type === 'clone') {
            return $this->resolveClone($field, $groupKey, $stack, $errors);
        }

        $layouts = [];

        foreach ($field->layouts as $layoutName => $layoutFields) {
            $layouts[$layoutName] = $this->resolveFields($layoutFields, $groupKey, [...$stack, $field->key, $layoutName], $errors);
        }

        return [
            $this->fieldWith(
                $field,
                $field->name,
                $field->type,
                $this->resolveFields($field->subFields, $groupKey, [...$stack, $field->key], $errors),
                $layouts,
            ),
        ];
    }

    /**
     * @param list<string> $stack
     * @param list<string> $errors
     *
     * @return list<FieldDefinition>
     */
    private function resolveClone(FieldDefinition $field, string $groupKey, array $stack, array &$errors): array
    {
        if (\in_array($field->key, $stack, true)) {
            $errors[] = "E005: clone field '{$field->name}' in group '{$groupKey}' has a circular reference";

            return [];
        }

        $refs = $this->cloneRefs($field, $groupKey, $errors);

        if ($refs === []) {
            return [];
        }

        $resolvedRefs = $this->resolveReferences($refs, $field, $groupKey, [...$stack, $field->key], $errors);
        $display = $this->stringRaw($field, 'display');

        if ($display === 'seamless') {
            return $this->prefixed($resolvedRefs, $field);
        }

        if ($display === 'group') {
            return [
                $this->fieldWith($field, $field->name, 'group', $resolvedRefs, []),
            ];
        }

        $errors[] = "E004: clone field '{$field->name}' in group '{$groupKey}' has unsupported display '{$display}'";

        return [];
    }

    /**
     * @param list<string> $refs
     * @param list<string> $stack
     * @param list<string> $errors
     *
     * @return list<FieldDefinition>
     */
    private function resolveReferences(array $refs, FieldDefinition $field, string $groupKey, array $stack, array &$errors): array
    {
        $resolved = [];

        foreach ($refs as $ref) {
            if (\in_array($ref, $stack, true)) {
                $errors[] = "E005: clone field '{$field->name}' in group '{$groupKey}' has a circular reference";
                continue;
            }

            if (str_starts_with($ref, 'group_')) {
                $group = $this->groups[$ref] ?? null;

                if (!$group instanceof FieldGroup) {
                    $errors[] = "E004: clone field '{$field->name}' in group '{$groupKey}' references missing group '{$ref}'";
                    continue;
                }

                foreach ($this->resolveFields($group->fields, $group->key, [...$stack, $ref], $errors) as $resolvedField) {
                    $resolved[] = $resolvedField;
                }

                continue;
            }

            if (str_starts_with($ref, 'field_')) {
                $referencedField = $this->fields[$ref] ?? null;

                if (!$referencedField instanceof FieldDefinition) {
                    $errors[] = "E004: clone field '{$field->name}' in group '{$groupKey}' references missing field '{$ref}'";
                    continue;
                }

                foreach ($this->resolveField($referencedField, $groupKey, $stack, $errors) as $resolvedField) {
                    $resolved[] = $resolvedField;
                }

                continue;
            }

            $errors[] = "E004: clone field '{$field->name}' in group '{$groupKey}' references unsupported key '{$ref}'";
        }

        return $resolved;
    }

    /**
     * @param list<FieldDefinition> $fields
     *
     * @return list<FieldDefinition>
     */
    private function prefixed(array $fields, FieldDefinition $cloneField): array
    {
        if (!$this->boolRaw($cloneField, 'prefix_name')) {
            return $fields;
        }

        $prefixed = [];

        foreach ($fields as $field) {
            $prefixed[] = $this->fieldWith(
                $field,
                $cloneField->name . '_' . $field->name,
                $field->type,
                $field->subFields,
                $field->layouts,
            );
        }

        return $prefixed;
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private function cloneRefs(FieldDefinition $field, string $groupKey, array &$errors): array
    {
        $refs = $field->raw['clone'] ?? null;

        if (!\is_array($refs)) {
            $errors[] = "E004: clone field '{$field->name}' in group '{$groupKey}' has no clone references";

            return [];
        }

        $strings = [];

        foreach ($refs as $ref) {
            if (!\is_string($ref) || $ref === '') {
                $errors[] = "E004: clone field '{$field->name}' in group '{$groupKey}' has an invalid clone reference";
                continue;
            }

            $strings[] = $ref;
        }

        return $strings;
    }

    /**
     * @param list<FieldDefinition> $subFields
     * @param array<string, list<FieldDefinition>> $layouts
     */
    private function fieldWith(FieldDefinition $field, string $name, string $type, array $subFields, array $layouts): FieldDefinition
    {
        return new FieldDefinition(
            key: $field->key,
            name: $name,
            label: $field->label,
            type: $type,
            required: $field->required,
            returnFormat: $field->returnFormat,
            multiple: $field->multiple,
            fieldType: $field->fieldType,
            subFields: $subFields,
            layouts: $layouts,
            raw: $field->raw,
        );
    }

    private function stringRaw(FieldDefinition $field, string $key): string
    {
        $value = $field->raw[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    private function boolRaw(FieldDefinition $field, string $key): bool
    {
        $value = $field->raw[$key] ?? null;

        return $value === true || $value === 1 || $value === '1';
    }
}
