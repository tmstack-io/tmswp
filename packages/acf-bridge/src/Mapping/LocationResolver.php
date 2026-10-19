<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Mapping;

use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Generate\NameConverter;
use TMSWP\AcfBridge\Json\FieldGroup;

final class LocationResolver
{
    /**
     * @return list<GenerationTarget>
     */
    public function resolve(FieldGroup $group, BridgeConfig $config): array
    {
        $warnings = [];

        return $this->resolveWithWarnings($group, $config, $warnings);
    }

    /**
     * @param list<string> $warnings
     *
     * @return list<GenerationTarget>
     */
    public function resolveWithWarnings(FieldGroup $group, BridgeConfig $config, array &$warnings): array
    {
        $targets = [];

        foreach ($group->location as $andGroup) {
            if (!\is_array($andGroup)) {
                continue;
            }

            $target = $this->resolveAndGroup($andGroup, $group->key, $warnings);

            if (!$target instanceof GenerationTarget) {
                continue;
            }

            $className = $config->classMap[$group->key] ?? $target->className;
            $targets[$target->kind->value . "\0" . $target->subtype] = new GenerationTarget($target->kind, $target->subtype, $className);
        }

        ksort($targets, SORT_STRING);

        return array_values($targets);
    }

    /**
     * @param array<array-key, mixed> $conditions
     * @param list<string> $warnings
     */
    private function resolveAndGroup(array $conditions, string $groupKey, array &$warnings): ?GenerationTarget
    {
        $candidates = [];
        $paramValues = [];
        $hasUnresolvedPostId = false;

        foreach ($conditions as $condition) {
            if (!\is_array($condition)) {
                continue;
            }

            $operator = $this->string($condition, 'operator');

            if ($operator !== '==') {
                continue;
            }

            $param = $this->string($condition, 'param');
            $value = $this->string($condition, 'value');

            if (isset($paramValues[$param]) && $paramValues[$param] !== $value) {
                $warnings[] = "W007: ACF field group '{$groupKey}' has an unsatisfiable location condition for '{$param}'";

                return null;
            }

            $paramValues[$param] = $value;

            if ($param === 'post') {
                $hasUnresolvedPostId = true;
                continue;
            }

            if ($this->isUnsupportedParam($param)) {
                $warnings[] = "W006: ACF field group '{$groupKey}' uses unsupported location param '{$param}'";

                return null;
            }

            if ($this->isIgnoredParam($param)) {
                continue;
            }

            $candidate = $this->candidate($param, $value);

            if ($candidate instanceof GenerationTarget) {
                $candidates[$candidate->kind->value] = $candidate;
                continue;
            }

            $warnings[] = "W006: ACF field group '{$groupKey}' uses unknown location param '{$param}'";

            return null;
        }

        if (\count($candidates) > 1) {
            $warnings[] = "W004: ACF field group '{$groupKey}' has multiple target location kinds; priority was applied";
        }

        if ($candidates === [] && $hasUnresolvedPostId) {
            $warnings[] = "W003: ACF field group '{$groupKey}' uses a post ID location that cannot be resolved statically";

            return null;
        }

        foreach ([TargetKind::Post->value, TargetKind::Term->value, TargetKind::Options->value, TargetKind::User->value] as $kind) {
            if (\array_key_exists($kind, $candidates)) {
                return $candidates[$kind];
            }
        }

        return null;
    }

    private function candidate(string $param, string $value): ?GenerationTarget
    {
        if ($value === '') {
            return null;
        }

        return match ($param) {
            'post_type' => new GenerationTarget(TargetKind::Post, $value, NameConverter::studly($value) . 'Fields'),
            'page_template', 'page_type', 'page_parent', 'page' => new GenerationTarget(TargetKind::Post, 'page', 'PageFields'),
            'post_category', 'post_format' => new GenerationTarget(TargetKind::Post, 'post', 'PostFields'),
            'attachment' => new GenerationTarget(TargetKind::Post, 'attachment', 'AttachmentFields'),
            'taxonomy' => new GenerationTarget(TargetKind::Term, $value, $value === 'all' ? 'TermFields' : NameConverter::studly($value) . 'TermFields'),
            'user_form', 'user_role' => new GenerationTarget(TargetKind::User, '', 'UserFields'),
            'options_page' => new GenerationTarget(TargetKind::Options, $value, NameConverter::studly($value) . 'Options'),
            default => null,
        };
    }

    private function isIgnoredParam(string $param): bool
    {
        return \in_array($param, ['current_user', 'current_user_role', 'post_status', 'post_taxonomy'], true);
    }

    private function isUnsupportedParam(string $param): bool
    {
        return \in_array($param, ['comment', 'widget', 'nav_menu', 'nav_menu_item', 'block'], true);
    }

    /** @param array<array-key, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
