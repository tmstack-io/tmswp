<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class RequiredIf extends Rule
{
    private function __construct(
        private readonly string $field,
        private readonly ?string $equals,
        ?string $message = null,
    ) {
        parent::__construct($message);
    }

    public static function filled(string $field, ?string $message = null): self
    {
        return new self($field, null, $message);
    }

    public static function equals(string $field, string $value, ?string $message = null): self
    {
        return new self($field, $value, $message);
    }

    public function skipIfEmpty(): bool
    {
        return false;
    }

    public function eachElement(): bool
    {
        return false;
    }

    protected function passes(mixed $value, ValidationContext $context): bool
    {
        $actual = Validator::stringValue($context->value($this->field));
        $condition = $this->equals === null
            ? $context->filled($this->field)
            : $actual === $this->equals;

        return !$condition || !Validator::isEmpty($value);
    }

    public function defaultMessage(): string
    {
        return $this->translate('{label} is required.');
    }
}
