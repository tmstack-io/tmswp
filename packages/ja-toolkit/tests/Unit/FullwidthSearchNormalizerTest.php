<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Tests\Unit;

use Brain\Monkey\Actions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\Testing\UnitTestCase;
use WP_Query;

/**
 * FullwidthSearchNormalizer本体: pre_get_postsへの登録と、検索語の正規化(全角スペースの半角化・連続するスペースの
 * まとめ・空白だけの検索語の0件化)。
 *
 * 日本語の検索語は、テストの対象そのもの(全角スペース・全角英数字)である。ユニット層にはWordPressのWP_Queryが
 * 無いため、is_search()・get()・set()だけを持つ代役で確かめる。
 */
#[RunTestsInSeparateProcesses]
final class FullwidthSearchNormalizerTest extends UnitTestCase
{
    public function testRegisterAddsNormalizeToPreGetPosts(): void
    {
        $module = new FullwidthSearchNormalizer();
        Actions\expectAdded('pre_get_posts')->once()->with([$module, 'normalize'], 10, 1);

        $module->register();
    }

    /** @return iterable<string, array{string, string}> */
    public static function searchTerms(): iterable
    {
        yield '全角スペースの区切り' => ['東京　タワー', '東京 タワー'];
        yield '前後と連続の全角スペース' => ['　東京　　タワー　', '東京 タワー'];
        yield '連続する半角スペース' => ['東京  タワー', '東京 タワー'];
        yield '全角と半角の混在' => ['a　b c 　d', 'a b c d'];
        yield 'スペースの無い検索語' => ['東京タワー', '東京タワー'];
        // スペース以外の全角文字(英数字・記号)は変えない
        yield '全角の英数字' => ['Ｔｏｋｙｏ　２０２６', 'Ｔｏｋｙｏ ２０２６'];
    }

    /** 検索のクエリの`s`を正規化し、検索語が残る場合は`post__in`を変えない。 */
    #[DataProvider('searchTerms')]
    public function testNormalizesSearchTerm(string $search, string $expected): void
    {
        $vars = $this->normalize(true, ['s' => $search]);

        self::assertSame(['s' => $expected], $vars);
    }

    /** @return iterable<string, array{string}> */
    public static function whitespaceOnlyTerms(): iterable
    {
        yield '全角スペース1つ' => ['　'];
        yield '全角スペースの連続' => ['　　'];
        yield '半角スペースだけ' => ['  '];
        yield '全角と半角の混在' => [' 　 '];
    }

    /**
     * 全角・半角のスペースだけの検索語は、`s`を空文字にし、`post__in`を`[0]`にして0件にする(空文字の`s`だけでは
     * WordPressが全件を返すため)。
     */
    #[DataProvider('whitespaceOnlyTerms')]
    public function testWhitespaceOnlySearchTermFindsNothing(string $search): void
    {
        $vars = $this->normalize(true, ['s' => $search]);

        self::assertSame(['s' => '', 'post__in' => [0]], $vars);
    }

    /** @return iterable<string, array{bool, array<string, mixed>}> */
    public static function untouchedQueries(): iterable
    {
        yield '検索でないクエリ' => [false, ['s' => '東京　タワー']];
        yield '空の検索語' => [true, ['s' => '']];
        yield '文字列でない検索語' => [true, ['s' => ['東京　タワー']]];
        yield '検索語の無い検索' => [true, []];
    }

    /**
     * 検索でないクエリ、空・文字列でない検索語は、何も変えない。
     *
     * @param array<string, mixed> $vars
     */
    #[DataProvider('untouchedQueries')]
    public function testLeavesOtherQueriesUntouched(bool $isSearch, array $vars): void
    {
        self::assertSame($vars, $this->normalize($isSearch, $vars));
    }

    /**
     * 代役のWP_Queryでnormalize()を呼び、呼んだ後のクエリの変数を返す。
     *
     * @param array<string, mixed> $vars 呼ぶ前のクエリの変数
     *
     * @return array<string, mixed>
     */
    private function normalize(bool $isSearch, array $vars): array
    {
        $this->loadWpQueryDouble();
        $query = new WP_Query();
        $query->is_search = $isSearch;
        $query->query_vars = $vars;

        (new FullwidthSearchNormalizer())->normalize($query);

        $result = [];

        foreach ($query->query_vars as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * WP_Queryの代役(WordPressと同じく、is_search()は`$is_search`を返し、get()は無い変数に既定値を返し、set()は
     * `$query_vars`に書く)を、テスト用の一時ファイルに書いて読み込む。ほかのテストにWP_Queryのクラスを残さないよう、
     * このクラスのテストは別プロセスで動かす。
     */
    private function loadWpQueryDouble(): void
    {
        $file = $this->createTempDirectory('wp-query-double') . '/WP_Query.php';
        self::assertNotFalse(file_put_contents($file, <<<'PHP'
<?php

declare(strict_types=1);

final class WP_Query
{
    /** @var array<string, mixed> */
    public $query_vars = [];

    /** @var bool */
    public $is_search = false;

    public function is_search(): bool
    {
        return $this->is_search;
    }

    public function get(string $query_var, mixed $default_value = ''): mixed
    {
        return $this->query_vars[$query_var] ?? $default_value;
    }

    public function set(string $query_var, mixed $value): void
    {
        $this->query_vars[$query_var] = $value;
    }
}
PHP));

        require $file;
    }
}
