<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use TMSWP\Core\Exception\RuntimeException;
use wpdb;

/**
 * 書き込み操作の中で呼ぶ WP の関数(書き込み関数と、その前後の読み取り)の、データベースのエラーを例外にする。
 *
 * WP の書き込み関数は「同じ値」「ほかのプラグインのフィルタによる中止」「データベースのエラー」のどれでも
 * false を返し、読み取り関数はクエリが失敗すると「行が無い」と同じ結果を返す。値を比べて区別するのではなく、
 * 呼び出しの間に発行したクエリのエラーで判定する。
 *
 * - wpdb::query() は実行の前に flush() で last_error を空にし、実行後に mysqli_error() を last_error に入れる。
 *   クエリを実際に発行したときは wpdb::_do_query() が num_queries を1つ増やす(WP 7.0 の wp-includes/class-wpdb.php)。
 * - WP 7.0 の update_option()・add_option()・update_metadata()・add_metadata()・delete_metadata() は、書き込みの
 *   クエリが失敗すると、ほかのクエリを発行せずにすぐ false を返す(wp-includes/option.php・meta.php)。
 *   そのため、書き込みの失敗のときは、呼び出しの間の最後のクエリが書き込みのクエリであり、その last_error が残る。
 * - 呼び出しの前の無関係なクエリのエラーを拾わないために、呼び出しの間に num_queries が増えた場合だけ last_error を見る。
 *
 * 受け入れる限界(読み取りにも同じく当てはまる):
 * 1. 呼び出しの間にほかのフックが発行したクエリが失敗して最後のクエリとして残った場合は、書き込み関数・読み取り
 *    関数そのものが成功していても例外にする(fail-fast)。
 * 2. delete_option() は削除のクエリが失敗した後もキャッシュを更新し、autoload のオプションで alloptions の
 *    キャッシュが無いときは読み直しのクエリを発行する。その場合は削除のエラーが last_error から消え、false になる。
 * 3. wpdb::query() が num_queries を増やさずに last_error だけを入れる経路(不正なデータを含むクエリの拒否)では、
 *    num_queries が増えないため、書き込みは false(書き込まなかった)、読み取りは成功として扱う。
 * 4. 接続が切れて再接続にも失敗した場合、wpdb::query() は last_error を入れる前に false を返すため、書き込みは
 *    false(書き込まなかった)、読み取りは成功として扱う。データベースのサーバーの障害の経路であり、wpdb の内部
 *    (接続ハンドル等)に依存して検出すると WP の版の変更で壊れやすいため、検出しない。
 *
 * @internal WpMetaStorage と WpOptionStorage だけが使う。
 */
final class WpDatabaseGuard
{
    private function __construct() {}

    /**
     * @param callable(): mixed $write WP の書き込み関数の呼び出し
     * @param string $failure 例外のメッセージの先頭(データベースのエラーの内容を続ける)
     *
     * @throws RuntimeException 書き込まなかったと返し、呼び出しの間のクエリでデータベースのエラーが起きた
     *
     * @return bool WP が書き込んだと返したら true、書き込まなかったと返し、データベースのエラーが無ければ false
     */
    public static function write(callable $write, string $failure): bool
    {
        $wpdb = self::wpdb();
        $queries = $wpdb->num_queries;

        if ($write() !== false) {
            return true;
        }

        self::assertNoDatabaseError($wpdb, $queries, $failure);

        return false;
    }

    /**
     * 書き込み操作の中で行う読み取り(存在の確認、置き換えの前後の行の取得)を呼ぶ。
     *
     * @template T
     *
     * @param callable(): T $read 読み取りの呼び出し
     * @param string $failure 例外のメッセージの先頭(データベースのエラーの内容を続ける)
     *
     * @throws RuntimeException 呼び出しの間のクエリでデータベースのエラーが起きた
     *
     * @return T 読み取りの結果
     */
    public static function read(callable $read, string $failure): mixed
    {
        $wpdb = self::wpdb();
        $queries = $wpdb->num_queries;
        $result = $read();
        self::assertNoDatabaseError($wpdb, $queries, $failure);

        return $result;
    }

    private static function assertNoDatabaseError(wpdb $wpdb, int $queriesBefore, string $failure): void
    {
        if ($wpdb->num_queries > $queriesBefore && $wpdb->last_error !== '') {
            throw new RuntimeException("{$failure}: {$wpdb->last_error}");
        }
    }

    private static function wpdb(): wpdb
    {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            throw new RuntimeException('wpdb is not available');
        }

        return $wpdb;
    }
}
