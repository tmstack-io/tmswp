<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use BackedEnum;
use DateTimeInterface;
use ReflectionMethod;
use Stringable;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\QueryException;
use wpdb;

final class Connection
{
    private const PLACEHOLDER_PATTERN = '/(?<!%)%(?:\d+\$)?[dfsi]/';

    public function __construct(
        private readonly wpdb $wpdb,
    ) {}

    public static function fromGlobal(): self
    {
        global $wpdb;

        if (!class_exists(wpdb::class) || !$wpdb instanceof wpdb) {
            throw new QueryException('global wpdb is not available');
        }

        return new self($wpdb);
    }

    public function wpdb(): wpdb
    {
        return $this->wpdb;
    }

    public function prefix(): string
    {
        return $this->wpdb->prefix;
    }

    public function tableName(string $bareName): string
    {
        Identifier::bareTable($bareName);

        return $this->prefix() . $bareName;
    }

    public function escLike(string $value): string
    {
        return $this->wpdb->esc_like($value);
    }

    public function charsetCollate(): string
    {
        return $this->wpdb->get_charset_collate();
    }

    /**
     * @param list<mixed> $bindings
     */
    public function prepare(string $sql, array $bindings = []): string
    {
        $this->assertPlaceholderCount($sql, $bindings);

        if ($bindings === []) {
            return $sql;
        }

        $prepared = $this->prepareWpdb($sql, $this->normalizeBindings($bindings));

        if (!\is_string($prepared)) {
            throw new QueryException('wpdb::prepare failed');
        }

        return $prepared;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $prepared = $this->prepare($sql, $bindings);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);
        $this->assertNoLastError($prepared);

        if (!\is_array($rows)) {
            throw new QueryException("query did not return rows: {$prepared}");
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new QueryException("query returned a non-array row: {$prepared}");
            }

            $normalized[] = $this->stringKeyedRow($row);
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return array<string, mixed>|null
     */
    public function selectRow(string $sql, array $bindings = []): ?array
    {
        $prepared = $this->prepare($sql, $bindings);
        $row = $this->wpdb->get_row($prepared, ARRAY_A);
        $this->assertNoLastError($prepared);

        if ($row === null) {
            return null;
        }

        if (!\is_array($row)) {
            throw new QueryException("query returned a non-array row: {$prepared}");
        }

        return $this->stringKeyedRow($row);
    }

    /** @param list<mixed> $bindings */
    public function selectVar(string $sql, array $bindings = []): mixed
    {
        $prepared = $this->prepare($sql, $bindings);
        $value = $this->wpdb->get_var($prepared);
        $this->assertNoLastError($prepared);

        return $value;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<mixed>
     */
    public function selectCol(string $sql, array $bindings = []): array
    {
        $prepared = $this->prepare($sql, $bindings);
        $values = $this->wpdb->get_col($prepared);
        $this->assertNoLastError($prepared);

        if (!\is_array($values)) {
            throw new QueryException("query did not return a column: {$prepared}");
        }

        return array_values($values);
    }

    /** @param list<mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int
    {
        $prepared = $this->prepare($sql, $bindings);
        $result = $this->wpdb->query($prepared);
        $this->assertNoLastError($prepared);

        if ($result === false) {
            throw new QueryException("query failed: {$prepared}");
        }

        return \is_int($result) ? $result : 0;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new InvalidQueryException('Connection::insert requires at least one value');
        }

        $table = $this->tableForWrite($table);
        $formats = $this->formats($data);
        $result = $this->wpdb->insert($table, $data, $formats);
        $this->assertNoLastError("INSERT INTO {$table}");

        if ($result === false) {
            throw new QueryException("insert failed: {$table}");
        }

        return $this->insertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            throw new InvalidQueryException('Connection::update requires data and where');
        }

        $this->assertScalarWhere($where);
        $table = $this->tableForWrite($table);
        $result = $this->wpdb->update($table, $data, $where, $this->formats($data), $this->formats($where));
        $this->assertNoLastError("UPDATE {$table}");

        if ($result === false) {
            throw new QueryException("update failed: {$table}");
        }

        return \is_int($result) ? $result : 0;
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new InvalidQueryException('Connection::delete requires where');
        }

        $this->assertScalarWhere($where);
        $table = $this->tableForWrite($table);
        $result = $this->wpdb->delete($table, $where, $this->formats($where));
        $this->assertNoLastError("DELETE FROM {$table}");

        if ($result === false) {
            throw new QueryException("delete failed: {$table}");
        }

        return \is_int($result) ? $result : 0;
    }

    public function insertId(): int
    {
        return (int) $this->wpdb->insert_id;
    }

    public function lastError(): string
    {
        return (string) $this->wpdb->last_error;
    }

    /**
     * @param list<mixed> $bindings
     */
    private function assertPlaceholderCount(string $sql, array $bindings): void
    {
        $count = preg_match_all(self::PLACEHOLDER_PATTERN, $sql);

        if ($count === false) {
            throw new InvalidQueryException('failed to parse SQL placeholders');
        }

        if ($count !== \count($bindings)) {
            throw new InvalidQueryException("SQL placeholder count {$count} does not match bindings count " . \count($bindings));
        }
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<int|float|string>
     */
    private function normalizeBindings(array $bindings): array
    {
        $normalized = [];

        foreach ($bindings as $binding) {
            $normalized[] = self::normalizeValue($binding);
        }

        return $normalized;
    }

    public static function placeholderFor(mixed $value): string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        if (\is_int($value)) {
            return '%d';
        }

        if (\is_float($value)) {
            return '%f';
        }

        if (\is_bool($value)) {
            return '%d';
        }

        if (\is_string($value) || $value instanceof Stringable || $value instanceof DateTimeInterface) {
            return '%s';
        }

        throw new InvalidQueryException('value cannot be bound to SQL placeholder');
    }

    public static function normalizeValue(mixed $value): int|float|string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        if (\is_int($value) || \is_float($value) || \is_string($value)) {
            return $value;
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        throw new InvalidQueryException('value cannot be converted to SQL binding');
    }

    private function assertNoLastError(string $sql): void
    {
        $error = $this->lastError();

        if ($error !== '') {
            throw new QueryException("database error: {$error}; SQL: {$sql}");
        }
    }

    /**
     * @param list<int|float|string> $bindings
     */
    private function prepareWpdb(string $sql, array $bindings): mixed
    {
        $method = new ReflectionMethod($this->wpdb, 'prepare');

        return $method->invokeArgs($this->wpdb, [$sql, ...$bindings]);
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function stringKeyedRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            if (!\is_string($key)) {
                throw new QueryException('query row contains a non-string key');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private function formats(array $values): array
    {
        $formats = [];

        foreach ($values as $column => $value) {
            Identifier::simple($column);
            $formats[] = $value === null ? '%s' : self::placeholderFor($value);
        }

        return $formats;
    }

    /** @param array<string, mixed> $where */
    private function assertScalarWhere(array $where): void
    {
        foreach ($where as $column => $value) {
            Identifier::simple($column);

            if (\is_array($value)) {
                throw new InvalidQueryException('Connection where values cannot be arrays');
            }
        }
    }

    private function tableForWrite(string $table): string
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $table) === 1 ? $this->tableName($table) : Identifier::table($table);
    }
}
