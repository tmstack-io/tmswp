<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

final readonly class DiscoveryResult
{
    /**
     * @param list<DiscoveredComponent> $components
     * @param list<DiscoveredHookMethod> $hookMethods
     * @param array<string, int> $files
     * @param array<string, int> $dirs
     */
    public function __construct(
        public array $components,
        public array $hookMethods,
        public array $files,
        public array $dirs,
    ) {}
}
