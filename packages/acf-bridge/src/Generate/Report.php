<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

final class Report
{
    /**
     * @param list<GeneratedFile> $files
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param list<string> $infos
     */
    public function __construct(
        private readonly array $files,
        private readonly array $errors = [],
        private readonly array $warnings = [],
        private readonly array $infos = [],
    ) {}

    /** @return list<GeneratedFile> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<string> */
    public function infos(): array
    {
        return $this->infos;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
