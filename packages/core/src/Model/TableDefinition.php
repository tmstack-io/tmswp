<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use TMSWP\Core\Exception\SchemaException;

final class TableDefinition
{
    /** @var list<string> */
    private array $columnLines = [];

    /** @var list<string> */
    private array $columnNames = [];

    /** @var array<string, string> */
    private array $columnTypes = [];

    /** @var list<array{type: 'KEY'|'UNIQUE KEY', name: string, columns: list<string>}> */
    private array $indexes = [];

    /** @var list<string>|null */
    private ?array $primaryColumns = null;

    private ?string $primaryKeyColumn = null;

    private ?string $charset = null;

    private ?string $collation = null;

    private function __construct(
        private readonly string $name,
    ) {
        Identifier::schemaBareTable($name);
    }

    public static function create(string $name): self
    {
        return new self($name);
    }

    public function id(string $name = 'id'): self
    {
        $this->addColumn($name, 'bigint(20) unsigned NOT NULL AUTO_INCREMENT');
        $this->setPrimary([$name]);
        $this->primaryKeyColumn = $name;

        return $this;
    }

    public function string(string $name, int $length = 191, bool $nullable = false, ?string $default = null): self
    {
        if ($length < 1) {
            throw new SchemaException('varchar length must be greater than 0');
        }

        return $this->addColumn($name, "varchar({$length})" . $this->nullAndDefault($nullable, $default));
    }

    public function text(string $name, bool $nullable = true): self
    {
        return $this->addColumn($name, 'text' . $this->nullAndDefault($nullable, null));
    }

    public function int(string $name, bool $nullable = false, ?int $default = null, bool $unsigned = false): self
    {
        return $this->addColumn($name, 'int(11)' . ($unsigned ? ' unsigned' : '') . $this->nullAndDefault($nullable, $default));
    }

    public function bigint(string $name, bool $nullable = false, ?int $default = null, bool $unsigned = false): self
    {
        return $this->addColumn($name, 'bigint(20)' . ($unsigned ? ' unsigned' : '') . $this->nullAndDefault($nullable, $default));
    }

    public function decimal(
        string $name,
        int $precision = 10,
        int $scale = 2,
        bool $nullable = false,
        ?string $default = null,
        bool $unsigned = false,
    ): self {
        if ($precision < 1 || $scale < 0 || $scale > $precision) {
            throw new SchemaException('invalid decimal precision or scale');
        }

        if ($default !== null && !is_numeric($default)) {
            throw new SchemaException('decimal default must be numeric string');
        }

        return $this->addColumn($name, "decimal({$precision},{$scale})" . ($unsigned ? ' unsigned' : '') . $this->nullAndDefault($nullable, $default, quoteString: false));
    }

    public function bool(string $name, bool $default = false): self
    {
        return $this->addColumn($name, 'tinyint(1) NOT NULL DEFAULT ' . ($default ? '1' : '0'));
    }

    public function datetime(string $name, bool $nullable = false, ?string $default = null): self
    {
        return $this->addColumn($name, 'datetime' . $this->datetimeNullAndDefault($nullable, $default));
    }

    public function json(string $name, bool $nullable = true): self
    {
        return $this->addColumn($name, 'longtext' . $this->nullAndDefault($nullable, null));
    }

    public function foreignId(string $name, bool $nullable = false): self
    {
        $this->addColumn($name, 'bigint(20) unsigned' . $this->nullAndDefault($nullable, null));

        return $this->index($name, $name);
    }

    public function rawColumn(string $name, string $definition): self
    {
        if (preg_match('/[\'"`;]/', $definition) === 1) {
            throw new SchemaException('raw column definition must not contain quotes, backticks, or semicolons');
        }

        return $this->addColumn($name, $definition);
    }

    public function primary(string ...$columns): self
    {
        $columns = array_values($columns);
        $this->setPrimary($this->normalizeColumns($columns));
        $this->primaryKeyColumn = \count($columns) === 1 ? $columns[0] : null;

        return $this;
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): self
    {
        $columns = $this->normalizeColumns(\is_array($columns) ? $columns : [$columns]);
        $name ??= implode('_', $columns);
        Identifier::schemaSimple($name);
        $this->assertNameLength($name, 'index name');
        $this->assertIndexable($columns);
        $this->indexes[] = ['type' => 'KEY', 'name' => $name, 'columns' => $columns];

        return $this;
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $columns = $this->normalizeColumns(\is_array($columns) ? $columns : [$columns]);
        $name ??= implode('_', $columns);
        Identifier::schemaSimple($name);
        $this->assertNameLength($name, 'index name');
        $this->assertIndexable($columns);
        $this->indexes[] = ['type' => 'UNIQUE KEY', 'name' => $name, 'columns' => $columns];

        return $this;
    }

    public function charset(string $charset): self
    {
        $this->charset = $this->charsetIdentifier($charset);

        return $this;
    }

    public function collation(string $collation): self
    {
        $this->collation = $this->charsetIdentifier($collation);

        return $this;
    }

    public function tableName(): string
    {
        return $this->name;
    }

    public function primaryKey(): ?string
    {
        return $this->primaryKeyColumn;
    }

    /** @return list<string> */
    public function columns(): array
    {
        return $this->columnNames;
    }

    /** @return list<string> */
    public function primaryColumns(): array
    {
        if ($this->primaryColumns === null) {
            throw new SchemaException('table must declare a primary key');
        }

        return $this->primaryColumns;
    }

    /** @return list<array{type: 'KEY'|'UNIQUE KEY', name: string, columns: list<string>}> */
    public function indexes(): array
    {
        return $this->indexes;
    }

    public function toSql(Connection $connection): string
    {
        if ($this->columnLines === []) {
            throw new SchemaException('table must declare at least one column');
        }

        $primaryColumns = $this->primaryColumns;

        if ($primaryColumns === null) {
            throw new SchemaException('table must declare a primary key');
        }

        $fullName = $connection->tableName($this->name);
        $this->assertNameLength($fullName, 'full table name');
        $lines = $this->columnLines;
        $lines[] = 'PRIMARY KEY  (' . implode(',', $primaryColumns) . ')';

        foreach ($this->indexes as $index) {
            $lines[] = "{$index['type']} {$index['name']} (" . implode(',', $index['columns']) . ')';
        }

        return 'CREATE TABLE ' . $fullName . " (\n"
            . implode(",\n", $lines)
            . "\n) " . $this->charsetClause($connection) . ';';
    }

    public function hash(Connection $connection): string
    {
        return hash('sha256', $this->toSql($connection));
    }

    public function hasColumn(string $name): bool
    {
        return \in_array($name, $this->columnNames, true);
    }

    public function columnType(string $name): string
    {
        if (!\array_key_exists($name, $this->columnTypes)) {
            throw new SchemaException("unknown column: {$name}");
        }

        return $this->columnTypes[$name];
    }

    private function addColumn(string $name, string $definition): self
    {
        Identifier::schemaSimple($name);

        if (\in_array($name, $this->columnNames, true)) {
            throw new SchemaException("duplicate column: {$name}");
        }

        $typeParts = explode(' ', $definition, 2);
        $this->columnNames[] = $name;
        $this->columnTypes[$name] = $typeParts[0] === '' ? $definition : $typeParts[0];
        $this->columnLines[] = "{$name} {$definition}";

        return $this;
    }

    /** @param list<string> $columns */
    private function setPrimary(array $columns): void
    {
        if ($columns === []) {
            throw new SchemaException('primary key must contain at least one column');
        }

        if ($this->primaryColumns !== null) {
            throw new SchemaException('primary key is already declared');
        }

        $this->assertColumnsExist($columns);
        $this->primaryColumns = $columns;
    }

    /**
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private function normalizeColumns(array $columns): array
    {
        if ($columns === []) {
            throw new SchemaException('column list must not be empty');
        }

        $normalized = [];

        foreach ($columns as $column) {
            Identifier::schemaSimple($column);
            $normalized[] = $column;
        }

        $this->assertColumnsExist($normalized);

        return $normalized;
    }

    /** @param list<string> $columns */
    private function assertColumnsExist(array $columns): void
    {
        foreach ($columns as $column) {
            if (!\in_array($column, $this->columnNames, true)) {
                throw new SchemaException("unknown column for key: {$column}");
            }
        }
    }

    /** @param list<string> $columns */
    private function assertIndexable(array $columns): void
    {
        foreach ($columns as $column) {
            $type = $this->columnTypes[$column] ?? '';

            if (\in_array($type, ['text', 'longtext'], true)) {
                throw new SchemaException("column '{$column}' cannot be indexed");
            }
        }
    }

    private function nullAndDefault(bool $nullable, int|string|null $default, bool $quoteString = true): string
    {
        $sql = $nullable ? ' DEFAULT NULL' : ' NOT NULL';

        if ($default !== null) {
            $sql .= ' DEFAULT ' . $this->literal($default, $quoteString);
        }

        return $sql;
    }

    private function datetimeNullAndDefault(bool $nullable, ?string $default): string
    {
        $sql = $nullable ? ' DEFAULT NULL' : ' NOT NULL';

        if ($default !== null) {
            $sql .= ' DEFAULT ' . ($default === 'CURRENT_TIMESTAMP' ? 'CURRENT_TIMESTAMP' : $this->literal($default));
        }

        return $sql;
    }

    private function literal(int|string $value, bool $quoteString = true): string
    {
        if (\is_int($value) || !$quoteString) {
            return (string) $value;
        }

        if (str_contains($value, "'")) {
            throw new SchemaException('string default must not contain single quote');
        }

        return "'{$value}'";
    }

    private function charsetIdentifier(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new SchemaException("invalid charset or collation: {$value}");
        }

        return $value;
    }

    private function charsetClause(Connection $connection): string
    {
        if ($this->charset === null) {
            return $connection->charsetCollate();
        }

        $clause = 'DEFAULT CHARACTER SET ' . $this->charset;

        if ($this->collation !== null) {
            $clause .= ' COLLATE ' . $this->collation;
        }

        return $clause;
    }

    private function assertNameLength(string $name, string $label): void
    {
        if (\strlen($name) > 64) {
            throw new SchemaException("{$label} exceeds 64 characters: {$name}");
        }
    }
}
