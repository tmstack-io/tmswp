<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

final readonly class DiscoveredHookMethod
{
    /** @param non-empty-string $class */
    public function __construct(
        public string $class,
        public string $method,
        public string $hook,
        public int $priority,
        public int $acceptedArgs,
    ) {}
}
