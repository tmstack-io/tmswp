<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final readonly class LogChannel
{
    /**
     * @param list<string> $levels
     * @param list<string> $scopes
     */
    public function __construct(
        public LoggerInterface $logger,
        public array $levels = [],
        public array $scopes = [],
    ) {}
}
