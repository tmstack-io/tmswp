<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use InvalidArgumentException;
use Mockery;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Stringable;
use wpdb;

// ユニット層には WordPress 本体が無く、wpdb クラスが存在しない。Connection などの型宣言 \wpdb を満たすため、
// この test double を読み込んだときに限り、振る舞いを持たない空の wpdb 型を宣言する。
// wpdb の振る舞いはすべて下の WpdbTestDouble が持つ（偽 wpdb の実装はここだけに置く）。
if (!class_exists(wpdb::class, false)) {
    Mockery::declareClass(wpdb::class);
}

/**
 * ユニット層用の wpdb フェイク。
 *
 * 実行した SQL と insert/update/delete の呼び出しを記録し、読み取り結果はキューから返す。
 * failNextWrite() で、書き込み系の呼び出しを実 wpdb の失敗と同じく false で返させられる。
 */
final class WpdbTestDouble extends wpdb
{
    /** @var string */
    public $prefix = 'wp_';

    /** @var string */
    public $options = 'wp_options';

    /** @var int */
    public $insert_id = 0;

    /** @var int */
    public $rows_affected = 0;

    /** @var string */
    public $last_error = '';

    /** @var list<string> */
    private array $tmswpQueries = [];

    /** @var list<array{table: string, data: array<string, mixed>, format: list<string>|string|null}> */
    private array $tmswpInsertCalls = [];

    /** @var list<array{table: string, data: array<string, mixed>, where: array<string, mixed>, format: list<string>|string|null, where_format: list<string>|string|null}> */
    private array $tmswpUpdateCalls = [];

    /** @var list<array{table: string, where: array<string, mixed>, where_format: list<string>|string|null}> */
    private array $tmswpDeleteCalls = [];

    /** @var list<list<array<string, mixed>>> */
    private array $tmswpResultSets = [];

    /** @var list<array<string, mixed>|null> */
    private array $tmswpRows = [];

    /** @var list<string|null> */
    private array $tmswpVars = [];

    /** @var array<string, string> */
    private array $tmswpClaimedJtis = [];

    private string $tmswpLastQuery = '';

    /** 次の書き込み系呼び出しを失敗させるときに wpdb::$last_error へ入れる値。null なら失敗させない。 */
    private ?string $tmswpNextWriteError = null;

    /** @var list<mixed> */
    private array $tmswpLastArgs = [];

    public static function create(): self
    {
        $reflection = new ReflectionClass(self::class);
        $wpdb = $reflection->newInstanceWithoutConstructor();

        if (!$wpdb instanceof self) {
            throw new RuntimeException('failed to create wpdb test double');
        }

        return $wpdb;
    }

    /**
     * wpdb の出力形式定数を定義する（実 WordPress では wp-includes/class-wpdb.php が定義する）。
     *
     * ユニット層では WP 定数を定義しない（docs/10 §4.4）。ただし wpdb 境界そのものである
     * Connection::select()/selectRow() は ARRAY_A を参照するため、それを通すテストに限ってこの定数が要る。
     * 定数は取り消せずプロセス全体に漏れるので、呼び出すテストは必ず #[RunInSeparateProcess] で分離する。
     * 分離されずに二度定義されると define() の警告で失敗する（failOnWarning）。
     */
    public static function defineOutputConstants(): void
    {
        \define('OBJECT', 'OBJECT');
        \define('OBJECT_K', 'OBJECT_K');
        \define('ARRAY_A', 'ARRAY_A');
        \define('ARRAY_N', 'ARRAY_N');
    }

    /**
     * 次の書き込み系呼び出し（query / insert / update / delete）を、実 wpdb の失敗と同じく false で返させる。
     *
     * $lastError が空でなければ wpdb::$last_error に設定する（実 wpdb は DB エラー時に MySQL のエラー文を入れる）。
     * 空のままなら、last_error を伴わずに false だけが返る経路を再現する。
     */
    public function failNextWrite(string $lastError = ''): void
    {
        $this->tmswpNextWriteError = $lastError;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function queueResultSet(array $rows): void
    {
        $this->tmswpResultSets[] = $rows;
    }

    /** @param array<string, mixed>|null $row */
    public function queueRow(?array $row): void
    {
        $this->tmswpRows[] = $row;
    }

    public function queueVar(?string $value): void
    {
        $this->tmswpVars[] = $value;
    }

    /** @return list<string> */
    public function queries(): array
    {
        return $this->tmswpQueries;
    }

    /** @return list<array{table: string, data: array<string, mixed>, format: list<string>|string|null}> */
    public function insertCalls(): array
    {
        return $this->tmswpInsertCalls;
    }

    /** @return list<array{table: string, data: array<string, mixed>, where: array<string, mixed>, format: list<string>|string|null, where_format: list<string>|string|null}> */
    public function updateCalls(): array
    {
        return $this->tmswpUpdateCalls;
    }

    /** @return list<array{table: string, where: array<string, mixed>, where_format: list<string>|string|null}> */
    public function deleteCalls(): array
    {
        return $this->tmswpDeleteCalls;
    }

    /**
     * @param mixed $query
     * @param mixed ...$args
     */
    public function prepare($query, ...$args): string
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        if (\count($args) === 1 && \is_array($args[0])) {
            $args = array_values($args[0]);
        }

        $normalizedArgs = [];
        foreach ($args as $arg) {
            $normalizedArgs[] = $arg;
        }

        $this->tmswpLastQuery = $query;
        $this->tmswpLastArgs = $normalizedArgs;
        $index = 0;

        return (string) preg_replace_callback(
            '/(?<!%)%(?:\d+\$)?[dfsi]/',
            static function (array $matches) use (&$index, $normalizedArgs): string {
                $placeholder = $matches[0];
                if (!\array_key_exists($index, $normalizedArgs)) {
                    throw new InvalidArgumentException('missing SQL binding');
                }

                $value = $normalizedArgs[$index];
                ++$index;

                return match ($placeholder[\strlen($placeholder) - 1]) {
                    'd' => self::intSql($value),
                    'f' => self::floatSql($value),
                    'i' => '`' . str_replace('`', '``', self::stringSql($value)) . '`',
                    default => "'" . str_replace("'", "''", self::stringSql($value)) . "'",
                };
            },
            $query,
        );
    }

    /**
     * @param mixed $query
     * @param mixed $output
     */
    public function get_results($query = null, $output = \OBJECT)
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        $this->last_error = '';
        $this->tmswpQueries[] = $query;

        $rows = array_shift($this->tmswpResultSets);

        if ($rows === null) {
            return null;
        }

        if ($output === \ARRAY_A) {
            return $rows;
        }

        if ($output === \ARRAY_N) {
            return array_map(static fn(array $row): array => array_values($row), $rows);
        }

        return array_map(static fn(array $row): stdClass => (object) $row, $rows);
    }

    /**
     * @param mixed $query
     * @param mixed $output
     * @param mixed $y
     */
    public function get_row($query = null, $output = \OBJECT, $y = 0)
    {
        if ($query === null) {
            return;
        }

        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        $this->last_error = '';
        $this->tmswpQueries[] = $query;

        $row = array_shift($this->tmswpRows) ?? null;

        if ($row === null || $output === \ARRAY_A) {
            return $row;
        }

        if ($output === \ARRAY_N) {
            return array_values($row);
        }

        return (object) $row;
    }

    /**
     * @param mixed $query
     * @param mixed $x
     * @param mixed $y
     */
    public function get_var($query = null, $x = 0, $y = 0): ?string
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        $this->last_error = '';
        $this->tmswpQueries[] = $query;

        return array_shift($this->tmswpVars);
    }

    /** @param mixed $query */
    public function query($query): int|false
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        $this->tmswpQueries[] = $query;

        if ($this->consumeWriteFailure()) {
            return false;
        }

        if (str_starts_with($this->tmswpLastQuery, 'INSERT IGNORE')) {
            $jti = $this->tmswpLastArgs[1] ?? null;
            $expires = $this->tmswpLastArgs[2] ?? null;

            if (!\is_string($jti) || !\is_string($expires)) {
                throw new InvalidArgumentException('invalid JTI claim arguments');
            }

            if (\array_key_exists($jti, $this->tmswpClaimedJtis)) {
                $this->rows_affected = 0;

                return 0;
            }

            $this->tmswpClaimedJtis[$jti] = $expires;
            $this->rows_affected = 1;

            return 1;
        }

        if (str_starts_with($this->tmswpLastQuery, 'DELETE FROM') && str_contains($this->tmswpLastQuery, 'option_name LIKE')) {
            $this->rows_affected = 0;

            return 0;
        }

        $this->rows_affected = 1;

        if (str_starts_with($query, 'INSERT INTO')) {
            ++$this->insert_id;
        }

        return 1;
    }

    /**
     * @param mixed $table
     * @param mixed $data
     * @param mixed $format
     */
    public function insert($table, $data, $format = null): int|false
    {
        if (!\is_string($table) || !\is_array($data) || (!\is_array($format) && !\is_string($format) && $format !== null)) {
            throw new InvalidArgumentException('invalid insert arguments');
        }

        $this->tmswpInsertCalls[] = ['table' => $table, 'data' => $this->stringKeyedArray($data), 'format' => $this->normalizeFormat($format)];

        if ($this->consumeWriteFailure()) {
            return false;
        }

        ++$this->insert_id;
        $this->rows_affected = 1;

        return 1;
    }

    /**
     * @param mixed $table
     * @param mixed $data
     * @param mixed $where
     * @param mixed $format
     * @param mixed $where_format
     */
    public function update($table, $data, $where, $format = null, $where_format = null): int|false
    {
        if (!\is_string($table) || !\is_array($data) || !\is_array($where) || (!\is_array($format) && !\is_string($format) && $format !== null) || (!\is_array($where_format) && !\is_string($where_format) && $where_format !== null)) {
            throw new InvalidArgumentException('invalid update arguments');
        }

        $this->tmswpUpdateCalls[] = [
            'table' => $table,
            'data' => $this->stringKeyedArray($data),
            'where' => $this->stringKeyedArray($where),
            'format' => $this->normalizeFormat($format),
            'where_format' => $this->normalizeFormat($where_format),
        ];

        if ($this->consumeWriteFailure()) {
            return false;
        }

        $this->rows_affected = 1;

        return 1;
    }

    /**
     * @param mixed $table
     * @param mixed $where
     * @param mixed $where_format
     */
    public function delete($table, $where, $where_format = null): int|false
    {
        if (!\is_string($table) || !\is_array($where) || (!\is_array($where_format) && !\is_string($where_format) && $where_format !== null)) {
            throw new InvalidArgumentException('invalid delete arguments');
        }

        $this->tmswpDeleteCalls[] = [
            'table' => $table,
            'where' => $this->stringKeyedArray($where),
            'where_format' => $this->normalizeFormat($where_format),
        ];

        if ($this->consumeWriteFailure()) {
            return false;
        }

        $this->rows_affected = 1;

        return 1;
    }

    /** @param mixed $text */
    public function esc_like($text): string
    {
        if (!\is_string($text)) {
            throw new InvalidArgumentException('text must be string');
        }

        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /**
     * 実 wpdb の flush() と同じく直前の last_error を消し、failNextWrite() の予約があれば消費して失敗状態にする。
     */
    private function consumeWriteFailure(): bool
    {
        $this->last_error = '';

        if ($this->tmswpNextWriteError === null) {
            return false;
        }

        $this->last_error = $this->tmswpNextWriteError;
        $this->tmswpNextWriteError = null;
        $this->rows_affected = 0;

        return true;
    }

    /**
     * @param array<mixed> $array
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $array): array
    {
        $normalized = [];

        foreach ($array as $key => $value) {
            if (!\is_string($key)) {
                throw new InvalidArgumentException('array key must be string');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed>|string|null $format
     *
     * @return list<string>|string|null
     */
    private function normalizeFormat(array|string|null $format): array|string|null
    {
        if (!\is_array($format)) {
            return $format;
        }

        $normalized = [];

        foreach ($format as $item) {
            if (!\is_string($item)) {
                throw new InvalidArgumentException('format must contain strings');
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private static function intSql(mixed $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (\is_string($value) && preg_match('/\A-?(0|[1-9][0-9]*)\z/', $value) === 1) {
            return $value;
        }

        throw new InvalidArgumentException('integer SQL binding is invalid');
    }

    private static function floatSql(mixed $value): string
    {
        if (\is_float($value) || \is_int($value)) {
            return (string) $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return $value;
        }

        throw new InvalidArgumentException('float SQL binding is invalid');
    }

    private static function stringSql(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException('string SQL binding is invalid');
    }
}
