# 07. View / Support / Log 設計書

## 目的

TMSWP コアのうち、テンプレート読み込みヘルパ(`View\Template`)、actions ディスパッチャ(`View\ActionRouter` / `View\View` / `View\NotFound` / `tms_var()` ヘルパ)、汎用ユーティリティ(`Support\Arr` / `Support\Str` / `Support\Html`)、ロギング基盤(`Log\Log` / `Log\LoggerRegistry` / `Log\SimpleLogger` / `tms_log()` ヘルパ)の完全仕様を定義する。

本章のコンポーネントは「WordPress 標準のテーマ構築手法を一切壊さない」絶対原則(要件3)の最前線である。テンプレート層には特殊処理を持ち込まず、`get_template_part()` と同型の発想・WP 5.5+ の `$args` 受け渡し互換の発想だけで構成する。

## スコープ(対象パッケージ)

- `packages/core` (tmswp/core) のうち以下の名前空間:
  - `TMSWP\Core\View\` … `Template`, `TemplateLocator`, `ActionRouter`, `View`, `NotFound`
  - `TMSWP\Core\Support\` … `Arr`, `Str`, `Html`
  - `TMSWP\Core\Log\` … `Log`, `LoggerRegistry`, `LogChannel`, `ScopedLog`, `SimpleLogger`
  - `TMSWP\Core\Exception\` のうち本章が定義する例外: `TemplateNotFoundException`, `InvalidTemplateSlugException`, `UnknownLogChannelException`, `InvalidArgumentException`
  - グローバルヘルパ: `tms_var()`, `tms_log()` (`packages/core/src/helpers.php`)

ランタイム依存は `psr/log ^3` のみ(DR-14)。`Support\Arr` / `Support\Str` の大部分と `Log` のディスパッチロジックは WP 非依存の純 PHP とし、WP 関数呼び出し(`esc_attr` / `wp_date` / `wp_mkdir_p` / `get_stylesheet_directory` 等)は本章で明示する箇所に限定する。

## 依存文書

- Kernel/Discovery 設計書(`KernelConfig::theme()` / `KernelConfig::plugin()` のブートフロー)… 本章は統合要件として「プラグインブート時の `Template` フォールバックディレクトリ登録」「`Container` への `LoggerRegistry` バインド」を要求する(§1.6、§5.7)。
- フォーム設計書(`Form\FormHtml`)… `Support\Html::attributes()` の出力規約(先頭スペース規約、§4.2)に依存する。
- Meta/Option 設計書 … `Support\Arr::get()` のドット記法仕様を参照してよい。

---

# 設計本体

## 1. View\Template — テンプレートヘルパ

### 1.1 役割

`get_template_part()` の発想をそのままに、(1) プラグイン同梱テンプレートへのフォールバック、(2) `$args` 連想配列の明示受け渡し(`extract()` 禁止)、(3) 文字列キャプチャ(`render()`)、の3点だけを足した薄いヘルパ。

**やらないこと(絶対原則の担保)**: `template_include` への介入、`exit`、グローバル変数の展開・注入、テンプレート階層の変更、独自テンプレート構文。テーマのテンプレートファイルは WP 標準のまま書ける。`Template` を一切使わないテーマ・プラグインも完全に動作する(オプトイン機能)。

### 1.2 クラスシグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\View;

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
     * テンプレートパーシャルを解決して出力する。
     *
     * @param string               $slug 拡張子なしの相対パス(例: 'parts/card')
     * @param array<string, mixed> $args テンプレート内で $args として参照できる変数
     * @throws TemplateNotFoundException 解決失敗時
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

    public function __construct() {}

    /**
     * フォールバックディレクトリを末尾に追加する(プラグインの Kernel ブートが呼ぶ)。
     * 同一パス(正規化後)の重複登録は無視。ディレクトリ非実在は登録時には検査しない。
     */
    public function addFallbackDir(string $dir): void;

    /** @return list<string> 登録済みフォールバックディレクトリ(登録順) */
    public function fallbackDirs(): array;

    /**
     * slug を絶対パスへ解決する。未発見なら null。
     * @throws InvalidTemplateSlugException slug が §1.4 の形式違反のとき
     */
    public function locate(string $slug): ?string;

    public function exists(string $slug): bool;

    /** 解決キャッシュを破棄する(テスト・テーマ切替時用) */
    public function flush(): void;
}
```

### 1.3 解決アルゴリズム(確定仕様)

`TemplateLocator::locate($slug)` は次の順で `{$slug}.php` を探し、最初に存在したファイルの絶対パスを返す。

1. slug を §1.4 の規則で検証(違反 → `InvalidTemplateSlugException`)。
2. 解決キャッシュにあればそれを返す(null 含む)。
3. `get_stylesheet_directory() . '/' . $slug . '.php'`(子テーマ)
4. `get_template_directory() . '/' . $slug . '.php'`(親テーマ。子=親なら本ステップはスキップ)
5. 登録済み `fallbackDirs` を登録順に `{$dir}/{$slug}.php`
6. すべて不在なら null。結果(null 含む)をキャッシュして返す。

存在判定は `is_file()`。テーマが常に最優先であるため、**プラグイン同梱テンプレートはテーマ側に同名ファイルを置くだけで上書きできる**(WooCommerce 方式の一般化)。プラグインは自身のテンプレートを `templates/{plugin-slug}/...` に置き、slug を `{plugin-slug}/...` で始めることを規約とする(rules/ に明記)。これによりテーマ側オーバーライドパスは `{テーマ}/{plugin-slug}/...` となり、プラグイン間の slug 衝突も構造的に回避される。

複数プラグインが同一 slug を解決できる場合は **登録順(=ブート順)で先勝ち**。これは上記規約に従う限り発生しない。

`get_stylesheet_directory()` / `get_template_directory()` の呼び出し結果はキャッシュエントリに織り込まれるため、リクエスト途中の `switch_theme` には追随しない(WP 通常運用では発生しない。テストでは `flush()` を呼ぶ)。

### 1.4 slug の形式規則

- 正規表現 `/\A[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*\z/` に一致すること。
- すなわち: 空文字・先頭/末尾の `/`・`..` セグメント・`.php` 等の拡張子・バックスラッシュ・NUL バイト・ドットを含む slug はすべて不可。
- 違反時は `InvalidTemplateSlugException`(メッセージに slug 原文を含める)。ユーザー入力を slug に使うことは想定しない(rules/ で禁止を明記)が、この検証によりパストラバーサルは構造的に不可能となる。

### 1.5 part() / render() の振る舞い仕様

`Template::part()`:

1. `self::locator()->locate($slug)` で解決。null なら **常に** `TemplateNotFoundException` を投げる(本番でも握り潰さない。失敗の明示は DR-04 と同じ思想)。例外メッセージには slug と探索した全候補パスを含める。
2. 解決したファイルを **隔離スコープ** で include する。テンプレートから見える変数は `$args` のみ。`$this` は束縛しない。擬似コード:

```php
private static function includeIsolated(string $file, array $args): void
{
    // $file は func_get_arg() 経由で渡し、テンプレートスコープに変数として漏らさない
    (static function (array $args): void {
        include func_get_arg(1);
    })($args, $file);
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

## 1A. View\ActionRouter — actions ディスパッチャ

### 1A.1 役割

WP のテンプレート階層が確定したあと、本描画が始まる前に、**テンプレートと同名の `actions/{テンプレート相対パス}.php` ファイル**でテンプレート単位のデータ準備(クエリ取得・フォーム駆動・404 判定)を行う規約を、**WP 標準テンプレートフローを一切壊さずに**提供するディスパッチャ。旧 iwf2b の `actions/{テンプレート名}.php` 規約(T04・T05・T06 で15案件中に定型化)を直接踏襲し、旧機構の4つの問題(テンプレート階層複製・`$GLOBALS` 展開・`exit` バイパス・`load_global`)を排した形である(DR-19)。

旧機構は `template_include`(priority 100)を乗っ取り、`load_global()` で `$wp_query->query_vars` をグローバル展開し、action ファイルを include し、`exit` で WP 標準ローダをバイパスしていた(DR-10/DR-16 で廃止確定)。`ActionRouter` はその4重の WP 標準破壊を排しつつ、「テンプレートからロジックを分離する」価値だけを残す。データの受け渡しは `extract()` でなく `View` 変数バッグ(§1B)経由とする。

**やらないこと(絶対原則の担保)**: `template_include` が受け取ったテンプレートの **差し替え・加工**(常に無加工で返す。404 強制時のみ WP 標準の `get_404_template()` へ差し替える)、`exit` による標準ローダのバイパス(actions が行う `exit` は `wp_safe_redirect` 直後の出力前リダイレクトのみ)、グローバル変数の展開・注入、`extract()`、独自テンプレート解決。`actions/` を1つも置かないテーマも完全に動作する(完全オプトイン)。

`actions/` ディレクトリは**テーマルート直下の `'actions'` 固定**(リネーム不可)。配置決定木・静的検査(`bin/tms-check`)・rules/ が無設定で同一前提を共有するための決定的規約である。

### 1A.2 生成と Kernel 統合(テーマ形態専用)

`ActionRouter` は **Kernel がテーマ形態でブートしたとき**(`KernelConfig::theme(...)`、かつ後述 `actions: true` のとき)に生成され、`template_include` フィルタ(priority 100)へ `dispatch` を登録する(Kernel 文書 §3.7)。`#[AsHook]` のような属性探索は使わない(actions はクラスでなくファイル規約であるため、Discovery の収集対象ではない)。

```
ActionRouter の生成・登録(Kernel boot 手順):
  if config.context !== KernelContext::Theme: return   // プラグイン形態では生成しない(下記)
  if config.actions !== true:        return            // KernelConfig::theme(actions: false) で無効化
  router = new ActionRouter()
  add_filter('template_include', [router, 'dispatch'], 100, 1)
```

- **`KernelConfig` の新パラメータ `actions: bool = true`**。テーマで actions 規約を使わない・別機構を使う場合は `KernelConfig::theme(actions: false)` で `ActionRouter` の生成自体を抑止できる。
- **priority 100** は「他プラグインの template フィルタ適用後の確定パスを見る」のに十分であり、9999 のような極端値による無用な順位競争を避ける(ADR-2 相当)。
- **プラグイン形態では `ActionRouter` を生成しない。** プラグインはトップレベルテンプレートを供給する機構を持たないため `template_include` への介入が意味を持たない。プラグイン側のテンプレート前処理に相当するロジックは `#[AsHook]` クラスへ書く。

### 1A.3 クラスシグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\View;

/**
 * actions ディスパッチャ。テンプレートと同名の actions/ ファイルを template_include で起動し、
 * 戻り値契約(array / NotFound / null)に従って View へ変数を積む。
 * Kernel がテーマ形態のとき生成し、template_include(priority 100)へ dispatch を登録する。
 */
final class ActionRouter
{
    /**
     * actions ディレクトリ名は 'actions' 固定(リネーム不可)。
     * 配置決定木・rules/ 文書・bin/tms-check が無設定で同じ前提を共有するための決定的規約。
     */
    private const ACTIONS_DIR = 'actions';

    /** 他プラグインの template フィルタ適用後の「確定パス」を見るための優先度(§1A.2) */
    public const PRIORITY = 100;

    public function __construct() {}

    /**
     * actions ファイルから 404 を強制するためのシグナル値を返す。
     * actions ファイルでは return \TMSWP\Core\View\ActionRouter::notFound(); と書く。
     */
    public static function notFound(): NotFound;

    /**
     * 確定したテンプレートパスに対応する actions ファイルを実行する。
     *
     * template_include の先行フィルタ(priority 100 より前)は false / null 等の
     * 非文字列を返し得る(コアは if ($template) で受けるだけ)ため、string 型宣言にせず
     * mixed 受け + 内部正規化とする。
     *
     * @param mixed $template 先行フィルタの戻り値(コア契約上は非文字列もあり得る)。
     * @return mixed 原則そのまま返す(404 強制時のみ 404 テンプレートに差し替え)。
     */
    public function dispatch(mixed $template): mixed;
}
```

### 1A.4 dispatch の正準手順(確定)

`template_include($template)` フィルタが発火したときの正準手順:

```
dispatch(mixed $template): mixed
  1. is_string($template) でない・空文字なら $template を素通しで返す
     (先行フィルタは false/null 等の非文字列を返し得るため mixed 受け+内部正規化)
  2. 確定テンプレートがテーマルート配下かを判定し、相対パスを得る:
       array_unique([get_stylesheet_directory(), get_template_directory()]) を子→親の順に走査し、
       str_starts_with($template, $root . '/') なら relative = substr($template, strlen($root) + 1)。
     どのテーマルート配下でもない(プラグイン由来等のテンプレート)は $template を素通しで返す
  3. {テーマルート}/actions/{relative} を子テーマ→親テーマの順に file_exists 探索し、
     最初に存在したものを採用する。無ければ $template を素通しで返す
     (完全オプトイン: actions を1つも置かないテーマも完全動作)
  4. static クロージャ内で include する(可視変数は $wp_query のみに固定):
       // ファイルパスは func_get_arg() 経由で渡し、actions スコープに変数として漏らさない
       // (Template::includeIsolated(§1.5)と同一イディオム)
       $result = (static function (\WP_Query $wp_query) {
           return include func_get_arg(1);
       })($GLOBALS['wp_query'], $action);
     → $this や Router 内部変数への偶発アクセス・上書きを構造的に遮断し、
        actions から見える変数が本当に $wp_query のみになる
  5. WP_DEBUG かつ !is_admin() のとき、使用 actions ファイルパスを wp_footer で
     HTML コメント出力する(子テーマ解決の無音スキップ調査用):
       printf("\n<!-- tms:action %s -->\n", esc_html(str_replace(ABSPATH, '', $action)));
  6. 戻り値契約(下記 §1A.5)で分岐:
       array            → View::set($result)(マージ)
       NotFound         → 404 強制(下記 §1A.6)し、404 テンプレートを返す
       null / int(1)    → no-op(int(1) は return 省略時の include 戻り値)
       それ以外         → WP_DEBUG 時のみ wp_trigger_error(E_USER_WARNING)で警告
  7. 404 強制でない全経路で $template を常に無加工で返す
     (WP 標準テンプレート解決を一切変更しない=絶対原則)
```

`int(1)` の特例: `include` は戻り値の `return` を省略したファイルに対し `int(1)` を返す。これを「`return` を書かない no-op」として許容するため、戻り値契約警告から除外する。

### 1A.5 戻り値契約(actions ファイルの return)

| actions の return | ActionRouter の挙動 |
|---|---|
| `array`(連想配列) | `View::set($result)` でマージ。テンプレート側は `tms_var('key')` / `View::get('key')` で読む |
| `\TMSWP\Core\View\ActionRouter::notFound()` | 404 を強制(§1A.6)。404 テンプレートへ差し替えて返す |
| `null`(明示)/ `return` 省略(`int(1)`) | no-op。`$template` を無加工で返す(データ準備不要・副作用のみの actions) |
| 上記以外(string・object 等) | WP_DEBUG 時のみ `wp_trigger_error(E_USER_WARNING)` で「戻り値は array / ActionRouter::notFound() / null のみ」と警告。`$template` は無加工で返す |

### 1A.6 404 強制の手順(確定)

`actions` が `ActionRouter::notFound()` を返したとき:

```
404 強制:
  1. $GLOBALS['wp_query']->set_404();
  2. headers_sent() が false のとき(=まだ出力開始前):
       status_header(404); nocache_headers();
     headers_sent() が true(出力開始済み)のとき:
       WP_DEBUG なら wp_trigger_error(__METHOD__,
         '出力開始後のため 404 ステータス行を上書きできません。先行プラグインの出力タイミングを確認してください。',
         E_USER_WARNING) で可視化する
       (status_header()/nocache_headers() は内部で headers_sent() を確認し送信済みなら黙って no-op するため、
        失敗を不可視にしないためのガード)
  3. $template404 = get_404_template();
     return $template404 !== '' ? $template404 : get_index_template();
     // 404.php 不在テーマでは get_404_template() が '' を返し、template-loader.php は
     // 空文字を include しないため白画面になる。get_index_template() へフォールバックして防止する
```

`wp_trigger_error()` は WP 6.4+ の標準 API(`_doing_it_wrong()` の後継。`E_USER_WARNING` を発する)を用いる。

### 1A.7 エッジケースとエラー時挙動(確定)

| 状況 | 挙動 |
|---|---|
| 非文字列の `$template`(先行フィルタが `false`/`null` 等) | 素通し。`dispatch` は関与しない(手順1) |
| テーマ外テンプレート(プラグイン由来等、テーマルート配下でない) | 素通し。テーマ形態 Router はテーマ配下の確定テンプレートのみ担当(手順2) |
| `actions/` ファイルが無い | 素通し。完全オプトイン(手順3) |
| 子テーマと親テーマの双方に同名 `actions` ファイル | 子テーマ優先(子→親の順で最初に存在したものを採用。手順3)。WP_DEBUG 時は採用ファイルを `wp_footer` の HTML コメントで可視化(手順5) |
| `actions` が `array` を返す | `View::set()` でマージ。複数 actions(本ディスパッチは1テンプレ1ファイルだが)・複数キーが暗黙に潰し合わない |
| `actions` が `return` を省略(副作用のみ) | `int(1)` を no-op として許容。`$template` 無加工 |
| `actions` が string 等を返す | WP_DEBUG 時のみ警告。`$template` 無加工(本番は黙って無視) |
| `actions` が `ActionRouter::notFound()` を返す | 404 強制(§1A.6)。`get_404_template()`(不在時 `get_index_template()`)を返す |
| 404 強制時に `headers_sent()` が true | `set_404()` は行うが status_header/nocache_headers は no-op。WP_DEBUG 時のみ `wp_trigger_error` で可視化(§1A.6) |
| `actions` 内で `wp_safe_redirect()+exit` | 許可。`template_include` は出力開始前のため安全(フォーム駆動の PRG リダイレクト等) |
| `actions` 内で `echo` 等の出力・フック登録・`$wp_query->set_404()` 直呼び | 規約違反。WP_DEBUG 検知+`bin/tms-check` 静的検査で検出(§1C・10-quality.md)。404 は必ず `ActionRouter::notFound()` で表明する |
| プラグイン形態の Kernel | `ActionRouter` を生成しない(§1A.2)。テンプレート前処理に相当するロジックは `#[AsHook]` へ |

---

## 1B. View\View — View 変数バッグ

### 1B.1 役割

`actions` → ページテンプレート間の**専用データチャネル**。変数の保持のみを責務とし、ビューユーティリティ(文字列加工・HTML 生成)は一切持たない(それらは `Support\Str` / `Support\Html`)。`ActionRouter::dispatch` が actions の戻り値 `array` を `set()` でマージし、テンプレートが `get()`(またはヘルパ `tms_var()`)で読む。

### 1B.2 クラスシグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\View;

/**
 * actions → ページテンプレート間の View 変数バッグ。変数の保持のみを責務とし、
 * 加工・出力機能は持たない。ActionRouter が set()、テンプレートが get()/tms_var() で読む。
 */
final class View
{
    /** @var array<string, mixed> */
    private static array $vars = [];

    /** 静的ユーティリティのためインスタンス化禁止 */
    private function __construct() {}

    /**
     * View 変数を登録する(既存キーとマージ。全置換ではない)。
     * 複数の書き込み元が暗黙に潰し合わないことを保証する。
     *
     * @param array<string, mixed> $vars
     */
    public static function set(array $vars): void;

    /**
     * View 変数を取得する。
     * 未定義キーをデフォルト指定なし($default === null)で読むと WP_DEBUG 時のみ
     * wp_trigger_error(E_USER_WARNING)で警告する(View 変数タイポの黙殺防止。
     * 書き込み側の戻り値契約警告と対になる読み出し側の検査)。
     * 意図的なデフォルト指定(View::get('x', []) 等)は警告しない。
     *
     * @param mixed $default 未定義時のデフォルト値。
     */
    public static function get(string $key, mixed $default = null): mixed;

    /** 変数が定義済みかを返す(値が null でも存在すれば true)。 */
    public static function has(string $key): bool;

    /** @internal テスト用。変数バッグを空にする。 */
    public static function reset(): void;
}
```

### 1B.3 get() の未定義キー警告(確定)

```
get(string $key, mixed $default = null): mixed
  if !array_key_exists($key, self::$vars):
      if $default === null && WP_DEBUG:
          wp_trigger_error(__METHOD__,
              "未定義の View 変数 '{$key}' を参照しました。actions 側の return キーを確認してください。",
              E_USER_WARNING)
      return $default
  return self::$vars[$key]
```

- 省略可能な変数は `View::get('key', $default)` とデフォルトを明示する規約(警告しない)。
- `wp_trigger_error()` は WP 6.4+ の標準 API を用いる(`_doing_it_wrong()` の後継)。
- これは**読み出し側のタイポ検出**であり、PHPStan max 方針(型の緩さの緩和策、§1D)の一部である。

### 1B.4 データフローの二層規約(DR-9 相当)

| 層 | 経路 | 機構 |
|---|---|---|
| 第一層 | actions → ページテンプレート | `View`(`return array` → `tms_var()` / `View::get()`) |
| 第二層 | ページテンプレート → パーツ | WP 標準の `get_template_part($slug, null, $args)` + `wp_parse_args` 受け口 |

**禁止事項(追跡経路を1本に保つための規約)**:

- **パーツから `View::get()` / `tms_var()` を読むことは禁止**。追跡経路が2本(View 経由とパーツ引数経由)になり規約が崩れる。パーツが必要とする値は、ページテンプレートが `get_template_part()` の `$args` で明示的に渡し、パーツは冒頭で `wp_parse_args($args, [...])` のデフォルト付き受け口を宣言する。
- **パーツ内ループ変数に `$post` を使うことは禁止**。`load_template()` が `global $post` 等を展開するため、パーツのローカルで `$post` を上書きすると展開された global を壊す事故になる(独自名のループ変数を使う)。

役割の整理: `View` クラス(本節)=第一層の変数バッグ(actions → ページテンプレート)、`Template::part()`(§1 の薄いテンプレートヘルパ)=第二層のパーツ描画(ページテンプレート → パーツ、`get_template_part` 同型)であり、両者は別物である。第二層は WP 標準の `get_template_part()` か `Template::part()` のいずれを使ってもよいが、いずれも値は `$args` で明示的に渡し、`View::get()`/`tms_var()` をパーツから読まない。

---

## 1C. View\NotFound — 404 シグナル値

意味を持たない空のマーカークラス。`ActionRouter::notFound()` 経由でのみ生成し、actions が 404 を要求したことを表明するシグナル値として使う。

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\View;

/**
 * actions ファイルが 404 を要求したことを示すシグナル値。
 * ActionRouter::notFound() 経由でのみ生成する。
 */
final class NotFound {}
```

actions では `$wp_query->set_404()` を直呼びせず、必ず `return \TMSWP\Core\View\ActionRouter::notFound();` で 404 を表明する(直呼びは `bin/tms-check` 静的検査+WP_DEBUG 検知で禁止。§1A.7・10-quality.md)。`ActionRouter::dispatch` がこのシグナル値を受け取って §1A.6 の 404 手順(`set_404` + ステータス行差し替え + 404 テンプレートフォールバック)を一括で行う。

---

## 1D. actions ファイルの規約と型の担保

### 1D.1 actions ファイルの規約

- **役割はデータ準備のみ**。`echo` 等の出力・フック登録・`$wp_query->set_404()` 直呼びは禁止(WP_DEBUG 検知+`bin/tms-check` 静的検査)。
- **戻り値はテンプレートで使う値を連想配列リテラルで `return` する**(`return ['posts' => $posts, 'form' => $form];` 等)。`compact()` は使わない: `extract()` 禁止と対称の規約であり、変数名の文字列契約を排して `'form' =>` と `tms_var('form')` が grep で1対1に対応し、PHPStan の array shape 推論も効く(リネーム安全・静的解析可能)。
- **404 は必ず** `return \TMSWP\Core\View\ActionRouter::notFound();` で表明する。
- **フォーム駆動・リダイレクト**(`wp_safe_redirect()` + `exit`)は許可(`template_include` は出力開始前)。
- ファイル冒頭の docblock に `@var \WP_Query $wp_query` を書く規約(可視変数 `$wp_query` の型を明示)。
- ファイルパスはテンプレートの**テーマ相対パスをミラー**する(例: `single-event.php` → `actions/single-event.php`、`parts/...` ではなくトップレベルテンプレート)。

### 1D.2 型の緩さの緩和策(PHPStan max 方針との整合)

actions/テンプレート/パーツは PHP ファイル include 規約であるため、変数の型がコードから追えない弱点がある。これを次の3点で担保する(クラスの `public readonly` プロパティ型に相当する型保証を、ファイル規約のまま得るための策)。

1. **テンプレート側の `@var` 注釈規約**: `View::get()` / `tms_var()` の戻り値は `mixed` であるため、テンプレートでは `/** @var \App\Form\ContactForm $form */` 形式の `@var` 注釈を書く規約を rules/ に明記する。
2. **`View::get` の未定義キー WP_DEBUG 警告**(§1B.3): 読み出し側のキー名タイポを実行時に検出する。
3. **軽量静的検査 `bin/tms-check`**(コア同梱・WP 非依存の素の PHP スクリプト)を `composer qa` へ組み込む。検査項目は次の3つ(検査仕様の正準定義は 10-quality.md):
   - (1) `actions/` 配下の `echo` / `print` 等の出力トークン
   - (2) 孤児 actions(対応する同名テンプレートが存在しない=テンプレート改名後に残った、無音で実行されなくなるファイル)
   - (3) `$wp_query->set_404()` 直呼び

### 1D.3 グローバルヘルパ tms_var()

`packages/core/src/helpers.php`(composer.json の `autoload.files` で読み込み):

```php
<?php

declare(strict_types=1);

use TMSWP\Core\View\View;

if (!function_exists('tms_var')) {
    /**
     * View 変数バッグへのグローバルアクセサ。View::get への1メソッド委譲。
     *
     * テンプレート側(@var 注釈で型を明示する規約。§1D.2):
     *   $form = tms_var('form');           // actions が return ['form' => $form] で用意
     *   $related = tms_var('related', []); // 省略可能な変数はデフォルトを明示(未定義警告を抑止)
     */
    function tms_var(string $key, mixed $default = null): mixed
    {
        return View::get($key, $default);
    }
}
```

- `function_exists` ガード付き(プラグイン+テーマで core が二重ロードされ得る環境対策。防御的に付与する)。
- `tms_` 接頭辞ヘルパ予約表(00-overview.md / AGENTS.md)に `tms_var` を追加する。
- 第二層(パーツ)からは呼ばない(§1B.4 の禁止事項)。

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

`excerpt()` を除き全メソッドは WP 非依存・純粋。マルチバイト処理は UTF-8 前提で `mb_*` を使用する(コアの composer.json に `ext-mbstring` を require する)。

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
- **実装メモ**: `strtr()` はキーの最長一致・単一パスで置換するため、逐次 `str_replace` と異なり**置換値が別のプレースホルダを含んでも再置換されない**(再置換バグの構造的排除)。`'{level}/{date}.log'` 等の通常ケース(§5.5 の fileNameFormat)では逐次置換と出力は同一である。
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

---

## 4. Support\Html — HTML 属性レンダラ

### 4.1 役割

`attributes(array): string` の **純関数1本** を中核とする属性レンダラ。`Form\FormHtml` の唯一の HTML 生成依存先であり、テンプレートでの直接利用も想定する。旧 `Iwf2b\Html` の流暢ビルダ(`__call`、約185行のデッドコード)は DR-16 により完全廃止。タグ自体の組み立て(`tag()`)も収載しない — タグはテンプレートに素の HTML として書く(絶対原則)。

エスケープは WP の `esc_attr()` を使用する(本クラスは WP 境界。ユニットテストでは Brain Monkey でスタブする)。

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
     * @throws \TMSWP\Core\Exception\InvalidArgumentException 属性名が §4.3 の形式違反のとき
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
8. **値のレンダリング**: bare 属性は属性名のみ。値付きは `name="' . esc_attr($value) . '"`。値が空文字でも `name=""` として出力する(省略しない。`value=""` 等が意味を持つため)。
9. 全トークンを `' ' . implode(' ', $tokens)` で返す(トークンゼロなら `''`)。

旧 `HtmlTag::parse_attrs` の「standalone 属性ホワイトリスト(checked/disabled/...13種)」は廃止し、**値の型(bool)で bare/省略を制御する** 方式に統一する。`required` / `hidden` / `open` 等の新しい boolean 属性にもリスト更新なしで対応できる。

---

## 5. Log — ロギング基盤

### 5.1 構成と役割分担

| クラス | 役割 |
|---|---|
| `Log` | 静的ファサード。明示メソッドのみ(`__callStatic` 禁止規約に従う) |
| `LoggerRegistry` | チャンネル管理+ディスパッチの実体。`Psr\Log\LoggerInterface` 実装。Container 登録・テスト対象 |
| `LogChannel` | チャンネル設定の内部 readonly 値オブジェクト |
| `ScopedLog` | `scope()` が返すワンショットの不変ロガー(旧実装の可変 scope 状態を置換) |
| `SimpleLogger` | PSR-3 v3 のファイルロガー実装(依存ゼロ) |
| `tms_log()` | グローバルヘルパ。`LoggerRegistry` を返す(旧 `iwf_log()` と同型の使い味) |

すべて `psr/log ^3` のシグネチャ(`string|\Stringable $message`)に準拠する。レベルメソッド8種は `Psr\Log\LoggerTrait` を use して実装する(マジックメソッド不使用)。

### 5.2 Log(静的ファサード)

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;

final class Log
{
    private static ?LoggerRegistry $registry = null;

    private function __construct() {}

    /** 共有レジストリ(未生成なら遅延生成)。tms_log() / Container バインドもこれを返す */
    public static function registry(): LoggerRegistry;

    /** レジストリ差し替え(テスト用)。null で初期化状態に戻す */
    public static function setRegistry(?LoggerRegistry $registry): void;

    /**
     * チャンネル登録。同名チャンネルは上書き。
     * @param list<string> $levels 受理レベル(Psr\Log\LogLevel 定数値)。空=全レベル
     * @param list<string> $scopes 受理スコープ。空=全スコープ(スコープなしレコード含む)
     */
    public static function set(string $channel, LoggerInterface $logger, array $levels = [], array $scopes = []): void;

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

全メソッドは `self::registry()` への単純委譲。`registry()` は `self::$registry ??= new LoggerRegistry()`。プロセス内で単一(テーマ+複数プラグインの Kernel が共存しても Log は1系統)。

**スコープ付与は不変 `ScopedLog`(§5.3)の1経路のみ**であり、既定スコープのような可変アンビエント状態は持たない(レジストリはプロセス内共有のため、あるコンポーネントが設定した既定スコープが他コンポーネントのスコープなしログ全てに波及する遠隔作用となる。スコープは常に明示引数として静的に追跡できる)。常に自スコープで書きたいコードは `$log = tms_log()->scope('shop');` の戻り値(`LoggerInterface` 実装)を保持して使い回す(DI 注入も可)。旧 `default_scope()` からの移行先としてこのレシピを rules/ とスターター `app/` スケルトンに明記する(申し送り)。

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
    private array $channels = [];

    /** set() が一度でも呼ばれたか(遅延デフォルトチャンネル生成の判定に使う) */
    private bool $configured = false;

    /**
     * @param list<string> $levels
     * @param list<string> $scopes
     * @throws \TMSWP\Core\Exception\InvalidArgumentException $channel が空文字、
     *         または $levels に不正レベルが含まれるとき
     */
    public function set(string $channel, LoggerInterface $logger, array $levels = [], array $scopes = []): void;

    public function has(string $channel): bool;

    /** @throws \TMSWP\Core\Exception\UnknownLogChannelException */
    public function channel(string $channel): LoggerInterface;

    /** チャンネル削除。未登録名は無視 */
    public function remove(string $channel): void;

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

/** @internal チャンネル設定の値オブジェクト */
final readonly class LogChannel
{
    /**
     * @param list<string> $levels
     * @param list<string> $scopes
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
2. **遅延デフォルトチャンネル**: `$this->channels === [] && !$this->configured` のとき、`set('default', new SimpleLogger())` を実行してから続行する(`set()` 内で `configured = true` になる)。一度でも `set()` が呼ばれたレジストリでは自動生成しない(全チャンネルを `remove()` した状態は「意図的にログ無効」とみなし no-op)。旧 FW で全15プロジェクトが完全コピペで行っていた `Log::set_logger(new SimpleLogger(), 'default')` 定型を不要にする。
3. **スコープ注入**: `$scope !== null` かつ `$context` に `_scope` キーが無ければ `$context['_scope'] = $scope` を追加する(ファイル上でスコープを追跡可能にする)。
4. **チャンネルマッチング**: 各チャンネルについて
   - `matchLevel = $channel->levels === [] || in_array($level, $channel->levels, true)`
   - `matchScope = $channel->scopes === [] || ($scope !== null && in_array($scope, $channel->scopes, true))`
   - 両方 true なら `$channel->logger->log($level, $message, $context)` を呼ぶ。
5. マッチするチャンネルがゼロでも例外は投げない(no-op)。

**旧挙動からの意図的変更**: 旧実装は「レコードにスコープが無い場合、scopes 制限付きチャンネルにもマッチする」だったが、新仕様では **scopes 制限付きチャンネルはスコープ一致時のみ受理** する(直感的なフィルタ動作に修正)。

`set()` の検証: `$channel === ''` → `TMSWP\Core\Exception\InvalidArgumentException`。`$levels` の各値もレベル検証する(タイポの早期検出)。`$scopes` は任意文字列(検証なし、空文字のみ不可)。

### 5.5 SimpleLogger

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * 依存ゼロのファイルロガー(PSR-3 v3)。
 * WP 境界: wp_date / wp_mkdir_p / trailingslashit を使用(ユニットテストでは Brain Monkey)。
 */
final class SimpleLogger implements LoggerInterface
{
    use LoggerTrait;

    private readonly string $dir;

    public function __construct(
        ?string $dir = null,                    // 既定: WP_CONTENT_DIR . '/tmswp-logs'
        private readonly string $fileNameFormat = '{date}.log',
        private readonly string $dateFormat = 'Y-m-d H:i:s',
        private readonly int $maxFiles = 30,    // 0 = ローテーション無効
    ) {}
    // コンストラクタ実装: $dir 既定値解決と末尾スラッシュ正規化のみ。
    // ディレクトリ作成は行わない(初回書き込みまで遅延)。
    // $maxFiles < 0 は TMSWP\Core\Exception\InvalidArgumentException。

    /** @param mixed $level */
    public function log($level, string|\Stringable $message, array $context = []): void;
}
```

#### ファイル名プレースホルダ

`fileNameFormat` は `Str::replace($format, $vars)` で展開する(プレースホルダ構文は `{key}` 固定。§3.2A)。

| プレースホルダ | 値 |
|---|---|
| `{date}` | `wp_date('Ymd')`(**WP タイムゾーン**。旧 `current_time()` ではなく `wp_date()` を使う) |
| `{level}` | レベル文字列小文字(例 `error`) |

`/` を含むフォーマット(例 `'{level}/{date}.log'`)はサブディレクトリとして解釈し、書き込み時に `wp_mkdir_p(dirname($path))` する。これにより旧 `split_dir` オプションはフォーマット指定だけで代替できる(bool オプションは廃止)。展開後のパスが `..` セグメントを含む場合は書き込みを拒否する(no-op)。

#### 書き込み手順(擬似コード)

```
log($level, $message, $context):
  1. $level を文字列smallcase化し8レベル検証(不正 => Psr\Log\InvalidArgumentException)
  2. ベースディレクトリ未作成なら wp_mkdir_p($this->dir) し、保護ファイルを生成(後述)
  3. $line = formatLine($level, $message, $context)   // 後述
  4. $path = $this->dir . Str::replace($this->fileNameFormat, ['date' => wp_date('Ymd'), 'level' => $level])
  5. $isNewFile = !file_exists($path)
  6. サブディレクトリ未作成なら wp_mkdir_p(dirname($path))
  7. error_log($line, 3, $path)    // 失敗しても例外は投げない(ロガーはアプリを止めない)
  8. $isNewFile かつ $this->maxFiles > 0 ならローテーション実行(後述)
```

#### 行フォーマット

```
{timestamp} [{LEVEL}] {message} {context_json}\n
```

- `{timestamp}` = `wp_date($this->dateFormat)`(WP タイムゾーン整合)。
- `{LEVEL}` = レベル大文字。
- `{message}` = `(string) $message` に対し PSR-3 プレースホルダ補間を適用: `{key}` 形式(`/\{([A-Za-z0-9_.]+)\}/`)を、`$context[key]` が scalar / null / `\Stringable` / `\BackedEnum` の場合のみ `Str::stringify` 値で置換する。補間済みキーも context JSON には残す(損失なし)。
- `{context_json}` = `$context` から `exception` キーを除いたものを `json_encode(..., JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)`。context が空なら本項とその前のスペースごと省略。
- `$context['exception']` が `\Throwable` の場合、本行の直後に以下を追記する:

```
{ExceptionClass}: {getMessage} in {getFile}:{getLine}
{getTraceAsString}
```

#### ローテーション

新規ファイル作成時のみ実行(日付ファイルなら日に1回)。対象は **作成したファイルと同じディレクトリ** の `*.log`:

```
$files = glob(dirname($path) . '/*.log')
if count($files) > $maxFiles:
    filemtime 昇順にソートし、古い方から (count - $maxFiles) 件を unlink(失敗は無視)
```

#### ログディレクトリの保護(旧 FW からの改善)

既定の `WP_CONTENT_DIR/tmswp-logs/` は Web 公開領域である。ベースディレクトリ作成時に次の2ファイルを生成する(既存なら触らない・失敗は無視):

- `.htaccess` … 内容 `Require all denied`(Apache 2.4)
- `index.php` … 内容 `<?php // Silence is golden.`

nginx 環境では `.htaccess` が無効なため、rules/ に「公開領域外ディレクトリを `dir:` 引数で指定するか、nginx 側で deny 設定を行う」ことを明記する(AGENTS.md 担当文書への申し送り)。

### 5.6 tms_log() ヘルパ

`packages/core/src/helpers.php`(composer.json の `autoload.files` で読み込み):

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Log\Log;
use TMSWP\Core\Log\LoggerRegistry;

if (!function_exists('tms_log')) {
    /**
     * ロガーへのグローバルアクセサ(旧 iwf_log() と同型の使い味)。
     *
     * tms_log()->info('imported', ['count' => $n]);
     * tms_log()->scope('mail')->error('send failed', ['exception' => $e]);
     * tms_log()->channel('audit')->info('user updated');
     */
    function tms_log(): LoggerRegistry
    {
        return Log::registry();
    }
}
```

- `function_exists` ガード付き(プラグイン+テーマで core が二重ロードされ得る環境対策。tmswp 同士は同一 vendor/ を共有する構成が原則だが防御的に付与する)。
- 戻り値は `LoggerRegistry`(`LoggerInterface` 実装)なので `->info()` / `->error()` 等がそのまま型安全に呼べる。旧 `iwf_log()->info(...)` の書き味は完全に維持される。
- PSR-3 v3 により `$message` は `string|\Stringable` のみ。旧 FW で見られた配列メッセージは `$context` に渡す(rules/ にレシピを明記)。

### 5.7 Kernel / Container との統合要件(統合点)

- Kernel は Container に `LoggerRegistry::class` を共有サービスとしてバインドし、その解決値は `Log::registry()` を返すこと(ファサードと DI 経由で同一インスタンスを保証)。
- Kernel は Log の初期化処理を一切行わない(遅延デフォルトチャンネルにより設定ゼロで動作する)。スターターの `app/` スケルトンには明示設定の例(§利用例)をコメントで含める。

---

## 6. 例外クラス(本章定義分)

すべて `declare(strict_types=1)` / `final`。

```php
namespace TMSWP\Core\Exception;

/** 引数検証エラーの共通例外(本章では Str/Html/Log の検証に使用) */
class InvalidArgumentException extends \InvalidArgumentException {}

/** Template の slug 形式違反 */
final class InvalidTemplateSlugException extends InvalidArgumentException {}

/** Template の解決失敗(メッセージに slug と探索候補全パスを含めること) */
final class TemplateNotFoundException extends \RuntimeException {}

/** 未登録ログチャンネルの参照 */
final class UnknownLogChannelException extends \OutOfBoundsException {}
```

`InvalidArgumentException` のみ継承を許す(`final` にしない)。Kernel 文書が例外の基底体系を別途定義する場合は当該定義に合流させる(open question)。

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

## actions/ によるテンプレート前処理(T04 の actions/ 相当)

旧 T04 は `actions/tmpl-contact.php`(問い合わせ固定ページ)でロジックを分離し、フォームの3画面遷移を駆動していた。新 FW は**テンプレート同名の `actions/{相対パス}.php` ファイル**で同等を表現する。テンプレート側はデータの読み出しを `tms_var()` で行う以外、素の WP テンプレートのままである。

### A. 関連記事の準備(actions/single-event.php — ファイル全文)

```php
<?php
/**
 * single-event.php の前処理: 関連イベント3件を準備する。
 * 戻り値契約: array を返すと View にマージされる。
 *
 * @var \WP_Query $wp_query メインクエリ(読み取り専用として扱う)
 */

declare(strict_types=1);

use App\Post\EventPost;

$related = EventPost::get_posts([
    'posts_per_page' => 3,
    'post__not_in'   => [get_the_ID()],
]);

return ['related' => $related];
```

テンプレートは素の WP テンプレートのまま、`tms_var()` で読む。`single-event.php`:

```php
<?php
/* イベント詳細テンプレート。FW はテンプレート階層を一切変更しない */

declare(strict_types=1);

/** @var list<\WP_Post> $related */
$related = tms_var('related', []); // actions/single-event.php が用意(省略可能なのでデフォルト明示)

get_header();
?>
<main>
    <?php while (have_posts()) : the_post(); // 標準ループをそのまま使う ?>
        <?php the_title('<h1 class="event-title">', '</h1>'); ?>
        <?php the_content(); ?>
    <?php endwhile; ?>
</main>
<?php
// 第二層: パーツへは get_template_part の $args で明示的に渡す(View::get/tms_var はパーツから読まない)
get_template_part('parts/event-related', null, ['related' => $related]);
get_footer();
```

パーツは冒頭で `wp_parse_args` のデフォルト付き受け口を宣言する。`parts/event-related.php`:

```php
<?php
/**
 * 関連イベント一覧パーツ。
 * @var array $args get_template_part() の第3引数
 */

declare(strict_types=1);

// パーツは必ず冒頭でデフォルト付きの受け口を宣言する。tms_var() は使わない(§1B.4)。
['related' => $related] = wp_parse_args($args, ['related' => []]);
?>
<?php foreach ($related as $event) : // ループ変数に $post は使わない(load_template の global 展開上書き事故防止) ?>
    <a href="<?= esc_url((string) get_permalink($event)) ?>"><?= esc_html(get_the_title($event)) ?></a>
<?php endforeach; ?>
```

### B. フォーム3画面遷移(actions/page-contact.php — 正準形)

旧 T04 の `actions/tmpl-contact.php` 相当。フォーム駆動とリダイレクト(PRG)を actions で行う。`template_include` は出力開始前のため `wp_safe_redirect()+exit` が安全に使える。`actions/page-contact.php`:

```php
<?php
/**
 * page-contact.php の前処理: お問い合わせフォームを駆動する。
 *
 * @var \WP_Query $wp_query
 */

declare(strict_types=1);

use App\Form\ContactForm;
use TMSWP\Core\Form\FormStep;

$form   = ContactForm::create();
$result = $form->handle();   // バインド+CSRF+検証+状態遷移(フォーム文書 §handle)

// 完了時はサンクスページへリダイレクト(PRG)。template_include は出力開始前のため exit は安全
if ($result->step === FormStep::Complete) {
    wp_safe_redirect(home_url('/contact/thanks/'));
    exit;
}

return ['form' => $form, 'result' => $result];
```

テンプレートは素の WP 固定ページテンプレートのまま、`tms_var()` で `Form` / `FormResult` を読む。`page-contact.php`:

```php
<?php
/* Template Name: お問い合わせ — 通常の WP ページテンプレート。FW は階層を一切変更しない */

declare(strict_types=1);

use TMSWP\Core\Form\FormStep;

/** @var \App\Form\ContactForm $form */
$form = tms_var('form');
/** @var \TMSWP\Core\Form\FormResult $result */
$result = tms_var('result');

get_header();
?>
<?php if ($result->step === FormStep::Confirm) : ?>
    <?= $form->open() ?>
    <dl>
        <?php foreach ($form->fields() as $view) : ?>
            <dt><?= $view->label() ?></dt><dd><?= $view->display() ?></dd>
        <?php endforeach; ?>
    </dl>
    <button type="submit" name="_step" value="back">入力に戻る</button>
    <button type="submit" name="_step" value="submit">送信する</button>
    <?= $form->close() ?>
<?php else : ?>
    <?= $form->open(['class' => 'contact-form']) ?>
    <?= $form->field('name')->input(['class' => 'w-full']) ?>
    <?= $form->field('name')->errors() ?>
    <?= $form->field('email')->input(['type' => 'email']) ?>
    <?= $form->field('email')->errors() ?>
    <!-- ...他フィールド。HTML 構造はテンプレートの自由 -->
    <button type="submit" name="_step" value="confirm">入力内容を確認する</button>
    <?= $form->close() ?>
<?php endif; ?>
<?php get_footer(); ?>
```

### C. 404 強制(actions/page-campaign.php)

スラッグからエンティティを解決し、見つからなければ 404 を強制する。`$wp_query->set_404()` は直呼びせず、必ず `ActionRouter::notFound()` を返す。

```php
<?php
/**
 * キャンペーン詳細ページの前処理: スラッグからキャンペーンを解決する。
 * 見つからなければ 404 を強制する。
 *
 * @var \WP_Query $wp_query
 */

declare(strict_types=1);

use App\Post\CampaignPost;
use TMSWP\Core\View\ActionRouter;

$campaign = CampaignPost::get_post(['name' => get_query_var('campaign_slug')]);

if (!$campaign) {
    return ActionRouter::notFound(); // set_404 + ステータス行 + 404.php(不在時 index.php)へ差し替え
}

return ['campaign' => $campaign];
```

要点: いずれの例もテンプレートには `template_include` 介入・`exit`・`extract()`・グローバル変数展開が一切なく、WP 標準テンプレート階層のままである。ロジックは同名 `actions/` ファイルに分離され、データは `View` 変数バッグ(`return array` → `tms_var()`)経由で型注釈つきに渡る(旧 `load_global()` + `extract()` の暗黙渡しを廃止)。`actions/common.php`(全テンプレート共通)はサポートしない(Keel 準拠。グローバルナビ用タームの取得等の共通ロジックは `app/Hook` クラス、またはパーツ内のモデルヘルパ直読みへ置く)。

## ロギング

```php
// 設定ゼロで動く(初回書き込み時に default チャンネル + SimpleLogger が自動登録される)
tms_log()->info('csv import finished', ['count' => $count]);

// 明示設定(スターター bootstrap の例): エラー以上を別ファイルへ、メール送信ログを分離
use Psr\Log\LogLevel;
use TMSWP\Core\Log\Log;
use TMSWP\Core\Log\SimpleLogger;

Log::set('default', new SimpleLogger());
Log::set('errors', new SimpleLogger(fileNameFormat: 'errors/{date}.log'),
    levels: [LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY]);
Log::set('mail', new SimpleLogger(fileNameFormat: 'mail-{date}.log'), scopes: ['mail']);

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

- **View / ActionRouter**: 旧 `Iwf2b\View` は `template_include` priority 100 での乗っ取り+`load_global()`(`$wp_query->query_vars` の全グローバル展開)+`extract()`+`exit` という4重の WP 標準破壊を抱えていた(DR-10/DR-16 で廃止確定)。実利用52参照のうちテンプレート分割の本質的価値は `View::element()`(T11 等のパーシャル描画)とテンプレート前処理(`actions/` 規約)であり、前者は WP 5.5+ の `get_template_part($slug, $name, $args)` と同型の `Template::part($slug, $args)`(§1)として、後者は `ActionRouter` + `View` 変数バッグ(§1A・§1B)として再設計した。
- **ActionRouter(actions/ 規約)**: 旧 `actions/{テンプレート名}.php` 規約(T04・T05・T06 で15案件中に定型化)は、テンプレートからロジックを分離する現場価値が高い一方、その実現手段が上記 View 乗っ取り機構そのものであり、4つの問題(テンプレート階層複製・`$GLOBALS` 展開・`exit` バイパス・`load_global`)を抱えていた。本層はこの価値(ロジック分離)だけを残し害を全廃する: `template_include`(priority 100)を購読するが `$template` を**常に無加工で返す**(404 強制時のみ WP 標準の `get_404_template()` へ差し替える)ため WP 標準解決を一切変更せず、actions が見える変数は `$wp_query` のみ(static クロージャ+`func_get_arg()` include。`Template::includeIsolated` と同一イディオムへ統一。モダン化監査(2026-06-13)で採用)、データは `return array` → `View` 変数バッグ → `tms_var()` の追跡可能な経路で渡す(`extract()`/`load_global()` 廃止)。actions の戻り値の正準形は明示的な連想配列リテラルとし `compact()` を使わない(`extract()` 禁止との対称性。同監査で採用)。404 は `ActionRouter::notFound()` で表明し `set_404()` 直呼びを禁ずる。本機構は当初クラス形式コントローラ(`#[AsController]` を付した `TemplateController` 基底+`current()` 取得)として検討したが、(1) 旧 iwf2b の現場規約が**テンプレート同名ファイル**であり15案件の定着パターンを直接踏襲する方が学習コストが低い、(2) 属性探索・Initializer・Runner・`ControllerNotRunException` という機構がファイル include 規約に対して過大、(3) 参考設計書 Keel が同型の `actions/` ファイル規約(§5.2 Router / §5.3 View / ADR-2 / ADR-9)を採用済みである、の3点から、クラス形式案を退けてファイル規約へ置き換えた(2026-06-13 ユーザー確定。DR-19)。型の担保は `public readonly` プロパティの代わりに、テンプレート側 `@var` 注釈規約+`View::get` 未定義キー警告+`bin/tms-check` 静的検査(§1D)で行う。`actions/common.php`(全テンプレート共通)はサポートしない(Keel 準拠。共通ロジックは `app/Hook` クラスまたはパーツ内モデルヘルパ直読みへ)。
- **Arr**: 実利用は `get`(21箇所)のドット記法アクセスが中核。deep merge 系(`merge`/`merge_intersect_key` 9箇所)は旧 FW の「options 連想配列」文化の産物であり、新 FW では named arguments + readonly 定義オブジェクト(`PostTypeDefinition` 等)が代替するため収載しない。
- **Str**: 旧 `Text` の実利用 4 メソッド+FW 内部で必要な命名変換のみ。`auto_link` は WP 標準 `make_clickable()`、`short_hash` は利用側の `hash_hmac` 直接利用(thumbnail 拡張)で代替。`truncate` は WP 依存(タグ除去)が混入していたため、純関数 `truncate` と WP 境界 `excerpt` に分離した。`replace` は旧 `Text::replace` の `$bounds` パラメータ(`%key%` 既定+区切り文字切替)を撤廃し、`{key}` 構文+`strtr()` 単一パスへ固定した(§3.2A。FW 内のプレースホルダ構文を1種類に統一・再置換バグの構造的排除。モダン化監査(2026-06-13)で採用)。
- **Html**: 旧流暢ビルダ(`__call`/`__get`/ノードコレクション約185行)は全プロジェクト参照ゼロの完全デッドコード(DR-16 で廃止確定)。実利用は `Html::tag()` 経由の属性レンダリングのみで、その実体だった `HtmlTag::parse_attrs` を純関数 `attributes()` として再定義。standalone 属性のホワイトリスト方式は HTML の進化に追随できないため bool 値駆動に変更した。
- **Log**: 全15プロジェクトがブートで同一の `Log::set_logger` 定型を書き、`iwf_log()` は300箇所で利用された最高頻度 API。チャンネル+levels/scopes フィルタの設計は実証済みのためそのまま踏襲し、(1) `__call`/`__callStatic` を明示メソッド+`LoggerTrait` に置換、(2) ワンショット scope の可変インスタンス状態(書き込み後にクリアする実装)を不変 `ScopedLog` に置換、(3) スコープなしレコードが scopes 制限チャンネルに漏れる旧マッチング規則を修正、(4) 定型ブートを遅延デフォルトチャンネルで不要化、(5) 旧 `default_scope()`(永続アンビエントスコープ)は撤廃し、スコープ付与を不変 `ScopedLog` の1経路に統一した(プロセス共有レジストリ上の可変既定スコープは他コンポーネントのスコープなしログ全てに波及する遠隔作用となるため。自スコープ常用は `$log = tms_log()->scope('shop')` の保持で代替。モダン化監査(2026-06-13)で採用)。
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

**View\ActionRouter / View\View / View\NotFound**
- [ ] `actions/page-contact.php` が `page-contact.php` 解決時の `template_include`(priority 100)で1回 include され、`return array` が `View::set()` でマージされる(統合テスト)
- [ ] `dispatch` が**常に元の `$template` を無加工で返す**(array/null/不正戻り値いずれでも。404 強制時のみ `get_404_template()` へ差し替え。`grep`+統合テストで確認)
- [ ] 非文字列の `$template`(先行フィルタが `false`/`null`)・テーマ外テンプレート・`actions/` ファイル不在のいずれも素通しされる(完全オプトイン)
- [ ] 子テーマ→親テーマの順で同名 `actions` を解決し、子テーマ優先で採用する(統合テスト)
- [ ] actions が `ActionRouter::notFound()` を返すと `set_404()` + `status_header(404)` + `nocache_headers()` が実行され、`get_404_template()`(404.php 不在テーマでは `get_index_template()`)が返る
- [ ] `headers_sent()` が true のとき status_header は no-op となり、WP_DEBUG 時に `wp_trigger_error` で可視化される
- [ ] actions 内で見える変数が `$wp_query` のみであり、ファイルパス変数も含めて `$this`/Router 内部へアクセスできない(static クロージャ+`func_get_arg()` include(§1A.4 手順4。`Template::includeIsolated` と同一イディオム)をコードレビュー+静的解析で確認)
- [ ] actions 内の `wp_safe_redirect()+exit` が機能する(出力開始前であることを統合テストで確認)
- [ ] `View::set()` がマージ(全置換でない)であり、`View::get()` が未定義キー+デフォルト未指定時に WP_DEBUG で `wp_trigger_error(E_USER_WARNING)` を発する(意図的デフォルト指定時は警告しない)
- [ ] `tms_var('key')` が `View::get('key')` へ1メソッド委譲する。`tms_var('key', $default)` でデフォルト指定できる
- [ ] プラグイン形態の Kernel では `ActionRouter` が生成されない(テーマ形態のみ。`KernelConfig::theme(actions: false)` で抑止できる)
- [ ] `bin/tms-check` が actions/ 配下の `echo`/`print`・孤児 actions・`$wp_query->set_404()` 直呼びを検出する(検査仕様は 10-quality.md)

**Support**
- [ ] `Arr::get/has/set` のドット記法がネスト3段・数値文字列キー・非配列中間ノードのケースを含むデータプロバイダで仕様どおり動作する
- [ ] `Str::replace` が `{key}` 構文を `strtr()` の単一パスで置換する(`$bounds` パラメータが存在しない・置換値に含まれる `{...}` が再置換されないテスト。§3.2A)
- [ ] `Str::stringify` が §3.3 の全行(enum / DateTimeInterface / Traversable / 再帰配列 / Closure 含む)を網羅するテストを持つ
- [ ] `Str::truncate` がマルチバイト文字列で文字数どおり切り詰める(バイト破断なし)
- [ ] `Html::attributes` が「空配列 → `''`」「非空 → 先頭スペース1つ」「`class` 条件マップ」「bool true/false/null」「`aria-*` の true/false 文字列化」「int キー bare 属性」「不正属性名 → 例外」を網羅するテストを持つ
- [ ] `Html::attributes` の値が必ず `esc_attr()` を通過する(`"` を含む値の出力テスト)
- [ ] `Arr` / `Str`(`excerpt` を除く)が WP 関数を一切呼ばない(ユニットテストが Brain Monkey のスタブなしで通る)

**Log**
- [ ] `tms_log()->info('msg')` が設定ゼロで `WP_CONTENT_DIR/tmswp-logs/{Ymd}.log` に `{timestamp} [INFO] msg` 形式で追記される(統合テスト)
- [ ] `Log::set()` を一度でも呼んだ後は遅延デフォルトチャンネルが生成されない
- [ ] levels / scopes フィルタが §5.4 のマッチング規則(スコープなしレコードは scopes 制限チャンネルに届かない)どおり動作する
- [ ] `Log::scope('x')->error(...)` 後に別のログ呼び出しへスコープが残留しない(`ScopedLog` の不変性)
- [ ] `Log` / `LoggerRegistry` に既定スコープ系 API(`defaultScope` / `setDefaultScope`)が存在せず、スコープ付与経路が `ScopedLog` のみである(scope 指定なしの `log()` は常に scope = null で dispatch される)
- [ ] 未知レベルで `\Psr\Log\InvalidArgumentException`、未登録 `channel()` で `UnknownLogChannelException`
- [ ] `context['exception']` の Throwable がクラス名・メッセージ・file:line・スタックトレース付きで追記される
- [ ] `{key}` プレースホルダ補間が scalar/Stringable/BackedEnum/null の context 値でのみ行われる
- [ ] `maxFiles` 超過時に mtime の古い `*.log` から削除される/`maxFiles: 0` で削除されない
- [ ] ベースディレクトリ作成時に `.htaccess`(`Require all denied`)と `index.php` が生成される
- [ ] タイムスタンプと `{date}` が `wp_date()` 由来である(タイムゾーン `Asia/Tokyo` 設定の統合テストで UTC とずれることを確認)
- [ ] `Log` / `LoggerRegistry` / `ScopedLog` / `SimpleLogger` に `__call` / `__callStatic` が存在しない

## 検証コマンド(DR-12 共通)

```bash
composer lint      # php-cs-fixer: PSR-12 + declare(strict_types=1) 検査
composer analyse   # PHPStan level max・baseline なし(本章クラスはエラーゼロ)
composer test      # PHPUnit 11: ユニット(Arr/Str/Html/Log ディスパッチ = Brain Monkey)
                   #             統合(Template 解決順/SimpleLogger 実書き込み = wp-env + wp-phpunit)
composer qa        # 上記すべて
grep -rn "extract(" packages/core/src/            # ヒット 0 件であること
grep -rn "compact(" packages/core/src/            # ヒット 0 件であること(actions の正準形は明示的配列リテラル。§1D.1)
grep -rn "template_include" packages/core/src/    # ActionRouter(actions ディスパッチ・$template 無加工返却)以外でヒット 0 件
grep -rn "template_redirect" packages/core/src/   # ヒット 0 件であること(乗っ取りなし)
grep -rn "__callStatic\|function __call(" packages/core/src/View packages/core/src/Support packages/core/src/Log  # 0 件
composer qa        # bin/tms-check(actions の echo/孤児/set_404 直呼び検査)を含む
```
