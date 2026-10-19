<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\Composite\CompositeField;

final class FieldView
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        private readonly Field|CompositeField $field,
        private readonly mixed $value,
        private readonly array $errors,
        private readonly string $formId,
        private readonly string $errorTemplate,
        private readonly ?string $partKey = null,
        private readonly ?Field $partField = null,
    ) {}

    /** @param array<string, mixed> $attrs */
    public function input(array $attrs = []): string
    {
        $field = $this->baseField();
        $type = $field->type->value;

        if (\array_key_exists('type', $attrs)) {
            $type = $this->attributeString($attrs['type'], 'type');
            unset($attrs['type']);
        }

        $value = \array_key_exists('value', $attrs) ? $this->attributeString($attrs['value'], 'value') : $this->stringValue();
        unset($attrs['value']);

        return match ($field->type) {
            FieldType::Text, FieldType::Password, FieldType::Hidden, FieldType::File => FormHtml::input($type, $this->name(), $field->type === FieldType::File ? '' : $value, ['id' => $this->id()] + $attrs),
            default => throw new FormConfigException("field '{$field->name}' cannot be rendered as input"),
        };
    }

    /** @param array<string, mixed> $attrs */
    public function textarea(array $attrs = []): string
    {
        $field = $this->baseField();

        if ($field->type !== FieldType::Textarea) {
            throw new FormConfigException("field '{$field->name}' cannot be rendered as textarea");
        }

        return FormHtml::textarea($this->name(), $this->stringValue(), ['id' => $this->id()] + $attrs);
    }

    /** @param array<string, mixed> $attrs */
    public function select(array $attrs = []): string
    {
        $field = $this->baseField();

        if ($field->type !== FieldType::Select) {
            throw new FormConfigException("field '{$field->name}' cannot be rendered as select");
        }

        return FormHtml::select($this->name(), $field->choices, $this->stringValue(), ['id' => $this->id()] + $attrs);
    }

    /** @return list<OptionView> */
    public function options(): array
    {
        $field = $this->baseField();

        if (!\in_array($field->type, [FieldType::Radios, FieldType::Checkboxes], true)) {
            throw new FormConfigException("field '{$field->name}' does not have options");
        }

        $values = $field->multiple ? $this->listValue() : [$this->stringValue()];
        $options = [];
        $index = 0;

        foreach ($field->choices as $value => $label) {
            $options[] = new OptionView((string) $value, $label, \in_array((string) $value, $values, true), $this->id() . '-' . $index, $this->name(), $field->type);
            ++$index;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $labelAttrs
     */
    public function checkboxes(array $attrs = [], array $labelAttrs = []): string
    {
        return $this->optionLabels(FieldType::Checkboxes, $attrs, $labelAttrs);
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $labelAttrs
     */
    public function radios(array $attrs = [], array $labelAttrs = []): string
    {
        return $this->optionLabels(FieldType::Radios, $attrs, $labelAttrs);
    }

    /** @param array<string, mixed> $attrs */
    public function checkbox(array $attrs = []): string
    {
        $field = $this->baseField();

        if ($field->type !== FieldType::Checkbox) {
            throw new FormConfigException("field '{$field->name}' cannot be rendered as checkbox");
        }

        return FormHtml::checkbox($this->name(), $field->checkedValue, $this->stringValue() === $field->checkedValue, ['id' => $this->id()] + $attrs);
    }

    public function hidden(): string
    {
        $field = $this->baseField();

        if ($field->type === FieldType::File) {
            $value = $this->value instanceof StoredFile ? $this->value->token : '';

            return FormHtml::hidden($field->name . '__token', $value);
        }

        return FormHtml::hidden($this->name(), $this->stringValue());
    }

    public function part(string $key): self
    {
        if (!$this->field instanceof CompositeField) {
            throw new FormConfigException('part() can be called only for composite fields');
        }

        $partFields = $this->field->parts();

        if (!\array_key_exists($key, $partFields)) {
            throw new FormConfigException("unknown composite part: {$key}");
        }

        if (\is_array($this->value)) {
            $value = $this->value[$key] ?? '';
        } else {
            $source = $this->stringValue();
            $partValues = $this->field->decompose($source);
            $value = $partValues[$key] ?? '';
        }

        return new self($this->field, $value, $this->errors, $this->formId, $this->errorTemplate, $key, $partFields[$key]);
    }

    public function errors(?string $template = null, bool $firstOnly = false): string
    {
        $template ??= $this->errorTemplate;
        $errors = $firstOnly ? \array_slice($this->errors, 0, 1) : $this->errors;
        $html = '';

        foreach ($errors as $error) {
            $html .= \sprintf($template, FormHtml::escHtml($error));
        }

        return $html;
    }

    public function hasError(): bool
    {
        return $this->errors !== [];
    }

    /** @return list<string> */
    public function errorList(): array
    {
        return $this->errors;
    }

    public function label(): string
    {
        return FormHtml::escHtml($this->baseField()->label);
    }

    /** @param array<string, mixed> $attrs */
    public function labelTag(array $attrs = []): string
    {
        return FormHtml::label($this->baseField()->label, $this->id(), $attrs);
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function display(?string $separator = null): string
    {
        $separator ??= __(', ', 'tmswp');

        if ($this->field instanceof CompositeField) {
            return FormHtml::escHtml($this->field->display($this->stringValue()));
        }

        if ($this->value instanceof UploadedFile || $this->value instanceof StoredFile) {
            return FormHtml::escHtml($this->value->clientName);
        }

        if (\is_array($this->value)) {
            $labels = [];

            foreach ($this->value as $value) {
                $string = Validator::stringValue($value);

                if ($string !== null) {
                    $labels[] = $this->choiceLabel($string);
                }
            }

            return FormHtml::escHtml(implode($separator, $labels));
        }

        $display = $this->choiceLabel($this->stringValue());

        return $this->field->type === FieldType::Textarea
            ? nl2br(FormHtml::escHtml($display), false)
            : FormHtml::escHtml($display);
    }

    public function id(): string
    {
        return $this->partKey === null ? $this->formId . '-' . $this->baseField()->name : $this->formId . '-' . $this->field->name . '-' . $this->partKey;
    }

    public function name(): string
    {
        $field = $this->baseField();

        if ($this->partKey !== null && $this->field instanceof CompositeField) {
            return $this->field->name . '[' . $this->partKey . ']';
        }

        return $field->multiple ? $field->name . '[]' : $field->name;
    }

    private function baseField(): Field
    {
        if ($this->partField instanceof Field) {
            return $this->partField;
        }

        if (!$this->field instanceof Field) {
            throw new FormConfigException('composite field requires part() before rendering input controls');
        }

        return $this->field;
    }

    /** @return list<string> */
    private function listValue(): array
    {
        if (!\is_array($this->value)) {
            return [];
        }

        $values = [];

        foreach ($this->value as $value) {
            $string = Validator::stringValue($value);

            if ($string !== null) {
                $values[] = $string;
            }
        }

        return $values;
    }

    private function stringValue(): string
    {
        return Validator::stringValue($this->value) ?? '';
    }

    private function choiceLabel(string $value): string
    {
        if ($this->field instanceof Field && \array_key_exists($value, $this->field->choices)) {
            return $this->field->choices[$value];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $labelAttrs
     */
    private function optionLabels(FieldType $expected, array $attrs, array $labelAttrs): string
    {
        if ($this->baseField()->type !== $expected) {
            throw new FormConfigException('option label renderer does not match field type');
        }

        $html = '';

        foreach ($this->options() as $option) {
            $html .= '<label' . \TMSWP\Core\Support\Html::attributes(['for' => $option->id] + $labelAttrs) . '>' . $option->input($attrs) . ' ' . FormHtml::escHtml($option->label) . '</label>';
        }

        return $html;
    }

    private function attributeString(mixed $value, string $name): string
    {
        $string = Validator::stringValue($value);

        if ($string === null) {
            throw new FormConfigException("attribute '{$name}' must be scalar or Stringable");
        }

        return $string;
    }
}
