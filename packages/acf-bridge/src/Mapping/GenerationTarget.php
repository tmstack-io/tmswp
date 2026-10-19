<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Mapping;

final readonly class GenerationTarget
{
    public function __construct(
        public TargetKind $kind,
        public string $subtype,
        public string $className,
    ) {}

    public function key(): string
    {
        return $this->kind->value . ':' . $this->subtype;
    }
}
