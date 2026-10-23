# 07. View / Support / Log 設計書

## 目的

TMSWP コアのうち、テンプレート読み込みヘルパ(`View\Template`)、テンプレート前処理の明示呼び出しの規約、汎用ユーティリティ(`Support\Arr` / `Support\Str` / `Support\Html`)、ロギング基盤(`Log\Log` / `Log\LoggerRegistry` / `Log\SimpleLogger` / `tms_log()` ヘルパ)の完全仕様を定義する。

本章のコンポーネントは「WordPress 標準のテーマ構築手法を一切壊さない」絶対原則(要件3)の最前線である。テンプレート層には特殊処理を持ち込まず、`get_template_part()` と同型の発想・WP 5.5+ の `$args` 受け渡し互換の発想だけで構成する。

## スコープ(対象パッケージ)

- `packages/core` (tmswp/core) のうち以下の名前空間:
  - `TMSWP\Core\View\` … `Template`, `TemplateLocator`
  - `TMSWP\Core\Support\` … `Arr`, `Str`, `Html`
  - `TMSWP\Core\Log\` … `Log`, `LoggerRegistry`, `LogChannel`, `ScopedLog`, `SimpleLogger`
  - `TMSWP\Core\Exception\` のうち本章が定義する例外: `TemplateNotFoundException`, `InvalidTemplateSlugException`, `UnknownLogChannelException`, `InvalidArgumentException`
  - グローバルヘルパ: `tms_log()` (`packages/core/src/functions.php`)
  - テンプレート前処理の明示呼び出しの規約(app 層の前処理クラス。FW のクラスは持たない。§1A)

ランタイム依存は `psr/log ^3` のみ(DR-14)。`Support\Arr` / `Support\Str` の大部分と `Log` のディスパッチロジックは WP 非依存の純 PHP とし、WP 関数呼び出し(`esc_attr` / `wp_date` / `wp_mkdir_p` / `get_stylesheet_directory` 等)は本章で明示する箇所に限定する。

## 依存文書

- Kernel/Discovery 設計書(`KernelConfig::theme()` / `KernelConfig::plugin()` のブートフロー)… 本章は統合要件として「プラグインブート時の `Template` フォールバックディレクトリ登録」「Kernel ごとの `LoggerRegistry` の生成と `Container` へのバインド」「`Kernel::forFile()` による記録先の Kernel の解決」を要求する(§1.6、§5.7)。
- フォーム設計書(`Form\FormHtml`)… `Support\Html::attributes()` の出力規約(先頭スペース規約、§4.2)に依存する。
- Meta/Option 設計書 … `Support\Arr::get()` のドット記法仕様を参照してよい。

---

# 設計本体

## 1. View\Template — テンプレートヘルパ

### 1.1 役割

`get_template_part()` の発想をそのままに、(1) プラグイン同梱テンプレートへのフォールバック、(2) `$args` 連想配列の明示受け渡し(`extract()` 禁止)、(3) 文字列キャプチャ(`render()`)、の3点だけを足した薄いヘルパ。`get_template_part()` と同じフック(`get_template_part_{$slug}` と `get_template_part`)を同じ引数・順序で発火するため、これらのフックに登録した処理は `Template::part()` でも動く(§1.5)。

**やらないこと(絶対原則の担保)**: `template_include` への介入、`exit`、グローバル変数の展開・注入、テンプレート階層の変更、独自テンプレート構文。テーマのテンプレートファイルは WP 標準のまま書ける。`Template` を一切使わないテーマ・プラグインも完全に動作する(オプトイン機能)。

### 1.2 クラスシグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\View;

use TMSWP\Core\Exception\InvalidTemplateSlugException;
use TMSWP\Core\Exception\TemplateNotFoundException;

/**
 * テンプレート読み込みの静的ファサード。
 * 実体は TemplateLocator(インスタンス・テスト可能)に委譲する二層構成(DR-09)。
 */
final class Template
{
    private static ?TemplateLocator $locator = null;

    /** 静的ユーティリティのためインスタンス化禁止 */
    private function __construct() {}

    /**
     * slug を検証し、get_template_part 系のフックを発火してから、テンプレートを解決して出力する(§1.5)。
     *
     * @param string               $slug 拡張子なしの相対パス(例: 'parts/card')
     * @param array<string, mixed> $args テンプレート内で $args として参照できる変数
     * @throws InvalidTemplateSlugException slug が §1.4 の形式違反のとき(フックは発火しない)
     * @throws TemplateNotFoundException 解決失敗時(フックの発火の後に解決し、見つからなければ投げる)
     */
    public static function part(string $slug, array $args = []): void;

    /**
     * part() と同じ解決を行い、出力を文字列で返す(メール本文等に利用)。
     *
     * @param array<string, mixed> $args
     * @throws TemplateNotFoundException
     */
    public static function render(string $slug, array $args = []): string;

    /** テンプレートが解決可能かを返す(任意パーシャルのガードに使う) */
    public static function exists(string $slug): bool;

    /** 解決済み絶対パスを返す。未解決なら null(例外は投げない) */
    public static function locate(string $slug): ?string;

    /** 共有 TemplateLocator を返す(未生成なら遅延生成) */
    public static function locator(): TemplateLocator;

    /** Locator の差し替え(テスト用・Kernel 統合用)。null で初期化状態に戻す */
    public static function setLocator(?TemplateLocator $locator): void;
}
```

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\View;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidTemplateSlugException;

/**
 * slug → 絶対ファイルパスの解決器。状態は フォールバックディレクトリのリストと
 * リクエスト内解決キャッシュのみ。WP 関数依存: get_stylesheet_directory / get_template_directory。
 */
final class TemplateLocator
{
    /** @var list<string> プラグイン同梱テンプレートのベースディレクトリ(登録順) */
    private array $fallbackDirs = [];

    /** @var array<string, string|null> slug => 解決済み絶対パス(null=未発見)のキャッシュ */
    private array $resolved = [];

    /**
     * フォールバックディレクトリを末尾に追加し、解決キャッシュを破棄する(プラグインの Kernel ブートが呼ぶ)。
     * 末尾の `/`・`\` を除いて比較し、同一パスの重複登録は無視する。ディレクトリ非実在は登録時には検査しない。
     * @throws InvalidArgumentException 末尾の `/`・`\` を除いた結果が空文字になるパス(`''`・`'/'` 等)のとき
     */
    public function addFallbackDir(string $dir): void;

    /** @return list<string> 登録済みフォールバックディレクトリ(登録順) */
    public function fallbackDirs(): array;

    /**
     * slug が §1.4 の形式の規則に合うかを確かめる(Template::part() がフックの発火の前に呼ぶ)。
     * @throws InvalidTemplateSlugException slug が §1.4 の形式違反のとき
     */
    public function assertSlug(string $slug): void;

    /**
     * slug を絶対パスへ解決する。未発見なら null。
     * @throws InvalidTemplateSlugException slug が §1.4 の形式違反のとき
     */
    public function locate(string $slug): ?string;

    public function exists(string $slug): bool;

    /** 解決キャッシュを破棄する(テスト・テーマ切替時用) */
    public function flush(): void;

    /**
     * §1.3 のステップ 3〜5 の候補パスを探索順に返す(TemplateNotFoundException のメッセージに使う)。
     * @return list<string>
     * @throws InvalidTemplateSlugException slug が §1.4 の形式違反のとき
     */
    public function candidatePaths(string $slug): array;
}
```

### 1.3 解決アルゴリズム(確定仕様)

`TemplateLocator::locate($slug)` は次の順で `{$slug}.php` を探し、最初に存在したファイルの絶対パスを返す。

1. slug を §1.4 の規則で検証(違反 → `InvalidTemplateSlugException`)。
2. 解決キャッシュにあればそれを返す(null 含む)。
3. `rtrim(get_stylesheet_directory(), '/\\') . DIRECTORY_SEPARATOR . $slug . '.php'`(子テーマ)
4. `rtrim(get_template_directory(), '/\\') . DIRECTORY_SEPARATOR . $slug . '.php'`(親テーマ。子=親なら本ステップはスキップ)
5. 登録済み `fallbackDirs`(登録時に末尾の `/`・`\` を除いたもの)を登録順に `$dir . DIRECTORY_SEPARATOR . $slug . '.php'`
6. すべて不在なら null。結果(null 含む)をキャッシュして返す。

ディレクトリと slug の間の区切りは `DIRECTORY_SEPARATOR` で、slug の中の区切りは `/` のまま連結する。3〜5 の候補パスは `candidatePaths()` が返す順と同じである。

存在判定は `is_file()`。テーマが常に最優先であるため、**プラグイン同梱テンプレートはテーマ側に同名ファイルを置くだけで上書きできる**(WooCommerce 方式の一般化)。プラグインは自身のテンプレートを `templates/{plugin-slug}/...` に置き、slug を `{plugin-slug}/...` で始めることを規約とする(rules/ に明記)。これによりテーマ側オーバーライドパスは `{テーマ}/{plugin-slug}/...` となり、プラグイン間の slug 衝突も構造的に回避される。

複数プラグインが同一 slug を解決できる場合は **登録順(=ブート順)で先勝ち**。これは上記規約に従う限り発生しない。

`get_stylesheet_directory()` / `get_template_directory()` の呼び出し結果はキャッシュエントリに織り込まれるため、リクエスト途中の `switch_theme` には追随しない(WP 通常運用では発生しない。テストでは `flush()` を呼ぶ)。

### 1.4 slug の形式規則

- 正規表現 `/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*\z/` に一致すること。
- すなわち: 空文字・先頭/末尾の `/`・`..` セグメント・`.php` 等の拡張子・バックスラッシュ・NUL バイト・ドットを含む slug はすべて不可。
- 違反時は `InvalidTemplateSlugException`(メッセージに slug 原文を含める)。ユーザー入力を slug に使うことは想定しない(rules/ で禁止を明記)が、この検証によりパストラバーサルは構造的に不可能となる。

### 1.5 part() / render() の振る舞い仕様

`Template::part()`:

1. `self::locator()->assertSlug($slug)` で slug を検証する。§1.4 の形式違反なら `InvalidTemplateSlugException` を投げる(不正な slug からフックの名前を作らないため、フックは発火しない)。この時点ではテンプレートを解決しない。
2. WordPress 7.0 の `get_template_part()` と同じフックを、同じ引数・順序で発火する。`Template::part()` は `$name` を持たないため、`get_template_part($slug, null, $args)` と同じ引数になる。

   | 順 | フック | 引数 |
   |---|---|---|
   | 1 | `get_template_part_{$slug}` | `$slug`, `null`(`$name`), `$args` |
   | 2 | `get_template_part` | `$slug`, `''`(`$name` を文字列にしたもの), `["{$slug}.php"]`(`$templates`), `$args` |

   `get_template_part()` と同じく、フックはテンプレートの解決の前に発火する。フックのコールバックが用意したテンプレートも、3 の解決で見つかる。ただし、同じ slug をそれより前に解決して未発見だった場合(`exists()` 等)は、§1.3 のキャッシュの null が返る。`load_template()` を通さないため、`load_template()` が発火する `wp_before_load_template` / `wp_after_load_template` は発火しない。
3. `self::locator()->locate($slug)` で解決する(§1.3)。
4. 3 の結果が null なら **常に** `TemplateNotFoundException` を投げる(本番でも握り潰さない。失敗の明示は DR-04 と同じ思想)。例外メッセージには slug と探索した全候補パスを含める。
5. 解決したファイルを **隔離スコープ** で include する。include するクロージャはどのクラスのスコープにも束縛せず(`Closure::bind($closure, null, null)`)、テンプレートは `Template` の private なプロパティ・メソッドに触れられない。`$this` も束縛しない。テンプレートから見える変数は `$args` のみ。擬似コード:

```php
private static function includeFile(string $file, array $args): void
{
    $include = Closure::bind(
        static function (array $args): void {
            // $args には [パス, 引数] が来る。分割代入で $args を引数に置き換え、
            // 代入式の値(元の配列)の [0] からパスを取り出す。パスを変数に置かない
            include ([, $args] = $args)[0];
        },
        null,   // $this を束縛しない
        null,   // クラスのスコープに束縛しない
    );

    $include([$file, $args]);
}
```

- `extract()` は使用しない。テンプレート内は `$args['post']` のように参照する(WP 5.5+ の `get_template_part( $slug, $name, $args )` と同じ書き味)。
- 任意パーシャルは呼び出し側で `Template::exists()` を使ってガードする(`part()` 自体に「存在すれば読む」モードは設けない)。

`Template::render()`:

```php
// 擬似コード。出力バッファは例外時も必ず解放する
ob_start();
try {
    self::part($slug, $args);
} catch (\Throwable $e) {
    ob_end_clean();
    throw $e;
}
return (string) ob_get_clean();
```

`Template::locator()` は `self::$locator ??= new TemplateLocator()` の遅延生成。プロセス内で単一の解決スタックを共有する(テーマ+複数プラグインが同一リクエストで boot しても locator は1つ)。

### 1.6 Kernel との統合要件(統合点)

- `KernelConfig::plugin(file: __FILE__, ...)` でブートした Kernel は、`dirname($file) . '/templates'` がディレクトリとして存在する場合、ブート処理中に `Template::locator()->addFallbackDir()` を呼ぶこと。
- `KernelConfig::theme(...)` ではフォールバック登録を行わない(テーマはステップ 3/4 で常に解決される)。
- Kernel 文書側にこの統合要件を反映すること(open question として申し送り)。

---

## 1A. テンプレート前処理の明示呼び出し

### 1A.1 役割

テンプレート単位の前処理(表示する値の用意・フォームの駆動・PRG リダイレクト)は、テンプレートの先頭で型付きの前処理クラスを明示的に呼んで行う(DR-19)。前処理クラスは app 層のクラスであり、FW は前処理のための基底クラス・属性・フック登録・値の受け渡しの機構を持たない。テンプレートの選択は WordPress 標準のテンプレート階層のままで、Kernel は `template_include` 等のテンプレートのフックに何も登録しない(02-kernel §3.7、原則1)。

- どのテンプレートで何が実行されるかを、テンプレートのファイルだけで追える。フックでテンプレートを判定して前処理を実行しない。
- テンプレートが使う値の型は、前処理クラスのプロパティの型で PHPStan が検査する。テンプレートで `@var` を書いて型を補わない。

### 1A.2 前処理クラスの規約

```php
<?php

declare(strict_types=1);

namespace App\Page;

use App\Form\ContactForm;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\FormStep;

/** page-contact.php の前処理: お問い合わせフォームを駆動し、テンプレートが使う値を返す */
final readonly class ContactPage
{
    private function __construct(
        public Form $form,
        public FormResult $result,
    ) {}

    public static function handle(): self
    {
        $form = ContactForm::create();
        $result = $form->handle();

        if ($result->step === FormStep::Complete) {
            wp_safe_redirect(home_url('/contact/thanks/'), 303);
            exit;   // 00-overview.md 原則1の例外(PRGのリダイレクト直後)
        }

        return new self($form, $result);
    }
}
```

| 項目 | 規約 |
|---|---|
| 置き場 | テーマの `app/Page/<Name>Page.php`。テンプレート1つにつき1クラス。前処理の要らないテンプレートには作らない |
| クラス | `final` で、コンストラクタは `private`。テンプレートが使う値を readonly のプロパティで宣言する(`final readonly class`、または `final class` と `public readonly` のプロパティ) |
| 入口 | `public static function handle(): self`。前処理を行い、用意した値を `new self(...)` で返す |
| 入力 | WordPress がテンプレートを選んだ時点のメインクエリ(`get_queried_object()` 等)とリクエスト。表示する投稿を別の投稿に差し替えない |
| 出力 | 戻り値の DTO だけ。`echo` 等の出力・グローバル変数への代入をしない |
| リダイレクト | PRG の `wp_safe_redirect()` の直後に限り `exit` してよい(00-overview 原則1の例外) |
| 404 | 前処理で `$wp_query->set_404()` を呼んで 404 に切り替えない。テンプレートの選択後では、ステータス行とテンプレートが食い違う。404 かどうかは WordPress のメインクエリに任せる |
| エラー | 想定外のテンプレートから呼ばれた等の呼び出しの誤りは例外にする。データが無いこと(関連記事が0件等)はエラーにせず、空の値を DTO に入れる(00-overview §5.6) |

### 1A.3 テンプレートからの呼び出し

テンプレートの先頭、`get_header()` より前で1回だけ呼び、以降は戻り値のプロパティを読む。出力の前のため、リダイレクトとページキャッシュからの除外のヘッダ(05-form)が効く。

```php
<?php
/* Template Name: お問い合わせ */

declare(strict_types=1);

use App\Page\ContactPage;

$vm = ContactPage::handle();   // 出力の前に1回だけ呼ぶ

get_header();
// 以降は $vm->form / $vm->result を読む
```

### 1A.4 データフローの二層規約

| 層 | 経路 | 機構 |
|---|---|---|
| 第一層 | 前処理クラス → ページテンプレート | `handle()` が返す DTO(`$vm`)の型付きのプロパティ |
| 第二層 | ページテンプレート → パーツ | `Template::part($slug, $args)`(§1)か WP 標準の `get_template_part($slug, null, $args)` |

**禁止事項(追跡経路を1本に保つための規約)**:

- **パーツから `handle()` を呼ぶことは禁止**。フォームの駆動やリダイレクトの副作用があり、追跡経路も2本になる。パーツが必要とする値は、ページテンプレートが `$args` で明示的に渡し、パーツは冒頭で受け口を宣言する(`@var array{...} $args` か `wp_parse_args($args, [...])` のデフォルト付き受け口)。
- **パーツ内ループ変数に `$post` を使うことは禁止**。`load_template()` が `global $post` 等を展開するため、パーツのローカルで `$post` を上書きすると展開された global を壊す事故になる(独自名のループ変数を使う)。

### 1A.5 テンプレートを持たない文脈

プラグインはトップレベルのテンプレートを供給しないため、前処理クラスを使わない。プラグインのショートコード等、テンプレートの外でフォームを駆動する必要がある場合は、出力の前のフックで行う(05-form §5)。フックは `#[AsHook]` クラスの `#[Action]` メソッドで登録する(02-kernel §5)。

---

## 2. Support\Arr — 配列ユーティリティ

### 2.1 役割と収載基準

ドット記法による多次元配列アクセス。旧 `Iwf2b\Arr` で実利用が確認されたのは `get`(21箇所)/`set`/`exists` と、FW 内部オプション処理の `merge` / `merge_intersect_key`(9箇所)のみ。新 FW ではオプション処理を named arguments + readonly DTO に置き換えるため deep merge 系は収載しない。`delete` / `apply` / `implode` は利用実績ゼロまたは他クラス委譲のため廃止。

全メソッドは WP 非依存の純粋静的関数。入力配列を変更するのは `set()` のみ(参照渡しを明示)。

### 2.2 クラスシグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Support;

final class Arr
{
    private function __construct() {}

    /**
     * ドット記法で値を取得する。
     * 例: Arr::get($a, 'user.address.city', '')
     */
    public static function get(array $array, string|int $key, mixed $default = null): mixed;

    /** ドット記法でキーの存在を判定する(値が null でも存在すれば true) */
    public static function has(array $array, string|int $key): bool;

    /**
     * ドット記法で値を設定する。中間ノードが配列以外なら空配列で上書きする。
     */
    public static function set(array &$array, string|int $key, mixed $value): void;

    /**
     * 指定キーのみ抽出する(トップレベルのみ・ドット記法非対応)。
     * 存在しないキーは結果に含めない。元配列の出現順を維持する。
     *
     * @param list<string|int> $keys
     */
    public static function only(array $array, array $keys): array;

    /**
     * 先頭要素、または $predicate を最初に満たす要素を返す。
     * 該当なし・空配列なら $default。
     *
     * @param null|callable(mixed $value, string|int $key): bool $predicate
     */
    public static function first(array $array, ?callable $predicate = null, mixed $default = null): mixed;

    /**
     * 値を配列にラップする。null => []、配列 => そのまま、それ以外 => [$value]。
     */
    public static function wrap(mixed $value): array;
}
```

### 2.3 ドット記法の確定仕様

- `$key` が **int の場合はドット分解しない**(そのままトップレベルキーとして扱う)。
- `$key` が string の場合、`explode('.', $key)` でセグメント分解する。セグメントが数値文字列なら PHP の配列キー正規化に従う(`'10'` は int 10 と同一視される — `array_key_exists` の標準挙動)。
- データ側キーにリテラルの `.` を含む配列はドット記法では到達できない(サポート外。`only()` / 直接アクセスを使う)。
- `get()` / `has()` の走査途中で値が配列でなくなったら、それぞれ `$default` / `false` を返す。`ArrayAccess` オブジェクトの透過走査はしない(配列のみ)。
- `set()` の走査途中に非配列(スカラー等)があれば空配列で **上書き** する(旧 `Iwf2b\Arr::set` と同じ)。
- 旧 `Arr::get` の「キー配列を渡すと一括取得」ポリモーフィズムは廃止(`only()` で代替)。

---

## 3. Support\Str — 文字列ユーティリティ

### 3.1 役割と収載基準

旧 `Iwf2b\Text`(42参照・9プロジェクト)のうち実利用は `replace` / `stringify` / `truncate` / `convert_eol`、および FW 内部の命名規約変換(`convert_case`)。これらのみをキャメルケース API で収載する。`auto_link` は WP 標準 `make_clickable()` で代替(rules/ に明記)、`reverse_wpautop` / `short_hash` は廃止(署名 URL は thumbnail 拡張が `hash_hmac` を直接使う)。

`excerpt()` を除き全メソッドは WP 非依存・純粋。マルチバイト処理は UTF-8 前提で `mb_*` を使用する(`ext-mbstring` はコアの composer.json の `suggest` に記載する。01-architecture §3.2)。

### 3.2 クラスシグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Support;

final class Str
{
    private function __construct() {}

    /**
     * プレースホルダ置換。構文は {key} 固定(§3.2A)。
     * 値は Str::stringify() で文字列化し、strtr() の単一パスで置換する。
     * 例: Str::replace('【{site}】{name} 様', ['site' => $site, 'name' => $name])
     *
     * @param array<string, mixed> $vars
     */
    public static function replace(string $text, array $vars): string;

    /**
     * 文字数ベースの切り詰め(純関数・タグ除去はしない)。
     * mb_strlen($text) <= $length ならそのまま返す。超過時は mb_substr + $ellipsis。
     * $ellipsis は $length に含めない(旧 Text::truncate と同じ)。
     *
     * @throws \TMSWP\Core\Exception\InvalidArgumentException $length < 1 のとき
     */
    public static function truncate(string $text, int $length = 200, string $ellipsis = '…'): string;

    /**
     * 抜粋生成(WP 依存)。strip_shortcodes → wp_strip_all_tags($text, true) → truncate の合成。
     * 旧 Text::truncate の主用途(アーカイブの本文抜粋)はこちらが後継。
     */
    public static function excerpt(string $text, int $length = 200, string $ellipsis = '…'): string;

    /**
     * 任意値の決定的な文字列化(§3.3)。ログ・プレースホルダ置換・HTML属性値の共通基盤。
     */
    public static function stringify(mixed $value, string $glue = ', '): string;

    /** 改行コードを統一する(CRLF/CR/LF => $to) */
    public static function convertEol(string $text, string $to = "\n"): string;

    /** 'ProductPost' / 'product-post' => 'product_post' (ASCII のみ対象) */
    public static function snake(string $text): string;

    /** 'ProductPost' / 'product_post' => 'product-post' */
    public static function kebab(string $text): string;

    /** 'product_post' / 'product-post' => 'ProductPost' */
    public static function pascal(string $text): string;

    /** 'product_post' => 'productPost' */
    public static function camel(string $text): string;
}
```

### 3.2A replace() の確定仕様

- **プレースホルダ構文は `{key}` の1種類に固定**する(区切り文字の実行時パラメータ化は行わない)。PSR-3 プレースホルダ補間(§5.5)・`SimpleLogger` の `fileNameFormat`(§5.5)・フォーム検証メッセージ(05-form.md の「`{name}` 固定」)と FW 内で同一構文に統一するための設計判断であり、旧 `Iwf2b\Text::replace` の `%key%` 既定+`$bounds` 切替は採用しない(DR-03: 完全新規のため移行互換は不要)。
- 実装の正準形: `foreach ($vars as $key => $value) { $map['{' . $key . '}'] = self::stringify($value); } return strtr($text, $map);`
- **実装メモ**: `strtr()` はキーの最長一致・単一パスで置換するため、逐次 `str_replace` と異なり**置換値が別のプレースホルダを含んでも再置換されない**(再置換バグの構造的排除)。`'{level}/{date}'` 等の通常ケース(§5.5 の fileNameFormat)では逐次置換と出力は同一である。
- `{` / `}` をリテラルとして含むテキストのエスケープ手段は設けない(`$vars` に含まれないプレースホルダ風文字列はそのまま残る)。

### 3.3 stringify() の変換規則(確定・この順で評価)

| 入力 | 出力 |
|---|---|
| `null` | `''` |
| `true` / `false` | `'true'` / `'false'` |
| int / float / string | `(string)` キャスト |
| `\BackedEnum` | `(string) $value->value` |
| `\UnitEnum`(非 Backed) | `$value->name` |
| `\DateTimeInterface` | `$value->format(DATE_ATOM)` |
| `\Traversable` | `iterator_to_array($value, false)` して配列規則へ |
| array | 各要素を再帰 stringify し `implode($glue, ...)` |
| `__toString` を持つオブジェクト(`\Stringable`) | `(string)` キャスト |
| `\JsonSerializable` | `json_encode($value, JSON_UNESCAPED_UNICODE \| JSON_UNESCAPED_SLASHES)`(失敗時は次規則へ) |
| その他(Closure・resource 含む) | `'(' . get_debug_type($value) . ')'` 例: `'(Closure)'`, `'(resource (stream))'` |

再帰深度の上限は設けない(循環参照配列は PHP 標準の挙動に委ねる。値オブジェクト用途で循環は想定外)。

### 3.4 ケース変換の確定仕様

- 対象は ASCII 英数と `_` `-` のみ。マルチバイトはそのまま通す(変換保証外)。
- `snake()`: (1) 大文字の直前に `_` を挿入し小文字化 → (2) `-`/`_` の連続を `_` 1個に正規化 → (3) 先頭の `_` を除去。例: `'ABTest'` → `'a_b_test'`(連続大文字を頭字語として扱う特別処理はしない。決定性優先)。
- `kebab()`: snake と同じ手順で区切りを `-` にする。
- `pascal()`: `_`/`-` を空白化 → `ucwords` → 空白除去。
- `camel()`: `lcfirst(pascal($text))`。

### 3.5 convertEol() の確定仕様

- `strtr($text, ["\r\n" => $to, "\r" => $to, "\n" => $to])` の1回の走査で置換する。CRLF は1つの改行として扱い、置換後の `$to` に含まれる CR / LF は再び置換しない(`$to = "\r\n"` でも `"\r\n\n"` にならない)。

---

## 4. Support\Html — HTML 属性レンダラ

### 4.1 役割

`attributes(array): string` の **純関数1本** を中核とする属性レンダラ。`Form\FormHtml` の唯一の HTML 生成依存先であり、テンプレートでの直接利用も想定する。旧 `Iwf2b\Html` の流暢ビルダ(`__call`、約185行のデッドコード)は DR-16 により完全廃止。タグ自体の組み立て(`tag()`)も収載しない — タグはテンプレートに素の HTML として書く(絶対原則)。

エスケープは WP の `esc_attr()` を使用し、URL を値に取る属性には `esc_url()` を使用する(§4.3。本クラスは WP 境界。ユニットテストでは Brain Monkey でスタブする)。

### 4.2 クラスシグネチャと出力規約

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Support;

final class Html
{
    private function __construct() {}

    /**
     * 属性配列を HTML 属性文字列へ変換する。
     *
     * 出力規約(重要): 結果が空なら ''、非空なら **先頭に半角スペース1つ** を付けて返す。
     * 利用側は '<input' . Html::attributes($attrs) . '>' と連結するだけでよい
     * (FormHtml はこの規約に依存する)。
     *
     * @param array<int|string, mixed> $attributes
     * @throws \TMSWP\Core\Exception\InvalidArgumentException 属性名が §4.3 の形式違反のとき、
     *         または URL 属性の値が許可されないプロトコルのとき(§4.3 の8)
     */
    public static function attributes(array $attributes): string;

    /**
     * class 属性値の合成(clsx 風)。エスケープは行わない(attributes() 側で行う)。
     * 文字列 / 文字列リスト / [クラス名 => bool] の条件マップを混在して受け取れる。
     * 空白区切りで連結し、空要素除去・前後trim・初出優先で重複除去する。
     *
     * 例: Html::classes('btn', ['btn-lg', 'is-active' => $active]) // => 'btn btn-lg is-active'
     *
     * @param string|array<int|string, string|bool> ...$values
     */
    public static function classes(string|array ...$values): string;
}
```

### 4.3 attributes() の変換規則(確定・入力順を維持)

各エントリ `(key => value)` を入力順に処理し、結果トークンを半角スペースで連結する。

1. **属性名の決定**: key が int の場合、value が非空 string ならそれを属性名とする bare 属性(`['checked']` → `checked`)。それ以外(int キー+非 string 値)はスキップ。key が string ならそれが属性名。
2. **属性名の検証**: `/\A[a-zA-Z][a-zA-Z0-9:_.-]*\z/` に一致しなければ `TMSWP\Core\Exception\InvalidArgumentException` を投げる(属性名は開発者コード由来であり、握り潰すより即時失敗が AI フレンドリー)。
3. **`class` キーの特例**: value が string|array なら `self::classes($value)` を適用。結果が空文字なら属性ごと省略。
4. **null**: 常に属性ごと省略(`aria-*` 含む)。
5. **bool**:
   - 属性名が `aria-` で始まる場合: `true` → `aria-x="true"`、`false` → `aria-x="false"`(リテラル文字列としてレンダリング。`aria-pressed="false"` 等が意味を持つため)。
   - それ以外: `true` → bare 属性(`disabled`)、`false` → 省略。
6. **array(class 以外)**: 各要素を `Str::stringify` し空白で連結した値として扱う(`'data-ids' => [1,2]` → `data-ids="1 2"`)。
7. **その他のスカラー・オブジェクト**: `Str::stringify($value)` で文字列化。
8. **URL 属性**: 属性名を小文字にして `Html` の private 定数と照合し、一致すれば `esc_attr()` の代わりに `esc_url()` で値をエスケープする。
   - `Html::URL_ATTRIBUTES`(1つの URL を取る): `href` / `src` / `action` / `formaction` / `poster` / `cite` / `data` / `background` / `longdesc` / `manifest` / `codebase` / `icon` / `xlink:href`
   - `Html::URL_LIST_ATTRIBUTES`(空白区切りの URL のリストを取る): `ping`。空白で分割し、URL ごとに `esc_url()` を適用して半角スペース1つで連結する。
   - 空でない値が `esc_url()` で空文字になった場合(`javascript:` や `data:` など、`wp_allowed_protocols()` に無いプロトコル)は、空の属性として出力せず `TMSWP\Core\Exception\InvalidArgumentException` を投げる。
   - 値が空文字なら `esc_url()` を呼ばずに `name=""` として出力する(`Form::open()` の `action=""` 等)。
   - `esc_url()` は `/`・`#`・`?` で始まらずプロトコルも無い値に `http://` を補う(`images/a.png` は `http://images/a.png` になる)。相対パスはルートからのパスで渡す。
   - `srcset` など、URL に記述子が付く属性は対象外(`esc_attr()` でエスケープする)。
9. **値のレンダリング**: bare 属性は属性名のみ。URL 属性以外の値付きは `name="' . esc_attr($value) . '"`。値が空文字でも `name=""` として出力する(省略しない。`value=""` 等が意味を持つため)。
10. 全トークンを `' ' . implode(' ', $tokens)` で返す(トークンゼロなら `''`)。

旧 `HtmlTag::parse_attrs` の「standalone 属性ホワイトリスト(checked/disabled/...13種)」は廃止し、**値の型(bool)で bare/省略を制御する** 方式に統一する。`required` / `hidden` / `open` 等の新しい boolean 属性にもリスト更新なしで対応できる。

---

## 5. Log — ロギング基盤

### 5.1 構成と役割分担

| クラス | 役割 |
|---|---|
| `Log` | アプリのコード(テンプレートを含む)向けの静的ファサード。呼び出したファイルを含むルートの Kernel のレジストリへ委譲する。明示メソッドのみ(`__callStatic` 禁止規約に従う) |
| `LoggerRegistry` | 1つの Kernel のチャンネル管理+ディスパッチの実体。`Psr\Log\LoggerInterface` 実装。Kernel ごとに1つ作られ(`Kernel::logger()`)、その Kernel の Container に登録される |
| `LogChannel` | チャンネル1つの設定(ロガー・受理レベル・受理スコープ)の readonly 値オブジェクト。`KernelConfig` の `logChannels` で宣言する |
| `ScopedLog` | `scope()` が返すワンショットの不変ロガー(旧実装の可変 scope 状態を置換) |
| `SimpleLogger` | PSR-3 v3 のファイルロガー実装(依存ゼロ) |
| `tms_log()` | グローバルヘルパ。呼び出したファイルの Kernel の `LoggerRegistry` を返す(旧 `iwf_log()` と同型の使い味) |

すべて `psr/log ^3` のシグネチャ(`string|\Stringable $message`)に準拠する。レベルメソッド8種は `Psr\Log\LoggerTrait` を use して実装する(マジックメソッド不使用)。

**チャンネルは Kernel ごとに分かれる。** テーマと複数のプラグインがそれぞれ Kernel を boot するサイトでも、チャンネルの宣言は各 Kernel の `KernelConfig` に閉じ、他の Kernel の宣言で上書きされない(プロセス全体で1つのレジストリを差し替える API は持たない)。記録先の Kernel は次のとおり決まる。

- アプリのコードからの静的な呼び出し(`Log::error()` 等、`tms_log()`): 呼び出したファイルを含むルートディレクトリ(`KernelConfig::rootDir()` = appDir の親)を持つ Kernel(§5.2)。
- TMSWP の内部(`TMSWP\` の名前空間のクラス): 扱う Kernel を明示して、その `Kernel::logger()` に記録する(例: Kernel のフックメソッドの失敗とキャッチアップ、`Taxonomy::register()`・`UserRole::register()`、エンティティの `parents()`(エンティティクラスの `Kernel::forClass()`)、拡張)。テーマとプラグインで同梱する tmswp/core の版が別でも、クラスは先に読み込まれた側の1組だけが使われ、内部のクラスのファイルの場所では Kernel を決められないためである。

### 5.2 Log(静的ファサード)

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;

final class Log
{
    private function __construct() {}

    /**
     * 呼び出したファイルを含むルートディレクトリを持つ Kernel のレジストリ。tms_log() もこれを返す。
     * @throws \TMSWP\Core\Exception\KernelException 呼び出したファイルがどの Kernel のルートにも含まれないとき
     */
    public static function registry(): LoggerRegistry;

    /**
     * 登録済みチャンネルのロガーを直接取得する。
     * @throws \TMSWP\Core\Exception\UnknownLogChannelException 未登録チャンネル名のとき
     */
    public static function channel(string $channel): LoggerInterface;

    /**
     * スコープ付きワンショットロガー(不変 ScopedLog)を返す。Log::scope('mail')->error('...')
     * 常に自スコープで書くコンポーネントは戻り値を保持して使い回す(§5.3)。
     */
    public static function scope(string $scope): LoggerInterface;

    public static function debug(string|\Stringable $message, array $context = []): void;
    public static function info(string|\Stringable $message, array $context = []): void;
    public static function notice(string|\Stringable $message, array $context = []): void;
    public static function warning(string|\Stringable $message, array $context = []): void;
    public static function error(string|\Stringable $message, array $context = []): void;
    public static function critical(string|\Stringable $message, array $context = []): void;
    public static function alert(string|\Stringable $message, array $context = []): void;
    public static function emergency(string|\Stringable $message, array $context = []): void;

    /** @param string $level Psr\Log\LogLevel 定数値 */
    public static function log(string $level, string|\Stringable $message, array $context = []): void;
}
```

全メソッドは `self::registry()` への単純委譲。`Log` は状態を持たない(レジストリの差し替え API・チャンネル登録 API を持たない。チャンネルは `KernelConfig` で宣言する。§5.3)。

**記録先の Kernel の決め方**(`registry()`):

1. 呼び出したファイルを、`debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)` のフレームの関数の種類で決める(`@internal` の `Log\CallerFile::fromBacktrace()`)。パスの文字列は比べないため、区切り文字(`/`・`\`)やパスの表記に左右されない。
   - 各フレームは「どの関数が、どのファイル(`file`)から呼ばれたか」を表す。スタックの先頭から、`class` が `Log` のメソッドの呼び出しか、`class` が無く `function` が `tms_log` の呼び出しを表すフレームが続く間たどり、続いたフレームのうち最後のものの `file` を呼び出したファイルとする。
   - 続くフレームが無い、または最後のフレームに `file` が無い(`array_map([Log::class, 'error'], ...)` のように内部関数から呼ばれた)場合は、呼び出したファイルを決められないため `KernelException` を投げる。
   - `eval` したコードのフレームの `file` は `/path/x.php(12) : eval()'d code` の形(入れ子の `eval` では接尾辞が繰り返す)のため、接尾辞を除いて `eval` を書いたファイルのパスにする。PHP 8.3 以降で残る擬似的なファイル名は、ほかに `php -r` の `Command line code` と標準入力の `Standard input code` があるが、どちらも実在するファイルではないため、手順2の実パスの解決で `KernelException` になる。
   - クラスの無いテンプレートのファイルからの呼び出しも、同じくファイルで決まる。
   - `debug_backtrace()` の深さは制限しない(連なりが上限で途切れたのか、呼び出し元に達したのかを区別できなくなるため)。
2. `Kernel::forFile($file)` で、そのファイルの実パス(`realpath()`。取れなければ `KernelException`)を含むルートディレクトリ(`KernelConfig::rootDir()`。実パス。ルート + `DIRECTORY_SEPARATOR` で前方一致)を持つ Kernel を求め、その `logger()` を返す。テーマ形態の Kernel は、有効な子テーマのディレクトリ(`get_stylesheet_directory()` の実パス。親テーマと異なる場合だけ)もルートに含むため、子テーマのテンプレート(`Template::part()` が先に探す)からの呼び出しは親テーマの Kernel に記録される。ルートが入れ子になる場合(例: テーマの中にプラグインの Kernel のルートがある)は、最も長く一致するルートを採る(02-kernel §3.5)。
3. どの Kernel のルートにも含まれないファイルからの呼び出し(Kernel の boot 前の呼び出し、TMSWP を使わない他のプラグインからの呼び出し等)は、プログラムの誤りとして debug に関係なく `KernelException`(`file '{file}' is not inside the root directory of any booted TMSWP kernel`)を投げる。呼び出したファイルが分からない場合も `KernelException` を投げる。

ルートの判定は、ファイルとルートの両方を実パスにしてから行う。シンボリックリンクを経由したパスは実体の Kernel に解決され、`..` でルートの外へ出るパスは、文字列ではルートで始まっていても、実体の場所(ルートの外)で判定される。

**スコープ付与は不変 `ScopedLog`(§5.3)の1経路のみ**であり、既定スコープのような可変アンビエント状態は持たない(あるコンポーネントが設定した既定スコープが、同じ Kernel の他コンポーネントのスコープなしログ全てに波及する遠隔作用となる。スコープは常に明示引数として静的に追跡できる)。常に自スコープで書きたいコードは `$log = tms_log()->scope('shop');` の戻り値(`LoggerInterface` 実装)を保持して使い回す(DI 注入も可)。旧 `default_scope()` からの移行先としてこのレシピを rules/ とスターター `app/` スケルトンに明記する(申し送り)。

### 5.3 LoggerRegistry / LogChannel / ScopedLog

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

final class LoggerRegistry implements LoggerInterface
{
    use LoggerTrait;

    /** @var array<string, LogChannel> */
    private array $channels;

    /**
     * @param array<string, LogChannel> $channels チャンネル名 => 設定。空なら遅延デフォルトチャンネル(§5.4)
     * @throws \TMSWP\Core\Exception\InvalidArgumentException チャンネル名が空文字のとき
     */
    public function __construct(array $channels = []);

    public function has(string $channel): bool;

    /** @throws \TMSWP\Core\Exception\UnknownLogChannelException */
    public function channel(string $channel): LoggerInterface;

    public function scope(string $scope): LoggerInterface;

    /**
     * PSR-3 エントリポイント。scope = null として dispatch する。
     * スコープ付与は不変 ScopedLog(scope() の戻り値)の1経路のみ。
     * @param mixed $level
     */
    public function log($level, string|\Stringable $message, array $context = []): void;

    /**
     * ディスパッチ本体。ScopedLog からも呼ばれる(@internal 扱い)。
     * @param mixed $level
     * @throws \Psr\Log\InvalidArgumentException 未知のログレベルのとき(PSR-3 準拠)
     */
    public function dispatch(mixed $level, string|\Stringable $message, array $context, ?string $scope): void;
}
```

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;

/** チャンネル1つの設定。KernelConfig の logChannels で、チャンネル名をキーにして宣言する */
final readonly class LogChannel
{
    /** PSR-3 のログレベル(Psr\Log\LogLevel の8定数値) */
    public const array LEVELS = [/* debug, info, notice, warning, error, critical, alert, emergency */];

    /**
     * @param list<string> $levels 受理レベル(Psr\Log\LogLevel 定数値)。空=全レベル
     * @param list<string> $scopes 受理スコープ。空=全スコープ(スコープなしレコード含む)
     * @throws \TMSWP\Core\Exception\InvalidArgumentException $levels に不正レベル、$scopes に空文字が含まれるとき
     */
    public function __construct(
        public LoggerInterface $logger,
        public array $levels = [],
        public array $scopes = [],
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/** scope() が返す不変ロガー。状態を持たないため「ワンショット」が構造的に保証される */
final class ScopedLog implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private readonly LoggerRegistry $registry,
        private readonly string $scope,
    ) {}

    /** @param mixed $level */
    public function log($level, string|\Stringable $message, array $context = []): void;
    // 実装: $this->registry->dispatch($level, $message, $context, $this->scope);
}
```

### 5.4 dispatch() の確定仕様

1. **レベル検証**: `$level` が `Psr\Log\LogLevel` の8定数値(string)のいずれでもなければ `\Psr\Log\InvalidArgumentException`(PSR-3 §1.1 準拠)。
2. **遅延デフォルトチャンネル**: `$this->channels === []`(`KernelConfig` で `logChannels` を宣言していない Kernel)のとき、`default` チャンネルに `new LogChannel(new SimpleLogger())` を加えてから続行する。生成は最初の記録の時点まで遅らせる(`SimpleLogger` の生成が `WP_CONTENT_DIR` を要するため)。`logChannels` を宣言した Kernel では自動生成しない。ログを捨てたい Kernel は `Psr\Log\NullLogger` のチャンネルを宣言する。旧 FW で全15プロジェクトが完全コピペで行っていた `Log::set_logger(new SimpleLogger(), 'default')` 定型を不要にする。
3. **スコープ注入**: `$scope !== null` かつ `$context` に `_scope` キーが無ければ `$context['_scope'] = $scope` を追加する(ファイル上でスコープを追跡可能にする)。
4. **チャンネルマッチング**: 各チャンネルについて
   - `matchLevel = $channel->levels === [] || in_array($level, $channel->levels, true)`
   - `matchScope = $channel->scopes === [] || ($scope !== null && in_array($scope, $channel->scopes, true))`
   - 両方 true なら `$channel->logger->log($level, $message, $context)` を呼ぶ。
5. マッチするチャンネルがゼロでも例外は投げない(no-op)。

**旧挙動からの意図的変更**: 旧実装は「レコードにスコープが無い場合、scopes 制限付きチャンネルにもマッチする」だったが、新仕様では **scopes 制限付きチャンネルはスコープ一致時のみ受理** する(直感的なフィルタ動作に修正)。

構築時の検証: `LoggerRegistry` のチャンネル名が空文字 → `TMSWP\Core\Exception\InvalidArgumentException`。`LogChannel` の `$levels` の各値もレベル検証する(タイポの早期検出)。`$scopes` は任意文字列(検証なし、空文字のみ不可)。どちらも `KernelConfig` の `logChannels` を boot 中に呼んだ時点で投げるため、設定の誤りは debug に関係なく起動時に例外になる(00-overview §5.6)。

### 5.5 SimpleLogger

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * 依存ゼロのファイルロガー(PSR-3 v3)。
 * WP 境界: wp_date / wp_mkdir_p / wp_hash / wp_trigger_error を使用(ユニットテストでは Brain Monkey)。
 */
final class SimpleLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * @param string|null $dir 出力先ディレクトリ。null なら WP_CONTENT_DIR . '/tmswp-logs'
     * @param string $fileNameFormat 拡張子を除いたファイル名。{date} と {level} を展開し、/ はサブディレクトリになる
     * @param int $maxFiles ディレクトリごとに残す自分のファイル数。0 ならローテーションしない
     *
     * @throws \TMSWP\Core\Exception\InvalidArgumentException 引数が不正なとき、または dir を省略して WP_CONTENT_DIR が無いとき
     */
    public function __construct(
        ?string $dir = null,
        private readonly string $fileNameFormat = '{date}',
        private readonly string $dateFormat = 'Y-m-d H:i:s',
        private readonly int $maxFiles = 30,
    ) {}
    // コンストラクタは引数の検証、$dir の既定値の解決、末尾の区切り文字の除去だけを行う。
    // ディレクトリの作成は初回の書き込みまで遅らせる。
    // 次の場合は TMSWP\Core\Exception\InvalidArgumentException を投げる(設定の誤り):
    //   $maxFiles < 0、$fileNameFormat が空文字、$fileNameFormat が '..' のセグメントを含む、
    //   $dir を省略して WP_CONTENT_DIR が未定義。

    /** @param mixed $level */
    public function log($level, string|\Stringable $message, array $context = []): void;
}
```

#### 既定の出力先

`$dir` を省略したときの出力先は `WP_CONTENT_DIR . '/tmswp-logs'` であり、Web の公開領域にある。公開領域外を既定にしないのは、WordPress が書き込める非公開のディレクトリを決める手段を持たないためである。

- `ABSPATH` の親ディレクトリは、Bedrock のような構成(WordPress 本体を `web/wp/` に置く)では公開領域になる。
- 公開領域外のディレクトリは、ホスティングによっては `open_basedir` の外にあるか、PHP のユーザーから書き込めない。既定の出力先に書けないと、すべてのログが PHP の `error_log` へ回る(後述の書き込み手順)。

このため既定の出力先は公開領域に置いたまま、次の3つで URL からの取得を防ぐ。

1. ファイル名に `wp_hash()` 由来の接尾辞を付け、URL を推測できなくする(後述のファイル名)。
2. ベースディレクトリに `.htaccess`(`Require all denied`)を置く(Apache 2.4)。
3. ベースディレクトリとログファイルを置く各ディレクトリに `index.php`(`<?php // Silence is golden.`)を置き、ディレクトリの一覧表示を防ぐ。

2と3の保護は、Apache が次の2つを満たすときにだけ成り立つ。これを運用の前提とする。

- **`.htaccess` が読まれること**: ログのディレクトリに `AllowOverride None` が効いていると `.htaccess` は無視される。`Require` を使えるように `AllowOverride AuthConfig`(または `All`)が許可されている必要がある。
- **`index.php` が `DirectoryIndex` に含まれること**: 含まれず、`Options +Indexes` が有効だと、ディレクトリの一覧が表示されてファイル名が分かる。

前提を満たさない環境(nginx を含む。nginx は `.htaccess` を読まない)では、次のどちらかを行う(rules/ に明記する)。

- `dir:` 引数で公開領域外のディレクトリを指定する。
- サーバの設定で、ログのディレクトリへの直接の取得と一覧表示を拒否する(Apache の `<Directory>` で `Require all denied`、nginx の `location` で `deny all` 等)。

公開領域外に書ける環境では、前提を満たしていても `dir:` で公開領域外を指定することを推奨する。

#### ファイル名

ログファイルのパスは次のとおり。

```
{$dir}/{fileNameFormat の展開結果}-{wp_hash('tmswp-simple-logger:' . $fileNameFormat)}.log
```

- `fileNameFormat` は拡張子を含めない。`Str::replace($format, $vars)` で展開する(プレースホルダ構文は `{key}` 固定。§3.2A)。
- 接尾辞は `wp_hash()`(サイトの認証用ソルトによる HMAC)の16進文字列であり、サイトごとに異なり、外部からは計算できない。拡張子 `.log` は常に付ける。
- `wp_hash()` の入力に展開前の `fileNameFormat` 全体を含めるため、接尾辞は `fileNameFormat` ごとに異なる。ローテーションはこの接尾辞で自身のファイルを見分ける(後述)。

| プレースホルダ | 値 |
|---|---|
| `{date}` | `wp_date('Ymd')`(**WP タイムゾーン**。`current_time()` ではなく `wp_date()` を使う) |
| `{level}` | レベル文字列小文字(例 `error`) |

`/` を含むフォーマット(例 `'{level}/{date}'`)はサブディレクトリとして解釈し、書き込み時に `wp_mkdir_p(dirname($path))` する。`split_dir` のような bool オプションは設けず、フォーマットの指定だけで分ける。`..` のセグメントを含むフォーマットはコンストラクタで例外にする。

#### 書き込み手順

```
log($level, $message, $context):
  1. $level を8レベルで検証する(不正 => Psr\Log\InvalidArgumentException)
  2. $line = formatLine($level, $message, $context)            // 後述の行フォーマット
  3. $failure = writeToFile($level, $line)                     // 失敗した理由。成功なら null
  4. $failure !== null なら:
       error_log($line)                                        // PHP 標準のエラーログへ必ず出す
       初回の失敗だけ wp_trigger_error(SimpleLogger::class . '::log', 理由, E_USER_WARNING)

writeToFile($level, $line):
  1. wp_hash() が未定義なら失敗(pluggable.php の読み込み前。プラグインのファイルを読み込む間に書いた場合)
  2. ベースディレクトリを用意する: 無ければ wp_mkdir_p(失敗なら失敗)、書き込めなければ失敗、
     .htaccess と index.php が無ければ作成(作成できなければ失敗)
  3. $path を組み立てる(前述のファイル名)
  4. dirname($path) がベースディレクトリと異なれば、同様に用意する(index.php のみ)
  5. 既存のファイルに書き込めなければ失敗
  6. error_log($line . "\n", 3, $path) が false なら失敗
  7. 新規のファイルを作成し、$this->maxFiles > 0 ならローテーション(後述)。ローテーションが失敗したら:
       error_log(SimpleLogger::class . ': ログのローテーションに失敗しました。' . 理由)
       初回の失敗だけ wp_trigger_error(SimpleLogger::class . '::log', 同じメッセージ, E_USER_WARNING)
```

- 本番でエラー方針(00-overview.md §5.6)が Kernel のロガーの `error()` に頼るため、ファイルに書けない行も捨てずに PHP の `error_log` へ出す。
- `wp_trigger_error()` は WordPress 側で `WP_DEBUG` が偽なら何もしない。可視化は SimpleLogger のインスタンスごとに初回の失敗の1回だけとし(書き込みとローテーションの失敗で共通)、同じ原因で毎回通知しない。
- 書き込みの失敗で例外は投げない(ロガーがアプリの処理を止めない)。

#### 行フォーマット

```
{timestamp} [{LEVEL}] {message} {context_json} {exception}\n
```

- `{timestamp}` = `wp_date($this->dateFormat)`(WP タイムゾーン整合)。
- `{LEVEL}` = レベル大文字。
- `{message}` = `(string) $message` に対し PSR-3 プレースホルダ補間を適用: `{key}` 形式(`/\{([A-Za-z0-9_.]+)\}/`)を、`$context[key]` が scalar / null / `\Stringable` / `\BackedEnum` の場合のみ `Str::stringify` 値で置換する。補間済みキーも context JSON には残す(損失なし)。
- `{context_json}` = `$context`(`exception` キーの値が `\Throwable` ならそのキーを除く)を `json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)`。context が空なら本項とその前のスペースごと省略。
- `{exception}` = `$context['exception']` が `\Throwable` の場合に、previous のチェーンをたどって次の形で連ねる。`\Throwable` でなければ本項とその前のスペースごと省略。

```
{ExceptionClass}: {getMessage} in {getFile}:{getLine}
{getTraceAsString}
Caused by: {PreviousClass}: {getMessage} in {getFile}:{getLine}
{getTraceAsString}
```

- **1記録を必ず1行にする**: 組み立てた行の CR を `\r`、LF を `\n` の2文字に置き換えてから書く。メッセージ・context の値・例外のメッセージとトレースに含まれる改行で、別の記録に見える行を作れない。
- 末尾の改行は、ファイルへ書くときだけ付ける(`error_log($line)` へは付けない)。

#### ローテーション

新規ファイル作成時のみ実行(日付ファイルなら日に1回)。対象は **作成したファイルと同じディレクトリにある、このロガー自身のファイル** だけとする。自身のファイルは、`fileNameFormat` の最後のセグメントの `{date}` を8桁の数字、`{level}` を8レベルのいずれかとみなし、自身の接尾辞を使った `-{接尾辞}.log` で終わる名前に一致するもので判定する。

```
$entries = scandir(dirname($path))                 // false なら失敗
$files = $entries のうち、自身のファイル名のパターンに一致し、シンボリックリンクではない通常ファイル
                                                   // このロガーはディレクトリやリンクを作らないため、それらは自身のファイルではない
各 $file の filemtime を取得する                     // false なら失敗(何も削除しない)
if count($files) > $maxFiles:
    filemtime 昇順にソートし、古い方から (count - $maxFiles) 件を unlink
                                                   // false を返したファイルを失敗として集める
```

- ディレクトリの列挙には `glob()` を使わず `scandir()` を使う。`glob()` は出力先のパスの `[` `]` `*` `?` をパターンとして解釈し、出力先が `logs[ab]` なら隣の `logsa` のファイルまで対象にしてしまう。
- 同じディレクトリに `fileNameFormat` の異なる SimpleLogger があっても、接尾辞が異なるため互いのファイルを数えず、削除しない。名前の形が重なる場合(例 `'{level}-{date}'` と `'error-{date}'` の `error-20260613-….log`)も同じ。他のツールの `*.log` も対象にしない。
- 列挙・更新日時の取得・削除の失敗は、理由を PHP の `error_log` へ出し、初回だけ `wp_trigger_error()` で知らせる(前述の書き込み手順の7)。ログの行はすでにファイルへ書けているため、行は `error_log` へ出さない。

### 5.6 tms_log() ヘルパ

`packages/core/src/functions.php`(composer.json の `autoload.files` で読み込み)の全体:

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Log\Log;
use TMSWP\Core\Log\LoggerRegistry;

/**
 * 呼び出したファイルの Kernel のログのレジストリを取得する。Log::registry() への委譲。
 *
 *   tms_log()->info('imported', ['count' => $n]);
 *   tms_log()->scope('mail')->error('send failed', ['exception' => $e]);
 *   tms_log()->channel('audit')->info('user updated');
 *
 * @throws TMSWP\Core\Exception\KernelException 呼び出したファイルがどの Kernel のルートにも含まれない場合
 *
 * @return LoggerRegistry 呼び出したファイルを含むルートディレクトリを持つ Kernel のレジストリ(PSR-3 のロガー)
 */
function tms_log(): LoggerRegistry
{
    return Log::registry();
}
```

- ヘルパは `function_exists` ガードで囲まずに定義する。同名の関数が先に定義されていれば、PHP の「Cannot redeclare」で明示エラーになり、別の実装が黙って使われることはない。
- 同じファイルが二重に読み込まれることはない。Composer の `autoload.files` は、パッケージ名とパスから作る識別子(`md5('tmswp/core:src/functions.php')`)を `$GLOBALS['__composer_autoload_files']` に記録し、同じ識別子のファイルを1回だけ読む。テーマとプラグインがそれぞれの vendor/ に tmswp/core を持つ場合も識別子は同じになる。
- 戻り値は `LoggerRegistry`(`LoggerInterface` 実装)なので `->info()` / `->error()` 等がそのまま型安全に呼べる。旧 `iwf_log()->info(...)` の書き味は完全に維持される。
- 記録先の Kernel は `Log` と同じく呼び出したファイルで決まる(§5.2)。`tms_log()` を定義する functions.php のフレームは読み飛ばすため、`tms_log()` を呼んだファイルで決まる。
- PSR-3 v3 により `$message` は `string|\Stringable` のみ。旧 FW で見られた配列メッセージは `$context` に渡す(rules/ にレシピを明記)。

### 5.7 Kernel / Container との統合要件(統合点)

- Kernel は boot 中に、`KernelConfig::$logChannels` を呼んで自分の `LoggerRegistry` を1つ作り(`logChannels` が null なら空のレジストリ=遅延デフォルトチャンネル)、`Kernel::logger()` で公開する。クロージャの戻り値がチャンネル名(文字列)=> `LogChannel` の空でない配列でなければ、debug に関係なく `KernelException` を投げる(02-kernel §2)。
- Kernel は Container に `LoggerRegistry::class` をその Kernel のレジストリ(`Kernel::logger()` と同一インスタンス)としてバインドする。DI で受け取る `LoggerRegistry` と、その Kernel のルートの中のファイルから呼ぶ `Log` / `tms_log()` は同じレジストリになる。
- `Kernel::forFile(string $file)` は、`file` を含むルートディレクトリを持つ Kernel を最も長く一致するルートで返し、無ければ `KernelException` を投げる(02-kernel §3.5)。
- `logChannels` をクロージャで受け取るのは、`SimpleLogger` 等の `Psr\Log\LoggerInterface` を実装するクラスを、Kernel が psr/log の非互換を検査(02-kernel §3.8)した後で初めて読み込むためである。

---

## 6. 例外クラス(本章定義分)

すべて `declare(strict_types=1)`。マーカー interface `TmswpExceptionInterface` と FW の基底 `RuntimeException` は `02-kernel.md` §9 が定義する。

```php
namespace TMSWP\Core\Exception;

/** 引数検証エラーの共通例外で、FW の基底の1つ(本章では Str/Html/Log の検証に使用) */
class InvalidArgumentException extends \InvalidArgumentException implements TmswpExceptionInterface {}

/** Template の slug 形式違反 */
final class InvalidTemplateSlugException extends InvalidArgumentException {}

/** Template の解決失敗(メッセージに slug と探索候補全パスを含めること) */
final class TemplateNotFoundException extends RuntimeException {}

/** 未登録ログチャンネルの参照 */
final class UnknownLogChannelException extends \OutOfBoundsException implements TmswpExceptionInterface {}
```

- `InvalidArgumentException` は FW の基底なので継承を許す(`final` にしない)。他の3つは `final`。
- `UnknownLogChannelException` は SPL の `\OutOfBoundsException` を継承するため FW の基底を経由せず、マーカー `TmswpExceptionInterface` を直接実装する。これで本章の例外も `catch (TmswpExceptionInterface $e)` で捕まえられる(継承関係の全体は `02-kernel.md` §9)。

---

# 利用例(app 側コード)

## テーマテンプレートでのパーシャル描画

```php
<?php // archive-product.php (テーマ直下・WP 標準のテンプレート階層のまま)
use TMSWP\Core\View\Template;

get_header();

while (have_posts()) {
    the_post();
    Template::part('parts/product-card', ['post' => get_post(), 'featured' => false]);
}

get_footer();
```

```php
<?php // parts/product-card.php
/** @var array{post: \WP_Post, featured: bool} $args */
use App\Post\ProductPost;
?>
<article <?= \TMSWP\Core\Support\Html::attributes([
    'class' => ['product-card', 'is-featured' => $args['featured']],
    'data-id' => $args['post']->ID,
]) ?>>
    <h2><?= esc_html(get_the_title($args['post'])) ?></h2>
    <p><?= esc_html(number_format(ProductPost::price($args['post']))) ?>円</p>
</article>
```

## プラグイン同梱テンプレートとテーマオーバーライド

```
my-shop/                       # プラグイン
├── my-shop.php                # Kernel::boot(KernelConfig::plugin(...)) → templates/ が自動登録される
└── templates/
    └── my-shop/
        └── order-summary.php
```

```php
// プラグイン内・テーマ内のどこからでも:
Template::part('my-shop/order-summary', ['order' => $order]);
// 解決順: {子テーマ}/my-shop/order-summary.php
//        → {親テーマ}/my-shop/order-summary.php
//        → {プラグイン}/templates/my-shop/order-summary.php
```

## メール本文のキャプチャと任意パーシャル

```php
$body = Template::render('my-shop/mail/order-complete', ['order' => $order]);

if (Template::exists('parts/campaign-banner')) {
    Template::part('parts/campaign-banner');
}
```

## テンプレート前処理の明示呼び出し(T04 の前処理の相当)

旧 T04 は問い合わせ固定ページのテンプレートからロジックを分離し、フォームの3画面遷移を駆動していた。新 FW はテンプレートの先頭で型付きの前処理クラスを明示的に呼んで同等を表現する(§1A)。テンプレートは前処理クラスが返した値を読む以外、素の WP テンプレートのままである。

### A. 関連記事の準備(app/Page/EventSinglePage.php)

```php
<?php

declare(strict_types=1);

namespace App\Page;

use App\Post\EventPost;
use LogicException;
use WP_Post;

/** single-event.php の前処理: 関連イベント3件を用意する */
final readonly class EventSinglePage
{
    /** @param list<WP_Post> $related */
    private function __construct(
        public array $related,
    ) {}

    public static function handle(): self
    {
        $queried = get_queried_object();
        $event = $queried instanceof WP_Post ? EventPost::get($queried) : null;

        if ($event === null) {
            throw new LogicException('EventSinglePage::handle() must be called from the event single template.');
        }

        return new self(EventPost::query()->idNotIn($event->ID)->limit(3)->get());
    }
}
```

テンプレートは先頭で前処理クラスを呼び、戻り値のプロパティを読む。`single-event.php`:

```php
<?php
/* イベント詳細テンプレート。FW はテンプレート階層を一切変更しない */

declare(strict_types=1);

use App\Page\EventSinglePage;
use TMSWP\Core\View\Template;

$vm = EventSinglePage::handle();   // 出力の前に1回だけ呼ぶ

get_header();
?>
<main>
    <?php while (have_posts()) : the_post(); // 標準ループをそのまま使う ?>
        <?php the_title('<h1 class="event-title">', '</h1>'); ?>
        <?php the_content(); ?>
    <?php endwhile; ?>
</main>
<?php
// 第二層: パーツへは $args で明示的に渡す(パーツから handle() を呼ばない)
Template::part('parts/event-related', ['related' => $vm->related]);
get_footer();
```

パーツは冒頭で `$args` の受け口を宣言する。`parts/event-related.php`:

```php
<?php
/**
 * 関連イベント一覧パーツ。
 * @var array{related: list<\WP_Post>} $args
 */

declare(strict_types=1);
?>
<?php foreach ($args['related'] as $event) : // ループ変数に $post は使わない(load_template の global 展開上書き事故防止) ?>
    <a href="<?= esc_url((string) get_permalink($event)) ?>"><?= esc_html(get_the_title($event)) ?></a>
<?php endforeach; ?>
```

### B. フォーム3画面遷移

フォームの駆動と PRG リダイレクトも、前処理クラスの `handle()` で行う(§1A.2 の `ContactPage`)。テンプレートを含む完全な形は 05-form.md の利用例 §2A が定義する。

要点: いずれの例もテンプレートには `template_include` 介入・`extract()`・グローバル変数展開が一切なく、WP 標準テンプレート階層のままである。ロジックは前処理クラスに分離され、値は DTO の型付きのプロパティで渡る(旧 `load_global()` + `extract()` の暗黙渡しは持たない)。全テンプレートに共通する前処理は持たない(グローバルナビ用タームの取得等の共通ロジックは `app/Hook` クラス、またはパーツ内のモデルヘルパ直読みへ置く)。

## ロギング

```php
// 設定ゼロで動く(初回書き込み時に default チャンネル + SimpleLogger が自動登録される)
tms_log()->info('csv import finished', ['count' => $count]);

// 明示設定(functions.php の例): エラー以上を別ファイルへ、メール送信ログを分離。チャンネルはこの Kernel だけのもの
use Psr\Log\LogLevel;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Log\SimpleLogger;

Kernel::boot(KernelConfig::theme(
    appDir: __DIR__ . '/app',
    appNamespace: 'App',
    logChannels: static fn(): array => [
        'default' => new LogChannel(new SimpleLogger()),
        'errors' => new LogChannel(
            new SimpleLogger(fileNameFormat: 'errors/{date}'),
            levels: [LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY],
        ),
        'mail' => new LogChannel(new SimpleLogger(fileNameFormat: 'mail-{date}'), scopes: ['mail']),
    ],
));

// 利用側
try {
    $mailer->send($message);
} catch (\Throwable $e) {
    tms_log()->scope('mail')->error('mail send failed: {to}', ['to' => $to, 'exception' => $e]);
}

// 常に自スコープで書くコンポーネントは ScopedLog(不変・LoggerInterface 実装)を保持する(DI 注入も可)。
// 旧 default_scope() のような可変の既定スコープは存在しない(§5.2)
$log = tms_log()->scope('shop');
$log->info('order created', ['order_id' => $orderId]);
$log->error('stock shortage', ['sku' => $sku]);
```

## ユーティリティ

```php
use TMSWP\Core\Support\Arr;
use TMSWP\Core\Support\Str;

$city  = Arr::get($settings, 'shop.address.city', '');
$first = Arr::first($items, fn ($item) => $item['stock'] > 0);

$subject = Str::replace('【{site}】{name} 様のご注文', ['site' => get_bloginfo('name'), 'name' => $name]);
$lead    = Str::excerpt(get_the_content(), 120);
```

---

# 旧 iwf2b からの設計根拠(簡潔に)

- **View**: 旧 `Iwf2b\View` は `template_include` priority 100 での乗っ取り+`load_global()`(`$wp_query->query_vars` の全グローバル展開)+`extract()`+`exit` という4重の WP 標準破壊を抱えていた(DR-10/DR-16 で廃止確定)。実利用52参照のうちテンプレート分割の本質的価値は `View::element()`(T11 等のパーシャル描画)とテンプレート前処理(旧 `actions/` 規約)であり、前者は WP 5.5+ の `get_template_part($slug, $name, $args)` と同型の `Template::part($slug, $args)`(§1)として、後者はテンプレートの先頭で前処理クラスを明示的に呼ぶ規約(§1A)として再設計した。
- **テンプレート前処理(旧 `actions/` 規約)**: 旧 `actions/{テンプレート名}.php` 規約(T04・T05・T06 で15案件中に定型化)は、テンプレートからロジックを分離する現場価値が高い一方、その実現手段が上記 View 乗っ取り機構そのものであり、4つの問題(テンプレート階層複製・`$GLOBALS` 展開・`exit` バイパス・`load_global`)を抱えていた。本設計はこの価値(ロジック分離)だけを残し、テンプレートの先頭で型付きの前処理クラス(`$vm = ContactPage::handle();`)を明示的に呼ぶ形にした。理由は次の3つである: (1) 前処理の呼び出しがテンプレートに書かれるため、どのテンプレートで何が動くかをテンプレートのファイルだけで追える、(2) 値が DTO の型付きのプロパティで渡るため、テンプレートが読む値の型を PHPStan max で検査できる、(3) FW がテンプレートのフックに介入する機構を持たずに済み、WP 標準のテンプレート解決を一切変更しない(原則1)。全テンプレート共通の前処理は持たない(共通ロジックは `app/Hook` クラスまたはパーツ内モデルヘルパ直読みへ)。
- **Arr**: 実利用は `get`(21箇所)のドット記法アクセスが中核。deep merge 系(`merge`/`merge_intersect_key` 9箇所)は旧 FW の「options 連想配列」文化の産物であり、新 FW では named arguments + readonly 定義オブジェクト(`PostTypeDefinition` 等)が代替するため収載しない。
- **Str**: 旧 `Text` の実利用 4 メソッド+FW 内部で必要な命名変換のみ。`auto_link` は WP 標準 `make_clickable()`、`short_hash` は利用側の `hash_hmac` 直接利用(thumbnail 拡張)で代替。`truncate` は WP 依存(タグ除去)が混入していたため、純関数 `truncate` と WP 境界 `excerpt` に分離した。`replace` は旧 `Text::replace` の `$bounds` パラメータ(`%key%` 既定+区切り文字切替)を持たず、`{key}` 構文+`strtr()` 単一パスに固定する(§3.2A。FW 内のプレースホルダ構文を1種類に統一し、再置換バグを構造的に排除する)。
- **Html**: 旧流暢ビルダ(`__call`/`__get`/ノードコレクション約185行)は全プロジェクト参照ゼロの完全デッドコード(DR-16 で廃止確定)。実利用は `Html::tag()` 経由の属性レンダリングのみで、その実体だった `HtmlTag::parse_attrs` を純関数 `attributes()` として再定義。standalone 属性のホワイトリスト方式は HTML の進化に追随できないため bool 値駆動に変更した。
- **Log**: 全15プロジェクトがブートで同一の `Log::set_logger` 定型を書き、`iwf_log()` は300箇所で利用された最高頻度 API。チャンネル+levels/scopes フィルタの設計は実証済みのためそのまま踏襲し、(1) `__call`/`__callStatic` を明示メソッド+`LoggerTrait` に置換、(2) ワンショット scope の可変インスタンス状態(書き込み後にクリアする実装)を不変 `ScopedLog` に置換、(3) スコープなしレコードが scopes 制限チャンネルに漏れる旧マッチング規則を修正、(4) 定型ブートを遅延デフォルトチャンネルで不要化、(5) 旧 `default_scope()`(永続アンビエントスコープ)は撤廃し、スコープ付与を不変 `ScopedLog` の1経路に統一した(レジストリ上の可変既定スコープは他コンポーネントのスコープなしログ全てに波及する遠隔作用となるため。自スコープ常用は `$log = tms_log()->scope('shop')` の保持で代替)、(6) プロセス全体で1つのレジストリを後勝ちで設定する方式をやめ、チャンネルを Kernel ごとに `KernelConfig` で宣言して、呼び出したファイルの Kernel に記録する(テーマとプラグインの併用で、あとから boot した側の設定が他方のログの出力先を上書きしないため)。
- **SimpleLogger**: `current_time()` → `wp_date()` への更新(WP タイムゾーン整合の現行推奨 API)、ローテーション皆無 → `maxFiles` 導入、`WP_CONTENT_DIR/iwf2b-log/` が Web から素通しだった問題 → `.htaccess`/`index.php` 自動生成+公開領域外推奨の明文化。オプション連想配列+動的プロパティ代入(`$this->{$arg_key} = $arg_value`)は readonly プロパティ+named arguments に置換。

---

# 受け入れ基準

## チェックリスト

**View\Template**
- [ ] `Template::part('parts/card', ['post' => $post])` が子テーマ → 親テーマ → 登録順フォールバックディレクトリの順で `.php` を解決して include する
- [ ] テンプレート内で参照できる注入変数が `$args` のみであり、`$this` が束縛されていない(`extract()` 不使用をコードレビュー+静的解析で確認)
- [ ] 解決失敗時に `TemplateNotFoundException`(slug と全候補パスを含むメッセージ)が投げられる
- [ ] `..` / 先頭 `/` / `.php` 付き / 空文字の slug が `InvalidTemplateSlugException` になる
- [ ] `render()` がテンプレート内で例外が起きても出力バッファをリークしない(`ob_get_level` 不変のテスト)
- [ ] 同一 slug の2回目以降の解決がキャッシュから返る(`is_file` 呼び出し回数のテスト)
- [ ] プラグインの `templates/` 配下テンプレートがテーマ同名ファイルで上書きできる(統合テスト)

**テンプレート前処理**
- [ ] テーマ形態・プラグイン形態の Kernel が `template_include` にコールバックを登録しない(統合テスト。02-kernel §3.7)
- [ ] rules/page.md の前処理クラスとテンプレートの例が、rules/ のコード例の抽出・検査(前処理クラスは PHPStan max)を通る

**Support**
- [ ] `Arr::get/has/set` のドット記法がネスト3段・数値文字列キー・非配列中間ノードのケースを含むデータプロバイダで仕様どおり動作する
- [ ] `Str::replace` が `{key}` 構文を `strtr()` の単一パスで置換する(`$bounds` パラメータが存在しない・置換値に含まれる `{...}` が再置換されないテスト。§3.2A)
- [ ] `Str::stringify` が §3.3 の全行(enum / DateTimeInterface / Traversable / 再帰配列 / Closure 含む)を網羅するテストを持つ
- [ ] `Str::truncate` がマルチバイト文字列で文字数どおり切り詰める(バイト破断なし)
- [ ] `Html::attributes` が「空配列 → `''`」「非空 → 先頭スペース1つ」「`class` 条件マップ」「bool true/false/null」「`aria-*` の true/false 文字列化」「int キー bare 属性」「不正属性名 → 例外」を網羅するテストを持つ
- [ ] `Html::attributes` の値が必ず `esc_attr()` を通過する(`"` を含む値の出力テスト)。URL 属性(§4.3 の8)の値は `esc_url()` を通過し、許可されないプロトコルの URL は例外になる
- [ ] `Arr` / `Str`(`excerpt` を除く)が WP 関数を一切呼ばない(ユニットテストが Brain Monkey のスタブなしで通る)

**Log**
- [ ] `tms_log()->info('msg')` が設定ゼロで `WP_CONTENT_DIR/tmswp-logs/{Ymd}-{wp_hash 由来の接尾辞}.log` に `{timestamp} [INFO] msg` 形式で追記される(統合テスト)
- [ ] `KernelConfig` で `logChannels` を宣言した Kernel では遅延デフォルトチャンネルが生成されない
- [ ] テーマとプラグインの2つの Kernel(別のルート)のファイルから呼んだ `Log::error()` / `tms_log()` が、それぞれの Kernel のロガーに記録される。ルートが入れ子なら最も長く一致するルートの Kernel に記録される(統合テスト)
- [ ] 子テーマのファイルからの `Log::error()` が、親テーマの Kernel に記録される
- [ ] 呼び出したファイルの判定が、フレームの関数の種類(`Log` のメソッド・`tms_log()`)で行われ、パスの文字列に依存しない(Windows の形のパスのフレームでも、テーマの vendor の中の functions.php ではなく呼び出したファイルになる)。`eval` したコードからの呼び出しは `eval` を書いたファイルになり、内部関数から呼ばれた `Log` は `KernelException` になる
- [ ] 本番の遅延 boot のキャッチアップで動くアプリのコードからの `Log::error()` が、その Kernel に記録される
- [ ] どの Kernel のルートにも含まれないファイルからの `Log` / `tms_log()` の呼び出しが、ファイルのパスを含む `KernelException` になる
- [ ] TMSWP の内部のログ(フックメソッドの失敗・遅延 boot のキャッチアップ)が、扱う Kernel のロガーに記録される(統合テスト)
- [ ] `Log` にレジストリの差し替え・チャンネル登録の API(`set` / `setRegistry`)が無く、Container の `LoggerRegistry::class` が `Kernel::logger()` と同一インスタンスになる
- [ ] levels / scopes フィルタが §5.4 のマッチング規則(スコープなしレコードは scopes 制限チャンネルに届かない)どおり動作する
- [ ] `Log::scope('x')->error(...)` 後に別のログ呼び出しへスコープが残留しない(`ScopedLog` の不変性)
- [ ] `Log` / `LoggerRegistry` に既定スコープ系 API(`defaultScope` / `setDefaultScope`)が存在せず、スコープ付与経路が `ScopedLog` のみである(scope 指定なしの `log()` は常に scope = null で dispatch される)
- [ ] 未知レベルで `\Psr\Log\InvalidArgumentException`、未登録 `channel()` で `UnknownLogChannelException`
- [ ] `context['exception']` の Throwable が previous のチェーンを含めて、クラス名・メッセージ・file:line・スタックトレース付きで同じ行に追記される
- [ ] メッセージ・context・例外に含まれる CR / LF がエスケープされ、1記録が必ず1行になる
- [ ] `{key}` プレースホルダ補間が scalar/Stringable/BackedEnum/null の context 値でのみ行われる
- [ ] `maxFiles` 超過時に、同じディレクトリにある自身のファイルだけを数え、mtime の古いものから削除される(名前の形が重なるものを含む他の `fileNameFormat` のファイル、他の `*.log`、出力先のパスを glob のパターンとみなしたときに一致する隣のディレクトリのファイルは削除されない)/`maxFiles: 0` で削除されない
- [ ] ローテーションの列挙・更新日時の取得・削除に失敗したとき、理由が PHP の `error_log` へ出て、`wp_trigger_error` は初回の失敗の1回だけ呼ばれる
- [ ] ファイルに書き込めないとき(ディレクトリを作成できない・書き込めない・`wp_hash()` が未定義)、その行が PHP の `error_log` へ出て、`wp_trigger_error` は初回の失敗の1回だけ呼ばれる
- [ ] ベースディレクトリに `.htaccess`(`Require all denied`)と `index.php` が、ログファイルを置くサブディレクトリに `index.php` が生成される
- [ ] タイムスタンプと `{date}` が `wp_date()` 由来である(タイムゾーン `Asia/Tokyo` 設定の統合テストで UTC とずれることを確認)
- [ ] `Log` / `LoggerRegistry` / `ScopedLog` / `SimpleLogger` に `__call` / `__callStatic` が存在しない

## 検証コマンド(DR-12 共通)

```bash
composer lint      # php-cs-fixer: PSR-12 + declare(strict_types=1) 検査
composer analyse   # PHPStan level max・baseline なし(本章クラスはエラーゼロ)
composer test      # PHPUnit 11: ユニット(Arr/Str/Html/Log ディスパッチ = Brain Monkey)
                   #             統合(Template 解決順/SimpleLogger 実書き込み = wp-env + WordPress のテストライブラリ)
composer qa        # 上記すべて
grep -rn "extract(" packages/core/src/            # ヒット 0 件であること
grep -rn "compact(" packages/core/src/            # ヒット 0 件であること
grep -rn "template_include" packages/core/src/    # ヒット 0 件であること(テンプレート階層に介入しない。§1A)
grep -rn "template_redirect" packages/core/src/   # ヒット 0 件であること(乗っ取りなし)
grep -rn "__callStatic\|function __call(" packages/core/src/View packages/core/src/Support packages/core/src/Log  # 0 件
```
