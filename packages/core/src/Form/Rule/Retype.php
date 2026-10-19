<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class Retype implements RuleInterface
{
    public function __construct(
        private readonly string $field,
        private readonly ?string $message = null,
    ) {}

    public function skipIfEmpty(): bool
    {
        return true;
    }

    public function eachElement(): bool
    {
        return false;
    }

    public function validate(mixed $value, ValidationContext $context): ?string
    {
        $current = Validator::stringValue($value);
        $other = Validator::stringValue($context->value($this->field));

        if ($current !== null && $other !== null && $current === $other) {
            return null;
        }

        $message = $this->message ?? $this->translate('{label} must match {otherLabel}.');

        if (\function_exists('apply_filters')) {
            $filtered = apply_filters('tmswp/form/rule_message', $message, $this, $context);

            if (\is_string($filtered)) {
                $message = $filtered;
            }
        }

        return strtr($message, [
            '{label}' => $context->label($context->currentField),
            '{otherLabel}' => $context->label($this->field),
        ]);
    }

    private function translate(string $message): string
    {
        return \function_exists('__') ? __($message, 'tmswp') : $message;
    }
}
