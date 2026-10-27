<?php

declare(strict_types=1);

namespace TMSWP\Testing;

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
 * 実行した SQL と insert/update/delete の呼び出しを記録し、読み取り結果はキューから返す。prepare()は実wpdbと同じ
 * SQLを返し(`%`はplaceholder_escape()に置き換わる)、実行したSQLは実wpdbのqueryフィルタと同じく
 * remove_placeholder_escape()を通して記録する。
 *
 * prepare()が実wpdbと意図して違うのは次の5つだけ。
 * 1. placeholder_escape()の文字列は、実wpdbではプロセスで1つ、代役ではインスタンスで1つ。
 * 2. 文字列でもnullでもないSQLは、InvalidArgumentExceptionにする。
 * 3. 文字列の値は、mysqli_real_escape_string()の代わりに同じ文字を置き換える(utf8mb4の接続と通常のSQLモードと同じ
 *    結果。NO_BACKSLASH_ESCAPESと不正なバイト列は対象外)。
 * 4. allow_unsafe_unquoted_parametersは、実wpdbの既定値(true)の振る舞いだけを持つ。
 * 5. `%i`の位置の値(番号付きの書式で使う引数を含む)が、配列・resource・null・Stringableでないオブジェクトなら、
 *    InvalidArgumentExceptionにする。実wpdbはこれらにPHPの警告・非推奨の通知・TypeErrorを出し、結果はPHPの弱い
 *    型の変換と診断の順序で決まるため、再現しない。
 *
 * failNextWrite() で、書き込み系の呼び出しを実 wpdb の失敗と同じく false で返させられる。
 * failNextRead() で、読み取り系の呼び出しを実 wpdb の失敗と同じく last_error を残して null で返させられる。
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

    /**
     * 実 wpdb が実行したクエリの数。この代役のメソッドは数えない(WpDatabaseGuard は、呼び出しの間に増えたときだけ
     * last_error を見る)。データベースのエラーを再現するテストが、エラーと一緒に増やす。
     *
     * @var int
     */
    public $num_queries = 0;

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

    /** 次の読み取り系呼び出しを失敗させるときに wpdb::$last_error へ入れる値。null なら失敗させない。 */
    private ?string $tmswpNextReadError = null;

    /** @var list<mixed> */
    private array $tmswpLastArgs = [];

    /** @var list<string> */
    private array $tmswpPrepareNotices = [];

    private ?string $tmswpPlaceholderEscape = null;

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
     * 次の読み取り系呼び出し（get_results / get_row / get_var）を、実 wpdb の失敗と同じく失敗させる。
     *
     * 実 wpdb の読み取り系はデータベースのエラーでも false を返さず、null を返して wpdb::$last_error に MySQL の
     * エラー文を残す。キューの値は消費しない。
     */
    public function failNextRead(string $lastError): void
    {
        if ($lastError === '') {
            throw new InvalidArgumentException('a failed read must leave the error in last_error');
        }

        $this->tmswpNextReadError = $lastError;
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
     * wpdb::prepare()のnotice(実wpdbが_doing_it_wrong()で出すもの)の記録。
     *
     * @return list<string>
     */
    public function prepareNotices(): array
    {
        return $this->tmswpPrepareNotices;
    }

    /**
     * 実wpdb::prepare()(WordPress 7.1のwp-includes/class-wpdb.php)と同じ手順でSQLを組み立てる。
     *
     * allow_unsafe_unquoted_parametersは実wpdbの既定値(true)の振る舞いだけを持つ。文字列の値は、MySQLへの接続が
     * 無いためmysqli_real_escape_string()と同じ文字を同じ形でエスケープする(utf8mb4の接続と同じ結果)。
     * 実wpdbが_doing_it_wrong()を出す場合は、noticeをprepareNotices()に記録し、実wpdbと同じ値を返す。
     * 戻り値の`%`はすべてplaceholder_escape()に置き換わっている(実wpdbと同じ)。
     *
     * 戻り値の型は実wpdb(スタブ)の宣言に合わせる。実wpdbと同じく、nullを返す場合は値の無いreturnで返す。
     *
     * @param mixed $query
     * @param mixed ...$args
     *
     * @return string|void
     */
    public function prepare($query, ...$args)
    {
        if ($query === null) {
            return;
        }

        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        $this->tmswpLastQuery = $query;

        if (!str_contains($query, '%')) {
            $this->tmswpPrepareNotices[] = 'The query argument of wpdb::prepare() must have a placeholder.';
        }

        $allowedFormat = '(?:[1-9][0-9]*[$])?[-+0-9]*(?: |0|\'.)?[-+0-9]*(?:\.[0-9]+)?';
        $query = str_replace("'%s'", '%s', $query);
        $query = str_replace('"%s"', '%s', $query);
        $query = (string) preg_replace("/%(?:%|$|(?!({$allowedFormat})?[sdfFi]))/", '%%\1', $query);
        $split = preg_split("/(^|[^%]|(?:%%)+)(%(?:{$allowedFormat})?[sdfFi])/", $query, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($split === false) {
            throw new RuntimeException('failed to split SQL placeholders');
        }

        $splitCount = \count($split);
        $placeholderCount = intdiv($splitCount - 1, 3);
        $passedAsArray = \count($args) === 1 && \is_array($args[0]);
        // 実wpdbと同じく、配列1つで渡された値はキーを保つ(識別子の引数の位置はキーで照らし合わせる)
        $args = $passedAsArray ? $args[0] : $args;
        $this->tmswpLastArgs = array_values($args);

        $newQuery = '';
        $key = 2;
        $argId = 0;
        $identifierArgs = [];
        $stringArgs = [];

        while ($key < $splitCount) {
            $placeholder = $split[$key];
            $format = substr($placeholder, 1, -1);
            $type = substr($placeholder, -1);

            if ($type === 'f' && str_ends_with($split[$key - 1], '%')) {
                // `%%%f`等: 直前の`%`の数で、この`%f`がプレースホルダかリテラルかを決める
                $preceding = $split[$key - 2] . $split[$key - 1];
                $length = \strlen($preceding);
                $k = 1;

                while ($k <= $length && $preceding[$length - $k] === '%') {
                    ++$k;
                }

                $placeholder = '%' . ($k % 2 === 1 ? '%' : '') . $format . $type;
                --$placeholderCount;
            } else {
                if ($type === 'f') {
                    $type = 'F';
                    $placeholder = '%' . $format . $type;
                }

                if ($type === 'i') {
                    $placeholder = '`%' . $format . 's`';
                    $identifierArgs[] = self::argumentPosition($format, $argId);
                } elseif ($type !== 'd' && $type !== 'F') {
                    $stringArgs[] = self::argumentPosition($format, $argId);

                    if ($format === '' && !str_ends_with($split[$key - 1], '%')) {
                        $placeholder = "'%" . $format . "s'";
                    }
                }
            }

            $newQuery .= $split[$key - 2] . $split[$key - 1] . $placeholder;
            $key += 3;
            ++$argId;
        }

        $query = $newQuery . $split[$key - 2];

        if (array_intersect($identifierArgs, $stringArgs) !== []) {
            $this->tmswpPrepareNotices[] = 'Arguments cannot be prepared as both an Identifier and Value.';

            return;
        }

        $argsCount = \count($args);

        if ($argsCount !== $placeholderCount) {
            if ($placeholderCount === 1 && $passedAsArray) {
                $this->tmswpPrepareNotices[] = 'The query only expected one placeholder, but an array of multiple placeholders was sent.';

                return;
            }

            $this->tmswpPrepareNotices[] = "The query does not contain the correct number of placeholders ({$placeholderCount}) for the number of arguments passed ({$argsCount}).";

            if ($argsCount < $placeholderCount) {
                $maxNumbered = 0;

                for ($i = 2; $i < $splitCount; $i += 3) {
                    $maxNumbered = max($maxNumbered, (int) substr($split[$i], 1));
                }

                if ($maxNumbered === 0 || $argsCount < $maxNumbered) {
                    return '';
                }
            }
        }

        $escapedArgs = [];

        foreach ($args as $i => $value) {
            if (\in_array($i, $identifierArgs, true)) {
                $escapedArgs[] = self::escapeIdentifierValue($value);
            } elseif (\is_int($value) || \is_float($value)) {
                $escapedArgs[] = $value;
            } else {
                if (!\is_scalar($value) && $value !== null) {
                    $this->tmswpPrepareNotices[] = 'Unsupported value type (' . \gettype($value) . ').';
                    $value = '';
                }

                $escapedArgs[] = $this->realEscape($value);
            }
        }

        return $this->add_placeholder_escape(vsprintf($query, $escapedArgs));
    }

    /**
     * 実wpdbと同じく、prepare()の戻り値の`%`を置き換える文字列(`{` + 64桁の16進 + `}`)を返す。インスタンスごとに1つ。
     */
    public function placeholder_escape(): string
    {
        $this->tmswpPlaceholderEscape ??= '{' . hash_hmac('sha256', uniqid('', true), random_bytes(16)) . '}';

        return $this->tmswpPlaceholderEscape;
    }

    /**
     * 実wpdbと同じく、`%`をすべてplaceholder_escape()に置き換える。
     *
     * @param mixed $query
     */
    public function add_placeholder_escape($query): string
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        return str_replace('%', $this->placeholder_escape(), $query);
    }

    /**
     * 実wpdbと同じく、placeholder_escape()を`%`に戻す。実wpdbはqueryフィルタ(優先度0)でこれを呼ぶため、
     * この代役のquery()と読み取り系も、記録する前にこれを通す。
     *
     * @param mixed $query
     */
    public function remove_placeholder_escape($query): string
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        return str_replace($this->placeholder_escape(), '%', $query);
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

        $this->tmswpQueries[] = $this->remove_placeholder_escape($query);

        if ($this->consumeReadFailure()) {
            return null;
        }

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

        $this->tmswpQueries[] = $this->remove_placeholder_escape($query);

        if ($this->consumeReadFailure()) {
            return null;
        }

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

        $this->tmswpQueries[] = $this->remove_placeholder_escape($query);

        if ($this->consumeReadFailure()) {
            return null;
        }

        return array_shift($this->tmswpVars);
    }

    /** @param mixed $query */
    public function query($query): int|false
    {
        if (!\is_string($query)) {
            throw new InvalidArgumentException('query must be string');
        }

        $this->tmswpQueries[] = $this->remove_placeholder_escape($query);

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

        // JtiStore::release(): 獲得済みの jti を手放す
        if (str_starts_with($this->tmswpLastQuery, 'DELETE FROM') && str_contains($this->tmswpLastQuery, 'option_name = %s')) {
            $jti = $this->tmswpLastArgs[1] ?? null;

            if (!\is_string($jti)) {
                throw new InvalidArgumentException('invalid JTI release arguments');
            }

            $this->rows_affected = \array_key_exists($jti, $this->tmswpClaimedJtis) ? 1 : 0;
            unset($this->tmswpClaimedJtis[$jti]);

            return $this->rows_affected;
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
     * 実 wpdb の flush() と同じく直前の last_error を消し、failNextRead() の予約があれば消費して失敗状態にする。
     */
    private function consumeReadFailure(): bool
    {
        $this->last_error = '';

        if ($this->tmswpNextReadError === null) {
            return false;
        }

        $this->last_error = $this->tmswpNextReadError;
        $this->tmswpNextReadError = null;

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

    /** sprintfの番号付きの書式(`1$`)なら、その番号の引数の位置(0始まり)。それ以外は$argId。 */
    private static function argumentPosition(string $format, int $argId): int
    {
        $position = strpos($format, '$');

        return $position === false ? $argId : (int) substr($format, 0, $position) - 1;
    }

    /**
     * 実wpdbの_escape_identifier_value()と同じ結果にする。実wpdbは値をそのままstr_replace()に渡し、class-wpdb.phpは
     * strict_typesを宣言しないため、PHPの弱い型の変換が働く。文字列・int・float・Stringableは文字列に、boolは`1`か
     * 空文字になる。実wpdbがPHPの診断を出す値(配列・resource・null・Stringableでないオブジェクト)は、再現せずに
     * InvalidArgumentExceptionにする(クラスのdocblockの5)。
     */
    private static function escapeIdentifierValue(mixed $value): string
    {
        $string = match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value), $value instanceof Stringable => (string) $value,
            \is_bool($value) => $value ? '1' : '',
            default => throw new InvalidArgumentException('WpdbTestDouble does not emulate %i with ' . get_debug_type($value) . ': real wpdb raises a PHP diagnostic for this input'),
        };

        return str_replace('`', '``', $string);
    }

    /**
     * 実wpdb::_real_escape()と同じく、スカラーでない値は空文字にし、mysqli_real_escape_string()がエスケープする文字を
     * エスケープして、placeholder_escape()を当てる。
     */
    private function realEscape(mixed $value): string
    {
        if (!\is_scalar($value)) {
            return '';
        }

        $escaped = strtr((string) $value, [
            '\\' => '\\\\',
            "\0" => '\\0',
            "\n" => '\\n',
            "\r" => '\\r',
            "'" => "\\'",
            '"' => '\\"',
            "\x1a" => '\\Z',
        ]);

        return $this->add_placeholder_escape($escaped);
    }
}
