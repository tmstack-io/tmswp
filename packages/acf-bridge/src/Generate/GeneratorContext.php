<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Generate;

use Closure;
use TMSWP\AcfBridge\BridgeConfig;

final readonly class GeneratorContext
{
    /**
     * @param array<string, string> $optionsPagePostIds
     * @param null|Closure(string): ?string $runtimePostIdResolver
     */
    public function __construct(
        public BridgeConfig $config,
        public array $optionsPagePostIds,
        public ?Closure $runtimePostIdResolver = null,
        public string $sourceHash = '',
    ) {}
}
