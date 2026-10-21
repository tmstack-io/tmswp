<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use Closure;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\Rule\RuleInterface;

final class Field
{
    /**
     * @param list<RuleInterface> $rules
     * @param string|list<string> $default
     * @param array<array-key, string> $choices 値 => 表示ラベル。PHP は '1' のような数字の文字列のキーを int に
     *                                          変えるため、キーは int のこともある。値との比較は、すべてキーを
     *                                          文字列にしてから行う(FieldView・FormHtml・Validator)
     * @param list<Closure(string): string> $filters
     */
    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly FieldType $type,
        public readonly array $rules = [],
        public readonly string|array $default = '',
        public readonly array $choices = [],
        public readonly bool $multiple = false,
        public readonly array $filters = [],
        public readonly string $checkedValue = '1',
        public readonly ?string $checkedLabel = null,
    ) {
        self::assertName($name);
        self::assertRules($rules);
        self::assertFilters($filters);

        if (\in_array($type, [FieldType::Select, FieldType::Radios, FieldType::Checkboxes], true) && $choices === []) {
            throw new FormConfigException("choices must not be empty for field '{$name}'");
        }
    }

    /**
     * @param list<RuleInterface> $rules
     * @param list<Closure(string): string> $filters
     */
    public static function text(string $name, string $label, array $rules = [], string $default = '', array $filters = []): self
    {
        return new self($name, $label, FieldType::Text, $rules, $default, filters: array_values([self::trimFilter(), ...$filters]));
    }

    /**
     * @param list<RuleInterface> $rules
     * @param list<Closure(string): string> $filters
     */
    public static function textarea(string $name, string $label, array $rules = [], string $default = '', array $filters = []): self
    {
        return new self($name, $label, FieldType::Textarea, $rules, $default, filters: array_values([self::trimFilter(), ...$filters]));
    }

    /** @param list<RuleInterface> $rules */
    public static function password(string $name, string $label, array $rules = []): self
    {
        return new self($name, $label, FieldType::Password, $rules);
    }

    /** @param list<RuleInterface> $rules */
    public static function hidden(string $name, string $label = '', array $rules = [], string $default = ''): self
    {
        return new self($name, $label, FieldType::Hidden, $rules, $default);
    }

    /**
     * @param array<array-key, string> $choices
     * @param list<RuleInterface> $rules
     */
    public static function select(string $name, string $label, array $choices, array $rules = [], string $default = ''): self
    {
        return new self($name, $label, FieldType::Select, $rules, $default, $choices);
    }

    /**
     * @param array<array-key, string> $choices
     * @param list<RuleInterface> $rules
     */
    public static function radios(string $name, string $label, array $choices, array $rules = [], string $default = ''): self
    {
        return new self($name, $label, FieldType::Radios, $rules, $default, $choices);
    }

    /**
     * @param array<array-key, string> $choices
     * @param list<RuleInterface> $rules
     * @param list<string> $default
     */
    public static function checkboxes(string $name, string $label, array $choices, array $rules = [], array $default = []): self
    {
        return new self(
            $name,
            $label,
            FieldType::Checkboxes,
            $rules,
            array_values(array_map(static fn(string $value): string => $value, $default)),
            $choices,
            true,
        );
    }

    /**
     * 単一のチェックボックス。チェックしたときの値は $checkedValue、しないときは ''。
     *
     * @param list<RuleInterface> $rules
     * @param ?string $checkedLabel チェックしたときに確認画面(FieldView::display())に出す文言。null のフィールドの
     *                              display() は FormConfigException になる(値の '1' をそのまま出さない)
     */
    public static function checkbox(string $name, string $label, string $checkedValue = '1', array $rules = [], ?string $checkedLabel = null): self
    {
        return new self($name, $label, FieldType::Checkbox, $rules, checkedValue: $checkedValue, checkedLabel: $checkedLabel);
    }

    /** @param list<RuleInterface> $rules */
    public static function file(string $name, string $label, array $rules = []): self
    {
        return new self($name, $label, FieldType::File, $rules);
    }

    public function withRules(RuleInterface ...$rules): self
    {
        return new self($this->name, $this->label, $this->type, array_values([...$this->rules, ...$rules]), $this->default, $this->choices, $this->multiple, $this->filters, $this->checkedValue, $this->checkedLabel);
    }

    /** @param Closure(string): string ...$filters */
    public function withFilters(Closure ...$filters): self
    {
        return new self($this->name, $this->label, $this->type, $this->rules, $this->default, $this->choices, $this->multiple, array_values([...$this->filters, ...$filters]), $this->checkedValue, $this->checkedLabel);
    }

    public function isChoiceField(): bool
    {
        return $this->choices !== [];
    }

    private static function assertName(string $name): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
            throw new FormConfigException("invalid field name: {$name}");
        }

        if (\in_array($name, [Form::FIELD_FORM_ID, Form::FIELD_STEP, Form::FIELD_NONCE, Form::FIELD_CONFIRM_TOKEN], true) || str_ends_with($name, '__token')) {
            throw new FormConfigException("reserved field name: {$name}");
        }
    }

    /** @param list<RuleInterface> $rules */
    private static function assertRules(array $rules): void
    {
        foreach ($rules as $rule) {
            if (!$rule instanceof RuleInterface) {
                throw new FormConfigException('field rules must implement RuleInterface');
            }
        }
    }

    /** @param list<Closure(string): string> $filters */
    private static function assertFilters(array $filters): void
    {
        foreach ($filters as $filter) {
            if (!$filter instanceof Closure) {
                throw new FormConfigException('field filters must be Closure instances');
            }
        }
    }

    /** @return Closure(string): string */
    private static function trimFilter(): Closure
    {
        return static function (string $value): string {
            $trimmed = preg_replace('/\A[\s\x{3000}]++|[\s\x{3000}]++\z/u', '', $value);

            return \is_string($trimmed) ? $trimmed : $value;
        };
    }
}
