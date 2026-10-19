<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

final readonly class MigrationStatus
{
    /**
     * @param class-string<Model> $model
     */
    public function __construct(
        public string $model,
        public string $table,
        public bool $exists,
        public bool $current,
        public string $schemaHash,
        public ?string $storedHash,
    ) {}
}
