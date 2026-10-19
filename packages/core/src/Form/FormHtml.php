<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Support\Html;

final class FormHtml
{
    private function __construct() {}

    /** @param array<string, mixed> $attrs */
    public static function input(string $type, string $name, string $value, array $attrs = []): string
    {
        return '<input' . Html::attributes(['type' => $type, 'name' => $name, 'value' => $value] + $attrs) . '>';
    }

    /** @param array<string, mixed> $attrs */
    public static function textarea(string $name, string $value, array $attrs = []): string
    {
        return '<textarea' . Html::attributes(['name' => $name] + $attrs) . '>' . self::escHtml($value) . '</textarea>';
    }

    /**
     * @param array<array-key, string> $choices
     * @param string|list<string> $selected
     * @param array<string, mixed> $attrs
     */
    public static function select(string $name, array $choices, string|array $selected, array $attrs = []): string
    {
        $selectedValues = \is_array($selected) ? array_values(array_map(static fn(string $value): string => $value, $selected)) : [$selected];
        $html = '<select' . Html::attributes(['name' => $name] + $attrs) . '>';

        foreach ($choices as $value => $label) {
            $html .= '<option' . Html::attributes([
                'value' => $value,
                'selected' => \in_array((string) $value, $selectedValues, true),
            ]) . '>' . self::escHtml($label) . '</option>';
        }

        return $html . '</select>';
    }

    /** @param array<string, mixed> $attrs */
    public static function checkbox(string $name, string $value, bool $checked, array $attrs = []): string
    {
        return self::input('checkbox', $name, $value, ['checked' => $checked] + $attrs);
    }

    /** @param array<string, mixed> $attrs */
    public static function radio(string $name, string $value, bool $checked, array $attrs = []): string
    {
        return self::input('radio', $name, $value, ['checked' => $checked] + $attrs);
    }

    public static function hidden(string $name, string $value): string
    {
        return self::input('hidden', $name, $value);
    }

    /** @param array<string, mixed> $attrs */
    public static function label(string $text, string $for, array $attrs = []): string
    {
        return '<label' . Html::attributes(['for' => $for] + $attrs) . '>' . self::escHtml($text) . '</label>';
    }

    /** @param array<string, mixed> $attrs */
    public static function openTag(array $attrs): string
    {
        return '<form' . Html::attributes($attrs) . '>';
    }

    public static function escHtml(string $value): string
    {
        return \function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
