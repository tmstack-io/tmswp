<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

final readonly class DiscoveryResult
{
    /**
     * @param list<DiscoveredComponent> $components
     * @param list<DiscoveredHookMethod> $hookMethods
     */
    public function __construct(
        public array $components,
        public array $hookMethods,
    ) {}
}
