<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use Closure;
use TMSWP\Core\Form\ValidationContext;

final class Callback implements RuleInterface
{
    /** @param Closure(mixed, ValidationContext): (bool|string) $callback */
    public function __construct(
        private readonly Closure $callback,
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
        $result = ($this->callback)($value, $context);

        if ($result === true) {
            return null;
        }

        if (\is_string($result)) {
            return $result;
        }

        return strtr($this->message ?? __('{label} is invalid.', 'tmswp'), ['{label}' => $context->label($context->currentField)]);
    }
}
