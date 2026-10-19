<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

final readonly class OptionView
{
    public function __construct(
        public string $value,
        public string $label,
        public bool $checked,
        public string $id,
        public string $name,
        public FieldType $type,
    ) {}

    /** @param array<string, mixed> $attrs */
    public function input(array $attrs = []): string
    {
        $attrs = ['id' => $this->id] + $attrs;

        if ($this->type === FieldType::Radios) {
            return FormHtml::radio($this->name, $this->value, $this->checked, $attrs);
        }

        return FormHtml::checkbox($this->name, $this->value, $this->checked, $attrs);
    }

    /** @param array<string, mixed> $attrs */
    public function labelTag(array $attrs = []): string
    {
        return FormHtml::label($this->label, $this->id, $attrs);
    }
}
