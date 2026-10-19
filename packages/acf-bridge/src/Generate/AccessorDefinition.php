<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

use TMSWP\AcfBridge\Json\FieldDefinition;

final readonly class AccessorDefinition
{
    public function __construct(
        public string $groupKey,
        public string $groupTitle,
        public string $sourceFile,
        public FieldDefinition $field,
        public string $constName,
        public string $keyConstName,
        public string $methodName,
        public string $setterName,
        public ?string $dateTimeMethodName,
        public TypeMapping $mapping,
    ) {}
}
