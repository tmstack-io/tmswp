<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\UploadedFile;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class UploadFile implements RuleInterface
{
    public function __construct(private readonly ?string $message = null) {}

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
        if (Validator::isEmpty($value)) {
            return null;
        }

        if ($value instanceof UploadedFile) {
            return $value->isValid() ? null : ($this->message ?? $value->errorMessage());
        }

        if ($value instanceof StoredFile) {
            return $value->exists() ? null : ($this->message ?? $this->translate('The file upload failed.'));
        }

        return $this->message ?? $this->translate('The file upload failed.');
    }

    private function translate(string $message): string
    {
        return \function_exists('__') ? __($message, 'tmswp') : $message;
    }
}
