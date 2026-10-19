<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

final readonly class MigrationResult
{
    /**
     * @param class-string<Model> $model
     * @param 'current'|'migrated'|'pending' $action
     */
    public function __construct(
        public string $model,
        public string $table,
        public string $action,
        public string $schemaHash,
    ) {}
}
