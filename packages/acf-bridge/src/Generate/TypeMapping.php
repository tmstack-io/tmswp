<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

final readonly class TypeMapping
{
    public function __construct(
        public string $getterNativeType,
        public string $getterDocType,
        public string $coercionHelper,
        public string $setterNativeType,
        public string $setterDocType = '',
        public bool $dateTimeAccessor = false,
        public string $dateRawFormat = '',
    ) {}
}
