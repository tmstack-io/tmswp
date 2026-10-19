<?php

declare(strict_types=1);

namespace TMSWP\Core\Support;

use TMSWP\Core\Exception\InvalidArgumentException;

final class Html
{
    private function __construct() {}

    /** @param array<int|string, mixed> $attributes */
    public static function attributes(array $attributes): string
    {
        $tokens = [];

        foreach ($attributes as $key => $value) {
            if (\is_int($key)) {
                if (!\is_string($value) || $value === '') {
                    continue;
                }

                $name = $value;
                $value = true;
            } else {
                $name = $key;
            }

            self::assertAttributeName($name);

            if ($name === 'class' && (\is_string($value) || \is_array($value))) {
                $value = self::classes($value);

                if ($value === '') {
                    continue;
                }
            }

            if ($value === null) {
                continue;
            }

            if (\is_bool($value)) {
                if (str_starts_with($name, 'aria-')) {
                    $tokens[] = $name . '="' . esc_attr($value ? 'true' : 'false') . '"';
                    continue;
                }

                if ($value) {
                    $tokens[] = $name;
                }

                continue;
            }

            if (\is_array($value)) {
                $value = implode(' ', array_map(static fn(mixed $item): string => Str::stringify($item), $value));
            } else {
                $value = Str::stringify($value);
            }

            $tokens[] = $name . '="' . esc_attr($value) . '"';
        }

        if ($tokens === []) {
            return '';
        }

        return ' ' . implode(' ', $tokens);
    }

    /** @param string|array<int|string, mixed> ...$values */
    public static function classes(string|array ...$values): string
    {
        $classes = [];

        foreach ($values as $value) {
            if (\is_string($value)) {
                $parts = preg_split('/\s+/', trim($value));

                if ($parts === false) {
                    $parts = [];
                }

                foreach ($parts as $class) {
                    if ($class !== '') {
                        $classes[$class] = true;
                    }
                }

                continue;
            }

            foreach ($value as $class => $enabled) {
                if (\is_int($class)) {
                    $className = \is_string($enabled) ? trim($enabled) : '';
                    $isEnabled = $className !== '';
                } else {
                    $className = trim($class);
                    $isEnabled = $enabled === true;
                }

                if ($isEnabled && $className !== '') {
                    $parts = preg_split('/\s+/', $className);

                    if ($parts === false) {
                        $parts = [];
                    }

                    foreach ($parts as $part) {
                        if ($part !== '') {
                            $classes[$part] = true;
                        }
                    }
                }
            }
        }

        return implode(' ', array_keys($classes));
    }

    private static function assertAttributeName(string $name): void
    {
        if (preg_match('/\A[a-zA-Z][a-zA-Z0-9:_.-]*\z/', $name) === 1) {
            return;
        }

        throw new InvalidArgumentException("invalid HTML attribute name: {$name}");
    }
}
