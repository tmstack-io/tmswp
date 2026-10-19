<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Json;

use JsonException;
use TMSWP\AcfBridge\Exception\GenerationException;

final class AcfJsonRepository
{
    /** @param list<string> $jsonDirs */
    public function __construct(private readonly array $jsonDirs) {}

    /** @return list<FieldGroup> */
    public function fieldGroups(): array
    {
        $groups = [];
        $seen = [];

        foreach ($this->sourceFiles() as $file) {
            foreach ($this->items($file) as $item) {
                $key = $this->string($item, 'key');

                if (!str_starts_with($key, 'group_') || \array_key_exists($key, $seen)) {
                    continue;
                }

                $seen[$key] = true;
                $groups[] = FieldGroup::fromArray($item, $file);
            }
        }

        usort($groups, static fn(FieldGroup $a, FieldGroup $b): int => $a->key <=> $b->key);

        return $groups;
    }

    /** @return array<string, string> */
    public function optionsPagePostIds(): array
    {
        $postIds = [];

        foreach ($this->sourceFiles() as $file) {
            foreach ($this->items($file) as $item) {
                $key = $this->string($item, 'key');

                if (!str_starts_with($key, 'ui_options_page_')) {
                    continue;
                }

                $menuSlug = $this->string($item, 'menu_slug');
                $postId = $this->string($item, 'post_id');

                if ($menuSlug !== '' && $postId !== '') {
                    $postIds[$menuSlug] = $postId;
                }
            }
        }

        ksort($postIds, SORT_STRING);

        return $postIds;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        $warnings = [];
        $seenGroups = [];

        foreach ($this->sourceFiles() as $file) {
            foreach ($this->items($file) as $item) {
                $key = $this->string($item, 'key');

                if (str_starts_with($key, 'group_')) {
                    if (\array_key_exists($key, $seenGroups)) {
                        $warnings[] = "W010: duplicate ACF field group '{$key}' in {$file} was ignored; first seen in {$seenGroups[$key]}";
                    }

                    $seenGroups[$key] = $seenGroups[$key] ?? $file;
                    continue;
                }

                if (str_starts_with($key, 'ui_options_page_')) {
                    continue;
                }

                $warnings[] = "W001: unsupported ACF JSON '{$key}' in {$file} was ignored";
            }
        }

        return $warnings;
    }

    /** @return list<string> */
    public function sourceFiles(): array
    {
        $files = [];

        foreach ($this->jsonDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $matches = glob(rtrim($dir, '/\\') . '/*.json');

            if ($matches === false) {
                continue;
            }

            foreach ($matches as $file) {
                if (is_file($file)) {
                    $files[] = $file;
                }
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    public function sourceHash(): string
    {
        $items = [];

        foreach ($this->sourceFiles() as $file) {
            foreach ($this->items($file) as $item) {
                if (str_starts_with($this->string($item, 'key'), 'group_')) {
                    $items[] = $this->normalize($item);
                }
            }
        }

        usort($items, static fn(array $a, array $b): int => ($a['key'] ?? '') <=> ($b['key'] ?? ''));

        return hash('sha256', json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new GenerationException("E102: failed to read ACF JSON file {$file}");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GenerationException("E102: invalid ACF JSON file {$file}: {$exception->getMessage()}", previous: $exception);
        }

        if (!\is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            $items = [];

            foreach ($decoded as $item) {
                if (\is_array($item)) {
                    $items[] = $this->stringKeyed($item);
                }
            }

            return $items;
        }

        return [$this->stringKeyed($decoded)];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function normalize(array $item): array
    {
        unset($item['modified']);

        foreach ($item as $key => $value) {
            if (\is_array($value)) {
                $item[$key] = $this->normalizeMixed($value);
            }
        }

        ksort($item, SORT_STRING);

        return $item;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function normalizeMixed(array $value): array
    {
        unset($value['modified']);

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $this->normalizeMixed($item);
            }
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
