<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Composite;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Rule\RuleInterface;

abstract class CompositeField
{
    /** @param list<RuleInterface> $rules */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly array $rules = [],
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
            throw new FormConfigException("invalid composite field name: {$name}");
        }
    }

    /** @return array<string, Field> */
    abstract public function parts(): array;

    /** @param array<string, string> $partValues */
    abstract public function compose(array $partValues): string;

    /** @return array<string, string> */
    abstract public function decompose(string $value): array;

    public function incompleteMessage(): string
    {
        return $this->translate('Please complete all parts of {label}.');
    }

    /** @param array<string, string> $partValues */
    public function validateParts(array $partValues): bool
    {
        return true;
    }

    public function invalidPartsMessage(): string
    {
        return $this->translate('{label} is invalid.');
    }

    public function display(string $value): string
    {
        return $value;
    }

    protected function translate(string $message): string
    {
        return \function_exists('__') ? __($message, 'tmswp') : $message;
    }
}
