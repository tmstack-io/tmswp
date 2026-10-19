<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use TMSWP\Core\Exception\MigrationException;
use TMSWP\Core\Kernel\Kernel;

final class Migrator
{
    private const OPTION_NAME = 'tmswp_schema_hashes';

    /** @var array<class-string<Model>, TableDefinition> */
    private array $models = [];

    /** @var array<string, class-string<Model>> */
    private array $tables = [];

    private bool $listening = false;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /** @param class-string<Model> $model */
    public function register(string $model): void
    {
        if (!is_subclass_of($model, Model::class)) {
            throw new MigrationException("model must extend Model: {$model}");
        }

        $definition = $model::table();
        $table = $definition->tableName();

        if (\array_key_exists($table, $this->tables) && $this->tables[$table] !== $model) {
            throw new MigrationException("table '{$table}' is already registered by {$this->tables[$table]}");
        }

        $this->models[$model] = $definition;
        $this->tables[$table] = $model;
    }

    /** @return list<class-string<Model>> */
    public function models(): array
    {
        return array_keys($this->models);
    }

    /** @return list<MigrationStatus> */
    public function status(): array
    {
        $hashes = $this->loadHashes();
        $statuses = [];

        foreach ($this->models as $model => $definition) {
            $table = $definition->tableName();
            $hash = $definition->hash($this->connection);
            $storedHash = $hashes[$table] ?? null;
            $statuses[] = new MigrationStatus(
                $model,
                $table,
                $this->tableExists($definition),
                $storedHash === $hash,
                $hash,
                $storedHash,
            );
        }

        return $statuses;
    }

    /**
     * @return list<MigrationResult>
     */
    public function migrateAll(bool $force = false, bool $dryRun = false): array
    {
        $hashes = $this->loadHashes();
        $results = [];

        foreach ($this->models as $model => $definition) {
            $table = $definition->tableName();
            $hash = $definition->hash($this->connection);

            if (!$force && ($hashes[$table] ?? null) === $hash) {
                $results[] = new MigrationResult($model, $table, 'current', $hash);
                continue;
            }

            if ($dryRun) {
                $results[] = new MigrationResult($model, $table, 'pending', $hash);
                continue;
            }

            $this->loadDbDelta();
            $this->migrateDefinition($definition);
            $this->assertMigrated($definition);
            $hashes[$table] = $hash;
            $this->saveHashes($hashes);
            $results[] = new MigrationResult($model, $table, 'migrated', $hash);
        }

        return $results;
    }

    public function migrate(string $table): MigrationResult
    {
        if (!\array_key_exists($table, $this->tables)) {
            throw new MigrationException("unregistered table: {$table}");
        }

        $model = $this->tables[$table];
        $definition = $this->models[$model];
        $hash = $definition->hash($this->connection);

        $this->loadDbDelta();
        $this->migrateDefinition($definition);
        $this->assertMigrated($definition);
        $hashes = $this->loadHashes();
        $hashes[$table] = $hash;
        $this->saveHashes($hashes);

        return new MigrationResult($model, $table, 'migrated', $hash);
    }

    public function dropTable(string $table): void
    {
        if (!\array_key_exists($table, $this->tables)) {
            throw new MigrationException("unregistered table: {$table}");
        }

        $definition = $this->models[$this->tables[$table]];
        $this->connection->execute('DROP TABLE IF EXISTS ' . $this->connection->tableName($definition->tableName()));
        $hashes = $this->loadHashes();
        unset($hashes[$table]);
        $this->saveHashes($hashes);
    }

    public function listen(Kernel $kernel): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        add_action('tmswp/activated', function (Kernel $activatedKernel) use ($kernel): void {
            if ($activatedKernel !== $kernel) {
                return;
            }

            $this->migrateAll(force: true);
        }, 10, 1);

        add_action('admin_init', function (): void {
            try {
                $this->migrateAll();
            } catch (MigrationException $exception) {
                wp_admin_notice($exception->getMessage(), ['type' => 'error']);

                if (\defined('WP_DEBUG') && WP_DEBUG === true) {
                    wp_trigger_error(__METHOD__, $exception->getMessage(), E_USER_WARNING);
                }
            }
        }, 5, 0);

        MigrateCommand::register();
    }

    private function migrateDefinition(TableDefinition $definition): void
    {
        dbDelta($definition->toSql($this->connection));
    }

    private function assertMigrated(TableDefinition $definition): void
    {
        if (!$this->tableExists($definition)) {
            throw new MigrationException("table was not created: {$definition->tableName()}");
        }

        $existingColumns = $this->existingColumns($definition);

        foreach ($definition->columns() as $column) {
            if (!\array_key_exists($column, $existingColumns)) {
                throw new MigrationException("column was not created: {$definition->tableName()}.{$column}");
            }
        }

        $existingIndexes = $this->existingIndexes($definition);
        $primaryKey = $existingIndexes['PRIMARY'] ?? [];

        if ($primaryKey !== $definition->primaryColumns()) {
            throw new MigrationException("primary key does not match table definition: {$definition->tableName()}");
        }

        foreach ($definition->indexes() as $index) {
            $columns = $existingIndexes[$index['name']] ?? [];

            if ($columns !== $index['columns']) {
                throw new MigrationException("index was not created: {$definition->tableName()}.{$index['name']}");
            }
        }
    }

    private function tableExists(TableDefinition $definition): bool
    {
        return $this->connection->selectVar('SHOW TABLES LIKE %s', [$this->connection->tableName($definition->tableName())]) !== null;
    }

    /** @return array<string, true> */
    private function existingColumns(TableDefinition $definition): array
    {
        $columns = [];

        foreach ($this->connection->select('SHOW COLUMNS FROM ' . $this->connection->tableName($definition->tableName())) as $row) {
            $field = $row['Field'] ?? null;

            if (!\is_string($field)) {
                throw new MigrationException("SHOW COLUMNS returned an invalid row for {$definition->tableName()}");
            }

            $columns[$field] = true;
        }

        return $columns;
    }

    /** @return array<string, list<string>> */
    private function existingIndexes(TableDefinition $definition): array
    {
        /** @var array<string, array<int, string>> $indexes */
        $indexes = [];

        foreach ($this->connection->select('SHOW INDEX FROM ' . $this->connection->tableName($definition->tableName())) as $row) {
            $name = $row['Key_name'] ?? null;
            $column = $row['Column_name'] ?? null;
            $sequence = $row['Seq_in_index'] ?? null;

            if (!\is_string($name) || !\is_string($column) || (!\is_int($sequence) && !\is_string($sequence))) {
                throw new MigrationException("SHOW INDEX returned an invalid row for {$definition->tableName()}");
            }

            $sequence = \is_int($sequence) ? $sequence : (ctype_digit($sequence) ? (int) $sequence : 0);

            if ($sequence < 1) {
                throw new MigrationException("SHOW INDEX returned an invalid sequence for {$definition->tableName()}");
            }

            $indexes[$name][$sequence] = $column;
        }

        $normalized = [];

        foreach ($indexes as $name => $columns) {
            ksort($columns);
            $normalized[$name] = array_values($columns);
        }

        return $normalized;
    }

    /** @return array<string, string> */
    private function loadHashes(): array
    {
        $value = get_option(self::OPTION_NAME);

        if ($value === false) {
            return [];
        }

        if (!\is_array($value)) {
            throw new MigrationException('schema hash option must be an array');
        }

        $hashes = [];

        foreach ($value as $table => $hash) {
            if (!\is_string($table) || !\is_string($hash)) {
                throw new MigrationException('schema hash option contains an invalid entry');
            }

            $hashes[$table] = $hash;
        }

        return $hashes;
    }

    /** @param array<string, string> $hashes */
    private function saveHashes(array $hashes): void
    {
        $exists = get_option(self::OPTION_NAME) !== false;
        $saved = $exists
            ? update_option(self::OPTION_NAME, $hashes, false)
            : add_option(self::OPTION_NAME, $hashes, '', false);

        if ($saved === false && get_option(self::OPTION_NAME) !== $hashes) {
            throw new MigrationException('failed to save schema hashes');
        }
    }

    private function loadDbDelta(): void
    {
        if (\function_exists('dbDelta')) {
            return;
        }

        if (!\defined('ABSPATH')) {
            throw new MigrationException('ABSPATH is required to load dbDelta');
        }

        $path = rtrim(ABSPATH, '/\\') . '/wp-admin/includes/upgrade.php';

        if (!is_file($path)) {
            throw new MigrationException("dbDelta file not found: {$path}");
        }

        require_once $path;
    }
}
