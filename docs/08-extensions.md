# 08. 拡張パッケージ仕様 — thumbnail / settings-page / ja-toolkit

## 目的

コア(tmswp/core)に収載しない付帯機能を、composerで個別導入できる3つの公式拡張パッケージとして確定する(DR-06/DR-10/DR-14/DR-15)。本書だけを読めば各パッケージをゼロから実装できる粒度で、公開API・振る舞い・エッジケース・受け入れ基準を定義する。

## スコープ(対象パッケージ)

| パッケージ | 名前空間 | monorepo配置 | 概要 |
|---|---|---|---|
| tmswp/thumbnail | `TMSWP\Thumbnail\` | packages/thumbnail | 署名付きURL+オンデマンド生成のサムネイル(timthumb全面置換) |
| tmswp/settings-page | `TMSWP\SettingsPage\` | packages/settings-page | Form層ベースの管理画面設定ページ |
| tmswp/ja-toolkit | `TMSWP\JaToolkit\` | packages/ja-toolkit | 日本語サイト対応のオプトインモジュール集 |

tmswp/acf-bridge は本書のスコープ外(専用文書が定義)。CSVインポート基盤は公式拡張として提供しない(DR-20。必要な案件はapp側で実装する)。

## 依存文書

- 01 Kernel/Discovery設計書 … `Kernel::boot()`、`Kernel::all()`(マルチKernel走査)、属性探索、`ComponentAttributeInterface` / `ComponentInitializerInterface`(拡張属性の正準契約。settings-pageが実装する)
- 03 エンティティ定義設計書 … `PostType::thumbnailUrl()` と `tmswp/post/thumbnail_fallback` フィルタ
- 04 Meta/Option設計書 … オプショングループ enum(`OptionAccess` trait)/ `OptionRepository`(settings-pageの保存先)
- 05 Form設計書 … `Form` / `FormResult` / `FormStep` / `TempFileStore`(settings-pageが利用)
- 06 Model/Log/View設計書 … `Log`(PSR-3)、`View\Template`

## パッケージ共通規約

全3パッケージに共通する規約。各パッケージ節では差分のみ記す。

### composer.json

```json
{
    "name": "tmswp/<package>",
    "type": "library",
    "require": {
        "php": ">=8.3",
        "tmswp/core": "^1.0"
    },
    "autoload": {
        "psr-4": { "TMSWP\\<Pascal>\\": "src/" }
    }
}
```

- パッケージ固有の追加require: thumbnail=`ext-gd`(DR-14でコアから移設)、ja-toolkit=`ext-mbstring`。settings-pageは追加なし。
- WPプラグインヘッダは持たない。各パッケージは「テーマ/プラグインのvendor/に入るライブラリ」であり、有効化はapp側の`KernelConfig`への明示的な登録(下記)で行う。

### 登録API(KernelConfig経由・明示オプトイン)

拡張は自動では何もしない。各パッケージの入口のクラス(`TMSWP\Core\Kernel\ExtensionInterface`の実装)のインスタンスを、テーマ・プラグインのルートの`tmswp-kernel.php`で`KernelConfig::theme()` / `plugin()`の`extensions`に渡す。拡張は、渡したKernelでだけ有効になる(文書02 §3.10)。テーマとプラグインがそれぞれ同じ拡張を登録した場合は、Kernelごとに別々に動く。

```php
// tmswp-kernel.php(functions.php / プラグイン本体は、このファイルのKernelConfigでKernel::boot()する)
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\SettingsPage\SettingsPages;

return KernelConfig::theme(
    __DIR__ . '/app',
    'App',
    extensions: [new SettingsPages()],
);
```

入口のクラスは、各パッケージの節に書く(settings-pageは§2.2の`SettingsPages`)。

- Kernelは、bootの中で`extensions`の各インスタンスの`register(Kernel $kernel)`を、渡した順に1回ずつ呼ぶ。
- 同じクラスの拡張を同じKernelに2つ渡すと、`KernelConfig`が`TMSWP\Core\Exception\KernelException`を投げる。1つのインスタンスを2つのKernelに渡すと、2つ目のKernelの`register()`が例外を投げる(1つのインスタンスは1つのKernelにだけ登録できる)。どちらも設定の誤りなので、debugに関係なく起動を止める。冪等な握り潰しはしない。設定差異の取り違えを早期に検出するためである。
- 拡張のdebugによる分岐は、登録したKernelの`config()->debug`に従う。`WP_DEBUG`を直接見ない。拡張の内部のログは、登録したKernelの`logger()`に記録する。

`register(Kernel $kernel)`が、`init`等のフックに処理を予約するときは、`add_action()`ではなく`$kernel->defer($hook, $priority, $callback)`を使う。本番の遅延boot(フックの発火後のboot)では`add_action()`で登録した処理が二度と実行されないが、`defer()`はKernelのキャッチアップの中で実行するためである(文書02 §3.3)。

拡張のコンストラクタは、WordPressの関数を呼ばない。WordPressに触れる処理は`register()`以降で行う。拡張は`tmswp-kernel.php`の中で`KernelConfig`と一緒に生成され、このファイルはWordPressを起動しない`tms-acf`(文書09 §5.1)も読むためである(文書02 §3.10)。

### 例外・ログ・i18n

- 汎用の宣言の誤り・型の誤り(設定の誤りや、拡張に固有の情報を持たない実行時の失敗)は、coreの`TMSWP\Core\Exception\LogicException`・`RuntimeException`を直接使う。中身の無いラッパーの例外クラスは作らない。
- 拡張に固有の例外は、各パッケージの`TMSWP\<Pascal>\Exception\`配下に置き、`TMSWP\Core\Exception\RuntimeException`(または`LogicException`)を継承する。
- ログは、受け取った Kernel の `logger()`(PSR-3)に記録する(Kernel設計書§3.10、View/Support/Log設計書§5.1)。`echo` によるログ出力は全パッケージで禁止。
- テキストドメインは `tmswp`(DR-17)。拡張独自ドメインは作らない。

### QA

コアと同一のmonorepoでCIを共有する(DR-11/DR-12)。`composer qa` でPHPStan level max・php-cs-fixer・PHPUnitが全パッケージ横断で走ること。ユニットテストはBrain MonkeyでWP関数をモック、統合テストはwp-envで実施。

---

# 1. tmswp/thumbnail — 署名付きURLサムネイル

## 1.1 設計方針

旧iwf2bの `Iwf2b\Thumb` は「timthumb.phpへ無署名クエリを投げるURL生成器」だった。timthumbは2011年のリモートファイル取得RCE(キャッシュディレクトリへのwebshell書き込み、いわゆるTimThumb脆弱性事件で数百万サイトが侵害)で悪名高く、メンテも終了している。旧設計はFW側に署名・サイズ上限・srcドメイン制限が皆無で、(a)任意巨大サイズの連続リクエストによるCPU/ディスクDoS、(b)src改変によるSSRF/パストラバーサルがエンドポイント実装品質に全面依存していた。

新設計の原則:

1. **リモートURLのフェッチを一切行わない**。srcはアップロードディレクトリ配下のローカルファイルまたは添付ファイルIDのみ。
2. **全パラメータをHMAC署名で固定**。署名のないURL・改ざんされたURLは画像を生成しない。
3. **サイズ上限を強制**。署名検証後であっても上限超過は拒否(多層防御)。
4. 画像処理は `wp_get_image_editor()`(WP_Image_Editor)に委譲し、GD/Imagickの差異をWPに任せる。
5. 生成物は `uploads/tmswp-thumb/` にキャッシュし、2回目以降は静的ファイルとして配信する(PHPを通さない)。

## 1.2 URL形式とエンドポイント

エンドポイントは **REST API ルート**とする(rewrite ruleは採用しない。`flush_rewrite_rules` 運用が不要で、テーマ/プラグインどちらの導入でも確実に動作するため)。

```
GET /wp-json/tmswp/v1/thumb?src=<src>&w=<int>&h=<int>&o=<opts>&s=<signature>
```

| パラメータ | 型 | 意味 |
|---|---|---|
| `src` | string | `"id:123"`(添付ファイルID) または uploadsベースURL配下の画像URL |
| `w` | int | 出力幅(px)。`0` = 高さ基準の自動 |
| `h` | int | 出力高さ(px)。`0` = 幅基準の自動(`w`と`h`の同時0は不正) |
| `o` | string | オプションの正規化文字列(後述)。オプションなしは空文字 |
| `s` | string | HMAC-SHA256署名(hex 64文字) |

### オプション正規化文字列(`o`)

許可キーは `crop`(bool) と `quality`(int 1〜100)の2つのみ。正規化手順:

1. 許可キー以外が含まれていたらURL生成時に `Exception\InvalidThumbOptionException` を投げる。
2. キー昇順にソートし、`crop=1&quality=82` 形式で連結(boolは`1`/`0`、デフォルト値と同値のキーは**含めない**)。デフォルト: `crop=true`、`quality=82`。
3. 全オプションがデフォルトなら空文字。

### 署名

```
payload   = "{src}|{w}|{h}|{o}"
signature = hash_hmac('sha256', payload, wp_salt('auth'))
```

検証は `hash_equals()` で行う。`wp_salt('auth')` を鍵とするため、サイト間でURLは流用できず、salt変更で全URLが無効化される(キャッシュ済みファイルの配信は影響を受けない)。

検証時のpayloadは**受信した生のクエリ文字列値**(`src`/`w`/`h`/`o`)をそのまま連結して組み立てる(intキャスト・trim・再正規化を一切行わない。§UrlSigner参照)。URL生成側は整数を10進最短表記で埋め込むため、`w=050` のような異表記は生成時payloadと一致せず署名不一致(403)になる。

### エンドポイントの処理手順(確定アルゴリズム)

```
1. s が無い/64桁hexでない        → 403 (WP_Error 'tmswp_thumb_unsigned')
2. UrlSigner::verify() が false  → 403 (WP_Error 'tmswp_thumb_bad_signature')
   ※ verify() には受信した生の src/w/h/o 文字列をそのまま渡す(intキャスト前)
2.5. 署名検証通過後、受信した o をパースし crop(bool)/quality(int) を取り出す:
   - o を '&' / '=' で分解し、許可キー(crop/quality)以外が含まれていれば → 400
   - quality が int として 1〜100 の範囲外 → 400
   - crop が '0'/'1' 以外 → 400
   - o に現れないキーは ThumbnailConfig の既定値(defaultCrop/defaultQuality)で補完
   ※ 署名が通っていても範囲外パラメータは多層防御で拒否する。
     o の再正規化・再署名は行わない(署名が通った o をそのままパースするのみ。
     非正規形の o は手順2の生文字列照合の時点で署名不一致になる)
3. w,h の検証: intキャスト後、両方0、負数、いずれかが maxDimension 超過 → 400
4. SourceResolver::resolve(src)  → null なら 404
5. キャッシュファイルが存在       → 302リダイレクト(Location: キャッシュURL,
                                    Cache-Control: public, max-age=31536000, immutable)
6. 生成: wp_get_image_editor(絶対パス) → resize(w?:null, h?:null, crop)
         → set_quality(quality) → save(キャッシュ絶対パス)
   ※ crop/quality は手順2.5のパース結果を使う(他の出所を持たない)。
     w または h が 0 の場合は null を渡しアスペクト比維持
7. 生成失敗(WP_Error)            → Log::error() 後、元画像URLへ302リダイレクト(劣化運転)
8. 生成成功                       → 5と同じ302リダイレクト
```

エラー応答(403/400/404)はJSON(`WP_Error` 標準形式)。画像を返さないことでクローラのキャッシュ汚染を防ぐ。

## 1.3 キャッシュ規則

- 配置: `wp_upload_dir()['basedir'] . '/tmswp-thumb/{shard}/{cacheKey}.{ext}'`
  - `shard` = cacheKey先頭2文字(1ディレクトリ肥大防止)
  - 初回生成時にディレクトリを `0755` で作成し、`tmswp-thumb/` 直下に空の `index.html` を設置する(旧FWの`0777`作成を廃止)
- キャッシュキー(確定式):

```
cacheKey = hash('sha256',
    "{uploadsからの相対パス}|{filemtime}|{w}|{h}|{o正規化文字列}"
)  // hex 64文字のうち先頭40文字を使用
ext      = 元画像の拡張子(小文字化。jpeg→jpg に正規化)
```

- `filemtime` をキーに含めるため、元画像が差し替わると自動的に別キャッシュへ切り替わる(明示的な無効化処理が不要)。旧ファイルはGCで回収する。
- 許可拡張子: `jpg, png, gif, webp, avif`。それ以外のsrcは `SourceResolver` が拒否する。
- GC: WP-CLI `wp tmswp thumb clear [--days=<n>]`(後述)。自動cronは持たない(DECISION: 運用判断。必要ならapp側で `wp_schedule_event` からCLI相当処理を呼ぶレシピをrules/に記載)。

## 1.4 クラス仕様

### `TMSWP\Thumbnail\Thumbnail`

パッケージのエントリポイント兼URL生成ファサード。

```php
final class Thumbnail
{
    private static ?ThumbnailConfig $config = null;

    public static function register(?ThumbnailConfig $config = null): void;
    public static function url(int|string $src, ?int $width = null, ?int $height = null, array $options = []): string;
    public static function config(): ThumbnailConfig;   // 未registerならLogicException
    public static function isRegistered(): bool;
    public static function reset(): void;               // テスト用。設定を破棄する
}
```

- `register()`: 設定を保持し、`rest_api_init` で `RestController` を登録、WP-CLI環境なら `ThumbCommand` を登録する。二重呼び出しは `LogicException`。
- `url()`: 振る舞い(確定手順)
  1. `$src` がintなら `"id:{$src}"` へ正規化。stringならuploadsベースURL配下かを検証(外なら `InvalidThumbSourceException`)。
  2. `$width`/`$height` がともにnull/0なら `InvalidThumbSourceException`。上限超過も同様(URL生成時点で開発者ミスを検出)。
  3. オプションを正規化(1.2参照)。
  4. `SourceResolver::resolve()` でローカルパス解決を試み、解決できてキャッシュファイルが既に存在すれば**キャッシュファイルの静的URLを返す**(エンドポイントを経由させない)。
  5. それ以外は署名付きRESTエンドポイントURLを返す。
- 例外はテンプレート描画境界では隔離する。本番(`WP_DEBUG` 無効)では `url()` 内で `Log::warning()` を記録して空文字 `''` を返す。`WP_DEBUG` 有効時は記録したうえで捕捉した例外をそのまま送出し、開発者の不正なサイズ指定・src指定を即時検出する。

### `TMSWP\Thumbnail\ThumbnailConfig`

```php
final readonly class ThumbnailConfig
{
    public function __construct(
        public int $maxDimension = 2560,          // WPのbig_image閾値に合わせる
        public int $defaultQuality = 82,
        public bool $defaultCrop = true,
        public string $cacheDirName = 'tmswp-thumb',
    ) {}
}
```

### `TMSWP\Thumbnail\UrlSigner`

```php
final class UrlSigner
{
    public function __construct(private readonly string $secret) {}  // wp_salt('auth')を注入

    public function sign(string $src, int $width, int $height, string $options): string;
    public function verify(string $src, string $width, string $height, string $options, string $signature): bool;
}
```

`verify()` の確定仕様: **受信した生の `src`/`w`/`h`/`o` 文字列そのもの**からpayloadを組み立てて照合する(intキャスト前の文字列で照合する)。

```
verify(src, width, height, options, signature):
  payload = "{src}|{width}|{height}|{options}"   // 受信文字列そのまま。キャスト・trim・再正規化は行わない
  return hash_equals(hash_hmac('sha256', payload, secret), signature)
```

`sign()`(URL生成側)は整数を10進最短表記で埋め込むため、`w=050`・`h=+1` 等の異表記や `o` のキー並べ替え・余分キー混入は生成時payloadと一致せず、署名不一致として確実に弾かれる。タイミング攻撃対策のため `===` 比較は禁止。

### `TMSWP\Thumbnail\SourceResolver`

```php
final class SourceResolver
{
    /** @return non-empty-string|null 解決済み絶対パス(uploads配下保証)。不正・不在はnull */
    public function resolve(string $src): ?string;
}
```

解決手順(確定。realpath封じ込めを**全経路共通の最終ゲート**とする):

```
1. パス取得(いずれかの経路):
   a. "id:{n}" 形式 → get_attached_file(n)。falseならnull
   b. URL形式 → wp_upload_dir()['baseurl'] に '/' を付与した文字列で
      前方一致しなければnull。一致したら相対パス部を basedir に連結
2. 拡張子が許可リスト外ならnull。file_exists() しなければnull
3. realpath封じ込め(最終ゲート。経路a/bの両方に必ず適用する):
   base = realpath(basedir)。false なら null
   real = realpath(取得パス)。false なら null
   str_starts_with(real, base . DIRECTORY_SEPARATOR) でなければ null
   合格したら real を返す
```

- 手順3は `realpath(basedir)` に**末尾ディレクトリ区切り `DIRECTORY_SEPARATOR` を付与した文字列**で `str_starts_with()` 判定を行い、prefix一致の境界をディレクトリ単位に限定する。`basedir` が `/var/www/uploads` のとき `/var/www/uploads-evil/x.jpg` のような「同prefix別ディレクトリ」の誤通過を遮断する(旧 `Filesystem::is_child_path` のstrpos前方一致バグの再発防止)。`../` 混入・シンボリックリンク脱出も同時に遮断される。
- 経路a(`id:{n}`)で得た `get_attached_file()` の結果も手順3を必ず通す。添付ファイルメタ(`_wp_attached_file`)が改変された環境ではuploads外を指しうるため、DB由来のパスを信頼しない。

### `TMSWP\Thumbnail\ThumbCache`

```php
final class ThumbCache
{
    public function __construct(private readonly string $baseDir, private readonly string $baseUrl) {}

    public function key(string $relativePath, int $mtime, int $width, int $height, string $options): string;
    public function path(string $key, string $ext): string;   // 絶対パス
    public function url(string $key, string $ext): string;
    public function exists(string $key, string $ext): bool;
    /** @return int 削除ファイル数 */
    public function clear(?int $olderThanDays = null): int;
}
```

### `TMSWP\Thumbnail\RestController`

```php
final class RestController
{
    public function __construct(
        private readonly UrlSigner $signer,
        private readonly SourceResolver $resolver,
        private readonly ThumbCache $cache,
        private readonly ThumbnailConfig $config,
    ) {}

    public function registerRoutes(): void;  // register_rest_route('tmswp/v1', '/thumb', ...)
    public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error;
}
```

`permission_callback` は `'__return_true'`(認可は署名検証が担う)。処理手順は1.2の確定アルゴリズムに従う。

### `TMSWP\Thumbnail\Cli\ThumbCommand`

```php
final class ThumbCommand
{
    /** wp tmswp thumb clear [--days=<n>] : キャッシュ削除(--days指定時はn日より古いもののみ) */
    public function clear(array $args, array $assocArgs): void;
    /** wp tmswp thumb status : キャッシュ件数・合計サイズを表示 */
    public function status(array $args, array $assocArgs): void;
}
```

`Thumbnail::register()` 内で `WP_CLI::add_command('tmswp thumb', ...)` 登録。

### グローバルヘルパ `tms_thumb()`

パッケージの `composer.json` の `autoload.files` で定義(`function_exists` ガード付き)。

```php
function tms_thumb(int|string $src, ?int $width = null, ?int $height = null, array $options = []): string
{
    return \TMSWP\Thumbnail\Thumbnail::url($src, $width, $height, $options);
}
```

旧 `iwf_thumb()`(5プロジェクト44箇所で利用)と同じ書き味を `tms_` 接頭辞で踏襲する。

## 1.5 利用例(app側)

```php
// functions.php
\TMSWP\Thumbnail\Thumbnail::register(new \TMSWP\Thumbnail\ThumbnailConfig());
```

```php
<?php /* template-parts/card.php — WP標準テンプレートにechoするだけ */ ?>
<img src="<?php echo esc_url(tms_thumb(get_post_thumbnail_id(), 400, 300)); ?>" alt="">
<img src="<?php echo esc_url(tms_thumb($url, 1200, 0, ['crop' => false, 'quality' => 90])); ?>" alt="">
```

## 1.6 旧iwf2bからの設計根拠

- `Thumb` + `TimthumbDriver` は無署名クエリURL生成のみで、timthumb本体(2011年RCEの代名詞・メンテ終了)を各案件が配置する前提だった → 署名付きURL+WP_Image_Editorで全面置換。
- `composer.json` の `ext-gd` はtimthumb前提の幻の依存だった → 本パッケージのrequireへ移設(DR-14)。
- staticシングルトン+`set_driver` の不整合状態(例外時にdriverのみ設定済み)→ `register()` 一発の不可分な初期化へ。
- `AbstractPost::get_thumbnail()` の本文`<img>`抽出+`@getimagesize`(リモート同期I/O)はコアの責務外 → コアには `tmswp/post/thumbnail_fallback` フィルタポイントのみ残し、本文画像抽出は app 側レシピ(`rules/post-type.md`)で実装する(DR-15)。

---

# 2. tmswp/settings-page — 管理画面設定ページ

## 2.1 設計方針

旧 `Iwf2b\SettingsPage\AbstractSettingsPage` は5プロジェクト16クラスで使われた実需機能だが、**`$_REQUEST['action']` 直読みでnonce/CSRF検証なしにアクションファイルをinclude実行する**重大な欠陥を持っていた(加えて `View::load_global` のextract依存、`{slug}/{action}.php` 命名規則ディスパッチというCakePHP時代の移植痕)。新設計はファイルベースディスパッチを全廃し、次の3層合成で再構築する:

1. **メニュー登録**: `#[AsSettingsPage]` + `SettingsPageDefinition`(`add_menu_page`/`add_submenu_page` の薄い宣言)
2. **フォーム処理**: コアForm層(DR-07)に全面委譲 — nonce自動埋め込み/自動検証、PRG(Post/Redirect/Get)
3. **描画**: コア `View\Template`(part/render)による通常のPHPテンプレート

WP Settings API(`register_setting`/`add_settings_section`)は採用しない。Form層と二重のフォーム抽象になるうえ、旧FW利用案件のUI要件(自由なHTML)と合わないため。

## 2.2 クラス仕様

### `TMSWP\SettingsPage\SettingsPages`(パッケージの入口)

```php
final class SettingsPages implements \TMSWP\Core\Kernel\ExtensionInterface
{
    /** @param list<class-string> $pages 属性探索で見つからない(app/の外にある)設定ページのクラス */
    public function __construct(array $pages = []);

    public function register(\TMSWP\Core\Kernel\Kernel $kernel): void;

    /** Kernelに登録したSettingsPages。無ければLogicException */
    public static function forKernel(\TMSWP\Core\Kernel\Kernel $kernel): self;
}
```

`KernelConfig`の`extensions`に`new SettingsPages()`を渡したKernelでだけ、設定ページが有効になる(共通規約「登録API」)。コンストラクタは、WordPressの関数もページの`definition()`も呼ばない。

`register()`の振る舞い(登録したKernelのbootの中で1回):

1. このインスタンスを登録済みなら`TMSWP\Core\Exception\LogicException`(1つのインスタンスは1つのKernelにだけ登録できる)。コンストラクタの`$pages`に`SettingsPage`を継承しないクラスがあれば`LogicException`。
2. 訳の読み込み(`init` priority 1。`load_textdomain('tmswp', <settings-page>/languages/tmswp-{$locale}.mo)`)を`$kernel->defer()`で予約する。
3. `$pages`があれば、`admin_menu`(priority 10)でそれぞれをメニューに登録する処理を`$kernel->defer()`で予約する(属性探索を使わない場合・appDirの外のクラスのための経路)。

ページの`definition()`は、メニューに登録するとき(`admin_menu`。`init`の後)に初めて呼ぶ。`definition()`の中で`__()`を使っても、`init`より前の翻訳の読み込み(WP 6.7以降の`_doing_it_wrong`)にならない。

slugの重複は、経路(属性探索/コンストラクタ)を問わず、起動したすべてのKernelのSettingsPagesにまたがって検出し、`LogicException`にする(管理画面のページはサイトで1つの名前空間を共有するため)。

`#[AsSettingsPage]` 付きクラスの収集に、本パッケージ独自の探索機構・Kernelへのハンドラ登録は**存在しない**。コアDiscoveryの `scan()` は `ComponentAttributeInterface` 実装属性を `getAttributes(ComponentAttributeInterface::class, IS_INSTANCEOF)` でinstanceof収集する(Kernel設計書§4/§5)ため、下記のとおり属性が同インターフェースを実装するだけで、Kernel側の改修・登録テーブルなしに「app/に置くだけ」で収集・スケジュールされる。

### `TMSWP\SettingsPage\Attribute\AsSettingsPage`

```php
<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage\Attribute;

use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\SettingsPage\SettingsPageInitializer;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsSettingsPage implements ComponentAttributeInterface
{
    public function hookName(KernelContext $context): string
    {
        return 'admin_menu';   // Theme/Plugin共通。管理画面以外では発火しない=フロントへの副作用ゼロ
    }

    public function hookPriority(): int
    {
        return 10;
    }

    /** @return class-string<\TMSWP\Core\Kernel\ComponentInitializerInterface> */
    public function initializerClass(): string
    {
        return SettingsPageInitializer::class;
    }
}
```

引数なしのマーカー属性。定義の実体は `definition()` が持つ(コアの `#[AsPostType]` + `definition()` 規約と相似形に揃える)。`admin_menu` はフロント・REST・WP-CLIでは発火しないため、本番(debug=false)の boot では、管理画面以外のリクエストで設定ページクラスのロード・初期化が一切行われない(debug の boot は走査(02-kernel §4)でクラスを読み込む)。

### `TMSWP\SettingsPage\SettingsPageInitializer`

```php
final class SettingsPageInitializer implements \TMSWP\Core\Kernel\ComponentInitializerInterface
{
    public function initialize(\TMSWP\Core\Kernel\DiscoveredComponent $component, \TMSWP\Core\Kernel\Kernel $kernel): void;
}
```

Kernelの初期化スケジューラから `admin_menu`(priority 10)の発火中に、発見コンポーネント1件につき1回呼ばれる(KernelのContainerからシングルトンとして解決される)。`initialize()` の確定アルゴリズム:

```
1. SettingsPages::forKernel($kernel)が、そのKernelに登録したSettingsPagesを返す。
   無ければLogicException(「拡張は自動では何もしない」共通規約のゲート。設定の誤りなので、
   Kernelの初期化(runInitializer)はdebugに関係なく例外をそのまま投げる)
2. $component->class が SettingsPage 非継承 → LogicException
3. definition()->slug を、起動したすべてのKernelのSettingsPagesのslugと照合して登録する。重複slugは LogicException
4. new PageRegistrar($component->class) を生成し registerMenu() を即時実行
   (admin_menu発火中のため add_menu_page/add_submenu_page を直接呼べる。
    registerMenu() が hook_suffix を取得し load-{hook_suffix} へ handleRequest() を接続する)
```

### `TMSWP\SettingsPage\SettingsPageDefinition`

```php
final readonly class SettingsPageDefinition
{
    public string $pageTitle;   // 未指定時はslugから生成(ucwords)
    public string $menuTitle;   // 未指定時はpageTitleへフォールバック

    public function __construct(
        public string $slug,                            // 例: 'site-settings'
        ?string $pageTitle = null,
        ?string $menuTitle = null,
        public string $capability = 'manage_options',
        public ?string $parent = null,                  // 指定でadd_submenu_page。例: 'options-general.php'
        public ?string $icon = null,                    // dashicon名またはURL。top-levelのみ有効
        public ?int $position = null,
    ) {
        // 派生既定値はコンストラクタ内で確定する(getter内の分岐を持たない)
        $this->pageTitle = $pageTitle ?? ucwords(str_replace(['-', '_'], ' ', $slug));
        $this->menuTitle = $menuTitle ?? $this->pageTitle;
    }
}
```

7スカラのみを保持する不変の値オブジェクト(`ThumbnailConfig`・`BridgeConfig` と同じreadonly+コンストラクタ昇格スタイル)。利用側はnamed argumentsで必要な項目だけを指定する: `new SettingsPageDefinition(slug: 'site-settings', pageTitle: __('サイト設定', 'my-theme'), parent: 'options-general.php')`(テキストドメインはアプリ自身のもの。01-architecture.md §7.4)。`PageRegistrar` はgetterを介さずpublic readonlyプロパティを直読みする(PHPStanが型を直接追える)。

注記: コアの `PostTypeDefinition`(エンティティ定義設計書)は流暢ビルダのまま据え置く。あちらは `toArgs()` が「呼ばれなかったセッターのキーを含めない」疎args意味論(WP側既定値への委譲)を持つためビルダ様式に技術的必然があるが、本クラスの7スカラは `add_menu_page`/`add_submenu_page` の位置引数へ流すだけで「不在 vs 既定値」の区別が不要である。この様式差は正当な理由のある既知の許容差分としてrules/にも1行記載する。

### `TMSWP\SettingsPage\SettingsPage`(基底)

```php
abstract class SettingsPage
{
    /** admin_menu以降(initの後)に呼ばれる。__()で訳した文字列を使える */
    abstract public static function definition(): SettingsPageDefinition;

    /** 設定フォーム。フォーム不要の情報表示ページはnullのまま。確認画面の有効なフォームはLogicException */
    protected function createForm(): ?\TMSWP\Core\Form\Form
    {
        return null;
    }

    /** フォーム送信成功時(検証通過+CSRF通過)の保存処理 */
    protected function onSubmit(\TMSWP\Core\Form\FormResult $result): void
    {
    }

    /**
     * ページ本体の描画(echoする)。$formはcreateForm()の戻り(handle済み)。
     * フォームのあるページでは、FWが出す<form>の中(項目の部分)だけを描画する
     */
    abstract protected function render(?\TMSWP\Core\Form\Form $form): void;

    // ===== フレームワーク管理(finalメソッド) =====
    /** このページの生のURL(esc_url前)+クエリの引数。admin_menuで登録した後に使える。未登録ならLogicException */
    final public function url(array $queryArgs = []): string;
}
```

`url()`はmenu_page_url()と同じ規則でURLを組み立てるが、esc_url()を通さない生のURLを返す(menu_page_url()の戻り値は`&`が`&#038;`になっており、リダイレクト先やadd_query_arg()の入力に使えないため)。HTMLに出すときは、出力の時点でesc_url()でエスケープする。

### `TMSWP\SettingsPage\PageRegistrar`(内部クラス)

```php
final class PageRegistrar
{
    /** @param class-string<SettingsPage> $pageClass */
    public function __construct(private readonly string $pageClass) {}

    public function registerMenu(): void;   // admin_menuフックで実行
    public function handleRequest(): void;  // load-{hook_suffix}フックで実行
    public function output(): void;         // メニューコールバック
}
```

確定ライフサイクル:

```
admin_menu:
  definition()を読み、parent有無で add_menu_page / add_submenu_page。
  add_submenu_pageがfalseを返したら(今のユーザーがcapabilityを持たないためWordPressが登録しなかった)、
  正常な結果として何もしない(load-{hook_suffix}も接続しない)。
  戻り値hook_suffixを保持し add_action("load-{$hookSuffix}", handleRequest)

load-{hook_suffix}(画面出力前):
  1. current_user_can(capability) を再確認(失敗は wp_die(..., '', ['response' => 403]))
  2. $form = createForm()。nullなら何もしない。$form->isConfirmEnabled()ならLogicException(設定の誤り)
  3. $result = $form->handle()
     (Form層がメソッド判定・バインド・nonce検証・バリデーション・状態遷移を行う。
      nonce不一致はForm層の仕様に従いエラー終了し、onSubmitへは到達しない)
  4. $result->step === FormStep::Complete のとき:
     a. onSubmit($result) を呼ぶ(保存処理)
     b. wp_safe_redirect(url(['updated' => '1'])); exit  — PRGで再POST防止
        (このexitは設計原則1のexit禁止の例外。00-overview.md §3、D-35)
  5. Formをインスタンスに保持し output() へ引き継ぐ

output():
  1. <div class="wrap"><h1>{pageTitle}</h1> を出力する
  2. $_GET['updated'] === '1' で、POSTでないなら成功notice(.notice-success)を出力
  3. フォームが無ければrender(null)を呼び</div>を閉じる
  4. フォームがあれば:
     a. Formのフォームエラー(formErrors()。nonceの失効等)を1件ずつエラーnotice(.notice-error)で出力
     b. 項目のエラーがあれば、保存しなかったことを示すエラーnoticeを出力
     c. $form->open()(<form>の開始タグと必須のhidden)、render($form)(項目)、
        保存ボタン(<button type="submit" name="_step" value="submit">)、$form->close()の順に出力し</div>を閉じる
```

- 外枠(wrap・見出し・通知・`<form>`の開始タグと必須のhidden・`_step=submit`の保存ボタン・終了タグ)はFWが描画し、render()は項目だけを描画する。`_step`の値は文書05の予約POSTキーのとおり、確認画面の無いフォームの送信(`submit`)である。
- 設定フォームは確認画面を使えない。`createForm()`が確認画面の有効なフォーム(`Form::isConfirmEnabled()`がtrue)を返すと、`handle()`の前に`LogicException`になる(設定の誤り。debugに関係なく投げる)。保存ボタンは`_step=submit`だけを送り、確認画面の有効なフォームは保存できないためである。
- GET時(`handle()` が `FormStep::Input` を返す)は `OptionFormBridge::applyDefaults()`(下記)等で初期値を与える。
- FWの文言(通知・保存ボタン・権限エラー)のソース文字列は英語で、ja訳を`languages/`に同梱する(テキストドメインは`tmswp`)。

### `TMSWP\SettingsPage\OptionFormBridge`

オプショングループ enum(コアOption層。`#[AsOptionGroup]` 付き string backed enum)とFormの橋渡しヘルパ。フィールド名とオプションキー(`OptionSchema` 付き enum ケースの backing 値)が一致するものを、スキーマの型とFormの値の形の間で変換して転送する。両メソッドはオプショングループ enum の class-string を受け取る。

```php
final class OptionFormBridge
{
    /**
     * オプショングループの現在値をFormの初期値へ流し込む(handle()の前)
     * @param class-string $optionGroupClass #[AsOptionGroup] 付きの string backed enum
     */
    public static function applyDefaults(\TMSWP\Core\Form\Form $form, string $optionGroupClass): void;

    /**
     * 検証済みフォーム値をスキーマの型に変換してオプショングループへ保存する(onSubmit時)
     * @param class-string $optionGroupClass #[AsOptionGroup] 付きの string backed enum
     * @param list<string> $only 指定時はこのキーのみ保存。#[OptionSchema]で宣言していない名前はLogicException
     */
    public static function persist(\TMSWP\Core\Form\FormResult $result, string $optionGroupClass, array $only = []): void;
}
```

- 転送規則: `$optionGroupClass` の `OptionSchema` 宣言済みキー集合とフォームのフィールド名集合の積集合のみを転送する。片側にしかない名前は黙って無視せず、オプショングループのKernel(SettingsPagesを登録したKernel)のロガー(`$kernel->logger()`)にdebugで記録する。オプショングループのKernelにSettingsPagesが無ければ`LogicException`。
- 値の読み書きはコア `OptionRepository` を経由する(直接 `get_option` を呼ばない)。
- スキーマの型とFormの値の形の対応:

| スキーマの型 | Formの値の形(applyDefaultsが渡す形・persistが受け付ける形) | 保存する値 |
|---|---|---|
| string | 文字列 | 文字列 |
| integer | 整数の文字列(先頭の`+`と0を許す) | int |
| number | 数値の文字列(`is_numeric()`。有限の値) | 整数ならint、それ以外はfloat |
| boolean | `'1'`(true)・`''`(false)。`Field::checkbox()`の既定のcheckedValue`'1'`とチェックしない場合の値 | bool |
| array | 文字列のlist(複数選択の項目) | 文字列のlist |
| object | 項目と結べない(`LogicException`) | — |

- `applyDefaults()`は、保存値(無ければスキーマの既定値)を上の形に変換して`Form::applyDefaults()`に渡す。値が無いキーは項目の既定値のままにする。WordPressのoptionsテーブルを経た保存値(`update_option()`がintやboolを文字列で保存し、次のリクエストで`'5'`・`'1'`・`''`として読める値)も受け付ける。保存値が型の形でない場合はデータの取得の失敗として扱い、登録したKernelのdebugなら`RuntimeException`、本番ならKernelのロガーにerrorで記録してその項目を項目の既定値のままにする(エラー方針、00-overview.md §5.6)。変換した値の形が項目に合わない場合(array型のキーを単一の値の項目と結んだ等)は、`Form::applyDefaults()`の`FormConfigException`になる。
- `persist()`は、すべての値を変換してから保存する。変換できない値(整数の項目に整数以外を許す等、項目の検証の規則が足りない場合)は`LogicException`にし、どのキーも保存しない。例外のメッセージに利用者の入力値は入れない。`OptionRepository::set()`の戻り値(false=保存値が変わらなかった)は失敗ではないため扱わない。

## 2.3 利用例(app側)

```php
// app/SettingsPage/SiteSettingsPage.php
declare(strict_types=1);

namespace App\SettingsPage;

use TMSWP\SettingsPage\Attribute\AsSettingsPage;
use TMSWP\SettingsPage\{SettingsPage, SettingsPageDefinition, OptionFormBridge};
use TMSWP\Core\Form\{Form, FormDefinition, FormResult};
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\View\Template;
use App\Option\SiteOption;

#[AsSettingsPage]
final class SiteSettingsPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(
            slug: 'site-settings',
            pageTitle: __('サイト設定', 'my-theme'),   // アプリ自身のテキストドメイン(01-architecture.md §7.4)
            parent: 'options-general.php',
        );
    }

    protected function createForm(): ?Form
    {
        $form = new Form(
            FormDefinition::create('site_settings')   // 確認画面なし
                ->text('site_catchphrase', __('キャッチコピー', 'my-theme'), [new MaxLength(100)]),
        );
        OptionFormBridge::applyDefaults($form, SiteOption::class);

        return $form;
    }

    protected function onSubmit(FormResult $result): void
    {
        OptionFormBridge::persist($result, SiteOption::class);
    }

    protected function render(?Form $form): void
    {
        Template::part('parts/admin/site-settings', ['form' => $form]);
    }
}
```

```php
<?php

// parts/admin/site-settings.php — <form>・必須のhidden・保存ボタン・エラーの通知はFWが出す。ここは項目だけ
declare(strict_types=1);

use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Form\Form;

// Template::part()のテンプレートから見える変数は$argsだけ。渡したFormは$args['form']から取り、型を確かめてから使う
$form = $args['form'] ?? null;

if (!$form instanceof Form) {
    throw new LogicException("parts/admin/site-settings.php requires a Form in \$args['form']");
}

$field = $form->field('site_catchphrase');
?>
<table class="form-table">
    <tr>
        <th><?php echo $field->labelTag(); ?></th>
        <td>
            <?php echo $field->input(['class' => 'regular-text']); ?>
            <?php echo $field->errors(); ?>
        </td>
    </tr>
</table>
```

## 2.4 旧iwf2bからの設計根拠

- 旧 `AbstractSettingsPage::action()` は `$_REQUEST['action']` から `{action_dir}/{slug}/{action}.php` を**CSRF検証なしでinclude実行**していた → アクションファイル機構を全廃し、Form層のnonce自動検証へ一本化(本パッケージ最大の新設根拠)。
- `View::load_global()` + `extract()` 依存の画面描画 → `View\Template::part()`(extract禁止、明示変数渡し)へ(DR-16)。
- `%template_dir%` プレースホルダ・`generate_file_name()` 命名規則ディスパッチ → 廃止。描画は `render()` にコードで書く。
- 5プロジェクト16クラスの利用実態(設定値保存+一覧表示が大半)から、必要十分なのは「メニュー登録+フォーム+オプション保存」の3点と判断し、それ以外(タブ・複数action画面)はapp側で `$_GET` 分岐して `render()` 内で実装する方針とする。
- `SettingsPageDefinition` は疎args意味論を持たない純粋な7スカラ値オブジェクトであるため、コア `PostTypeDefinition` のような流暢ビルダにはせず、隣接する `ThumbnailConfig`/`BridgeConfig` と同じreadonly+named argumentsで構成する(`PostTypeDefinition` は `toArgs()` の疎args意味論があるためビルダとする)。

---

# 3. tmswp/ja-toolkit — 日本語サイト対応

## 3.1 設計方針

旧 `Iwf2b\AbstractHook` は10テーマが継承した神クラスで、`initialize()` が40超のフックを**継承一発で無差別に**登録していた(管理メニュー全削除等の破壊的デフォルト+ACF/CF7/Yoast直結合+View乗っ取り)。ACF非使用の5プロジェクトは継承を避けて独自Hookを再実装しており、責務過多が利用側の分裂を招いた。

新設計は**モジュール式の明示オプトイン**(DR-10):

1. 1関心事=1モジュール(final class)。`JaToolkit::enable([...])` に列挙したものだけが有効化される。
2. 収載対象は**日本語サイト固有の課題を解くモジュールとFormプリセットのみ**。管理メニュー・管理バー・ダッシュボード・wp_head 出力等の汎用WPクリーンアップは、削除対象がテーマ毎に異なり一律の既定値が成立しないため**コード収載しない**。app側Hookクラスのレシピ(コアの rules/hooks.md)として提供する(3.6の対応表参照)。
3. クラシックエディタ(TinyMCE・クラシックメタボックス)専用の挙動調整は、WP 7.0 を下限とする本FWでは収載しない。必要案件はapp側Hookクラスで実装する。
4. ACF/CF7/Yoast連携・View乗っ取り(`template_include`+exit)・`load_global` は**本パッケージにも収載しない**(完全廃止。3.6の対応表参照)。
5. 全モジュールはフロント/管理画面の双方で安全に `register()` できる(各モジュールが内部で適切なフックに繋ぐ)。

## 3.2 エントリAPI

### `TMSWP\JaToolkit\JaToolkit`

```php
final class JaToolkit
{
    /**
     * モジュールを有効化する。Kernel::boot()直後(initフック前)に呼ぶこと。
     * @param list<class-string<ModuleInterface>|ModuleInterface> $modules
     *        class-string指定は引数なしコンストラクタで生成(必須引数を持つモジュールはインスタンス渡し)
     */
    public static function enable(array $modules): void;

    /** @param class-string<ModuleInterface> $moduleClass */
    public static function isEnabled(string $moduleClass): bool;
    public static function reset(): void;  // テスト用
}
```

- `enable()` は各モジュールの `register()` を即時呼び出す(モジュール側がadd_action/add_filterするだけなので、`init` 前であればタイミング問題はない)。`init` 発火後の呼び出しは `TMSWP\Core\Exception\LogicException`(`did_action('init')` で判定)。
- 同一モジュールクラスの重複指定・`enable()` 自体の複数回呼び出しのうち既登録クラスを含むものは `LogicException`(別クラスの追加enableは許可)。
- class-string指定でコンストラクタに必須引数がある場合は `LogicException`(メッセージで「インスタンスを渡せ」と案内)。

### `TMSWP\JaToolkit\ModuleInterface`

```php
interface ModuleInterface
{
    /** WPフックの登録のみを行う(副作用の即時実行は禁止) */
    public function register(): void;
}
```

## 3.3 モジュール仕様(`TMSWP\JaToolkit\Module\` 配下)

すべて `final class`・`ModuleInterface` 実装。コンストラクタ引数は全てreadonly。

### 3.3.1 `JapaneseSlug` — 日本語スラッグの自動変換

```php
final class JapaneseSlug implements ModuleInterface
{
    /** @param string $format プレースホルダ: {post_type} {post_id} */
    public function __construct(private readonly string $format = '{post_type}-{post_id}') {}

    public function register(): void;  // add_filter('wp_unique_post_slug', ..., 10, 4)

    public function filterSlug(string $slug, int $postId, string $postStatus, string $postType): string;
}
```

`filterSlug()` の確定アルゴリズム:

```
1. preg_match('/(%[0-9a-f]{2})+/i', $slug) が偽(URLエンコードされた多バイト文字を含まない)
   → $slug をそのまま返す
2. $postId === 0(新規・ID未確定) → $slug をそのまま返す
   (この場合は保存後の再サニタイズで本フィルタが再度呼ばれた時点で変換される。
    旧実装と同一の振る舞いであり、挙動を変えない)
3. $format の {post_type}/{post_id} を置換した文字列を返す(例: 'blog-123')
```

### 3.3.2 `FullwidthSearchNormalizer` — 全角スペース検索の正規化

```php
final class FullwidthSearchNormalizer implements ModuleInterface
{
    public function register(): void;  // add_action('pre_get_posts', ..., 10, 1)

    public function normalize(\WP_Query $query): void;
}
```

`normalize()` の確定アルゴリズム:

```
1. $query->is_search() でない、または query_var 's' が空文字 → 何もしない
2. $s = mb_convert_kana($s, 's', 'UTF-8')  // 全角スペース→半角
3. 半角スペースで分割→空要素を除去→半角スペース1つで再結合(連続スペースの圧縮と先頭末尾のtrimを兼ねる)
4. $query->set('s', $s)
```

旧実装の「`init` で `$_GET['s']` を直接書き換える」方式はスーパーグローバル汚染(他プラグインへの副作用)のため廃止し、クエリオブジェクト側で正規化する。メイン/サブクエリを問わず `is_search()` のクエリ全てに適用する。

### 3.3.3 `DisableTexturize` — wptexturize無効化

```php
final class DisableTexturize implements ModuleInterface
{
    public function register(): void;  // add_filter('run_wptexturize', '__return_false')
}
```

旧実装は `the_title`/`the_excerpt`/`the_content`/`comment_text` から個別に `remove_filter` していたが、ウィジェット等に漏れがあった。WP公式の一括無効化フィルタ `run_wptexturize` に置換する(タイトル・本文・抜粋・コメントすべてに効く)。

## 3.4 Form プリセット

### `TMSWP\JaToolkit\Form\KanaTextField`

カナ入力用の構成済み `Field` を返す静的ファクトリ。WPフックを登録しないため `ModuleInterface` は実装せず、`JaToolkit::enable()` の対象でもない。フォーム定義で直接 `KanaTextField::create()` を呼ぶ。

```php
namespace TMSWP\JaToolkit\Form;

use TMSWP\Core\Form\Field;

enum KanaType
{
    case Katakana;
    case Hiragana;
}

final class KanaTextField
{
    /**
     * @param list<RuleInterface> $rules
     */
    public static function create(
        string $name,
        string $label,
        KanaType $type = KanaType::Katakana,
        array $rules = [],
        int $maxLength = 100,
    ): Field;
}
```

- filters: `mb_convert_kana()` で表記ゆれを正規化する。Katakana は `'KVC'`、Hiragana は `'KVc'` を使う。
- rules: 正規化後の文字種を `Regex` で検証し、`MaxLength($maxLength)` を追加する。`$rules` は先頭に展開されるため、`Required` 等の必須条件を呼び出し側で追加できる。
- メッセージ: ソース文字列は英語で `__('...', 'tmswp')` を通し、日本語訳は ja-toolkit の翻訳ファイルで提供する。

## 3.5 利用例(app側)

```php
// functions.php(Kernel::boot()の直後)
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Form\KanaTextField;
use TMSWP\JaToolkit\Module\{JapaneseSlug, FullwidthSearchNormalizer, DisableTexturize};

JaToolkit::enable([
    JapaneseSlug::class,               // 引数なし=既定値でOK → class-string可
    FullwidthSearchNormalizer::class,
    DisableTexturize::class,
]);
```

```php
FormDefinition::create('contact')
    ->add(KanaTextField::create('kana', 'フリガナ', rules: [new Required()], maxLength: 50));
```

管理画面・wp_head 等の汎用クリーンアップは、コアの rules/hooks.md のレシピに従いapp側Hookクラスで実装する。

## 3.6 旧AbstractHook全フック棚卸し→対応表

旧 `Iwf2b\AbstractHook::initialize()`(658行・実ソース確認済み)の全登録フックと新FWでの処遇。**太字=本パッケージのモジュール**。

| 旧フック登録(AbstractHook) | 内容 | 新FWでの処遇 |
|---|---|---|
| `init` → `init_theme()` | show_acf/hide_acfクッキー切替 | **廃止**(ACF開発者向けトグル。acf-bridgeにも収載しない) |
| `wp_terms_checklist_args` → `terms_checklist_args()` | 選択済みタームの最上部移動を無効化 | 廃止(クラシックメタボックス専用。WP 7.0 下限の本FWでは収載しない。必要案件はapp側Hookクラスで実装) |
| `admin_enqueue_scripts` → `check_tinymce_override()` / `tiny_mce_before_init` → `override_mce_options()` | 固定ページTinyMCEの空タグ削除抑制 | 廃止(TinyMCE=クラシックエディタ専用。同上) |
| `widgets_init` → `remove_default_widgets()` | 標準ウィジェット13種解除 | レシピ化(rules/hooks.md。`widgets_init` priority 11 で `unregister_widget()`) |
| `admin_menu` → `remove_admin_menu()` | 管理メニュー全削除 | レシピ化(rules/hooks.md の `AdminCleanupHook` 例。削除対象はテーマ毎にapp側で明示) |
| `wp_dashboard_setup` → `remove_dashboard_widgets()` | ダッシュボードウィジェット削除 | レシピ化(同上) |
| `wp_before_admin_bar_render` → `remove_admin_bar_menu()` | 管理バーノード削除 | レシピ化(同上。admin_bar_menu+remove_nodeへ近代化) |
| `template_redirect` → `before_filter()` | テンプレ表示前処理(空フック) | 廃止。必要ならapp側 `Hook\Hook` 継承クラスで直接 `add_action` |
| `pre_get_posts` → `query_filter()` | クエリ調整(空フック) | 同上(app側Hookクラス) |
| `template_include` priority 100 → `template_filter()` | **View乗っ取り**: actions/読込+`View::load_global()`+`exit` | **完全廃止**(DR-10。代替なし。WP標準テンプレート階層をそのまま使う) |
| `the_content` priority 1 → `pre_the_content()` | 固定ページのwpautop解除 | 廃止(クラシックエディタ本文専用。必要案件はapp側Hookクラスで実装) |
| `posts_clauses` → `posts_sql_filter()` | SQL調整(空フック) | 廃止(app側Hookクラス) |
| `wp_enqueue_scripts` → `enqueue_scripts()` | アセット読込(空フック) | 廃止(app側Hookクラス) |
| `wp_head` → `head_scripts()` | `var ajaxurl = ...` のインラインscript出力 | 廃止。rules/レシピ化(`wp_add_inline_script`/`wp_localize_script` を案内) |
| `wpcf7_support_html5` / `wpcf7_map_meta_cap` → `cf7_meta_cap()` | **CF7連携** | **廃止**(DR-10/DR-16。コアにも全拡張にも収載しない。必要案件はapp側で2行書く) |
| `wpseo_metabox_prio` → `yoast_seo_metabox_priority()` | **Yoast連携**(メタボックス位置low) | **廃止**(同上) |
| `remove_action wp_head`: shortlink/generator/rsd/wlwmanifest/rest_output_link | headタグ掃除 | レシピ化(rules/hooks.md。wlwmanifest_linkのみ対応不要: WP 6.3で本体撤去済み・本FW下限WP 7.0のため全環境で恒久no-op) |
| `remove_action wp_head`: feed_links_extra | フィードリンク削除 | レシピ化(同上) |
| 絵文字3点セット+`emoji_svg_url` | 絵文字無効化 | レシピ化(同上。admin側remove_actionを含めて完全化) |
| `remove_action welcome_panel` | ウェルカムパネル削除 | レシピ化(同上) |
| `init` → `filter_search_keyword()` | `$_GET['s']` の全角スペース正規化 | **FullwidthSearchNormalizer**(pre_get_posts方式へ刷新) |
| `wp_unique_post_slug` → `auto_post_slug()` | 日本語スラッグ→`{post_type}-{id}` 変換 | **JapaneseSlug**(visibility欠落バグ修正・format設定可) |
| `remove_filter wptexturize` ×4 | テクスチャライズ無効化 | **DisableTexturize**(run_wptexturizeへ一本化) |
| `wp_insert_post` → `save_preview_post()` / `save_preview_postmeta` アクション発火 | プレビュー時メタ保存の基盤 | コアへ移管: `#[WithMetaRevisions]`(オプトイン。Meta設計書が定義) |
| `class_exists('ACF')` ブロック一式: `acf_load_config`/`acf_activated`/`acf/settings/path`/`acf/settings/dir`/`acf/load_field`/`acf_field_auto_export`/`acf_save_preview_postmeta`/`acf/settings/show_admin` | **ACF連携**(同梱パス・JSON自動書出し・表示トグル) | **廃止→acf-bridgeへ移管**(DR-06。acf-json真実源化により自前JSON書出し`acf_field_auto_export`は役割消滅。`acf_save_preview_postmeta`はACF標準で足りるため継承しない。詳細はacf-bridge設計書) |
| `iwf2b/meta/*`・`iwf2b/option/*` フィルタ → `*_with_acf()` ×6 | nullセンチネルのメタ/オプションACF委譲(**フィルタ名不一致で実際は機能していなかった**) | **廃止**(DR-18。後継は `MetaStorageInterface` + `#[UseMetaStorage]` の明示オプトイン。acf-bridgeの `AcfMetaStorage` が実装) |

## 3.7 旧iwf2bからの設計根拠

- 「継承一発・全部入り・破壊的デフォルト」が利用側の分裂(10テーマが継承/5プロジェクトが回避して再実装)を招いた → 1関心事=1モジュールの明示オプトインへ。
- ACF/CF7/Yoast連携のハードコードが、それらを使わないプロジェクトにまで暗黙の結合を強いた → 外部プラグイン制御コードはコア・本パッケージから完全排除(要件4/DR-10)。
- `auto_post_slug`/`yoast_seo_metabox_priority` のvisibility欠落、`$_GET` 直接書き換え等の品質問題 → 全モジュールで型宣言+strict_types+クエリオブジェクト操作に統一。
- 管理画面整理・wp_head掃除等の汎用クリーンアップは削除対象がテーマ毎に変動し、FW側の一律プリセットは「継承一発で全部消える」の変種になりやすい → コード収載をやめ、rules/hooks.md のapp側Hookクラスレシピへ移した(削除対象は必ず案件側で明示する)。
- 旧TinyMCE空タグ抑制・checked_ontop調整・固定ページwpautop解除はクラシックエディタ専用の挙動であり、WP 7.0(ブロックエディタ標準)を下限とする本FWでは収載しない。旧wlwmanifest_link対応も WP 6.3 で本体撤去済みのため対応を持たない。

---

# 受け入れ基準

## 共通

- [ ] 3パッケージとも `composer.json` が共通規約(php>=8.3、tmswp/core ^1.0、PSR-4)に従い、固有依存(ext-gd/ext-mbstring)が正しいパッケージにのみ存在する
- [ ] 全クラスが `declare(strict_types=1)`・PSR-12・final優先・全シグネチャ型宣言・マジックメソッド不使用・`extract()` 不使用
- [ ] 各 `register()`/`enable()` の二重呼び出しが `LogicException` になるユニットテストがある
- [ ] PHPStan level max(szepeviktor/phpstan-wordpress)をbaselineなしで通過: `composer analyse`
- [ ] `composer lint` / `composer test` / `composer qa` が成功

## tmswp/thumbnail

- [ ] 無署名・署名不一致リクエストが403になる(統合テスト)
- [ ] `w=050` 等、URL生成時と異なる表記のパラメータが署名不一致403になる(生文字列payload照合のテスト)
- [ ] `maxDimension` 超過の `w`/`h` が400になる(署名済みでも拒否=多層防御のテスト)
- [ ] 署名済みURLでも `o` の `quality` 範囲外(0・101等)・許可外キー混入が400になる(手順2.5のテスト)
- [ ] `src` に `../` やuploads外URL・リモートURLを与えると `SourceResolver` がnullを返しエンドポイントが404を返す
- [ ] `basedir` と同prefixの別ディレクトリ(例: `uploads-evil/x.jpg`)・uploads外を指す添付メタ(`id:n`)が `SourceResolver` でnullになる(realpath最終ゲートのテスト)
- [ ] 初回リクエストで `uploads/tmswp-thumb/{shard}/{key}.{ext}` が生成され302が返り、`tms_thumb()` の2回目以降の呼び出しがキャッシュ静的URLを直接返す
- [ ] 元画像の `filemtime` 変更でキャッシュキーが変わる
- [ ] `tms_thumb()` が不正入力時に、WP_DEBUG 無効では例外を投げず空文字を返し `Log::warning` し、WP_DEBUG 有効では記録のうえ例外を送出する
- [ ] `wp tmswp thumb clear --days=30` が対象ファイルのみ削除する
- [ ] 検証: `wp-env` 上で `curl -i "$(wp eval 'echo tms_thumb(123, 100, 100);')"` → 302 → 画像取得

## tmswp/settings-page

- [ ] `KernelConfig`の`extensions`に`new SettingsPages()`を渡したKernelで、`#[AsSettingsPage]` クラスがapp/に置くだけでメニュー登録される(wp-env統合テスト。コアDiscoveryの `ComponentAttributeInterface` instanceof収集経由であり、本パッケージ独自の探索コードが存在しないことをコードレビューで確認)
- [ ] KernelにSettingsPagesを登録していない場合、`#[AsSettingsPage]` クラスの初期化がdebugに関係なく `LogicException` になる。1つのSettingsPagesを2つのKernelに登録すると`LogicException`になる
- [ ] コンストラクタに渡したページの`definition()`が、bootでは呼ばれず`admin_menu`(initの後)で呼ばれる
- [ ] nonce欠落・不正nonceのPOSTで `onSubmit` が呼ばれない(CSRFテスト=本パッケージ最重要)
- [ ] `capability` を満たさないユーザーで管理画面のメニューの作成が例外にならず(`add_submenu_page()`のfalseを正常として扱う)、ページ表示・POSTの両方が`wp_die()`の403で拒否される(統合テスト)
- [ ] FWがフォームの外枠(`$form->open()`・`_step=submit`の保存ボタン・`$form->close()`)と、フォームエラー・項目のエラーのエラーnoticeを出し、render()は項目だけを描画する(統合テスト)
- [ ] 送信成功時に302(PRG)が返り、リダイレクト先(`url()`の生のURL)で成功noticeが表示される。`wp_safe_redirect()`の直後の`exit`は、00-overview.mdの原則1の例外の範囲だけにある
- [ ] `OptionFormBridge::applyDefaults`が、int・bool・arrayのオプション(optionsテーブルを経た文字列の形を含む)を項目の既定値の形に変換し、int・boolのオプションを結んだ設定ページが例外にならない(統合テスト)
- [ ] `OptionFormBridge::persist` がOptionSchema宣言済みキーのみをスキーマの型に変換して保存し、未宣言フィールドを保存しない。変換できない値と`$only`の未宣言の名前は`LogicException`になり、どのキーも保存しない
- [ ] 片側にしかない名前と、型の形でない保存値(本番)が、登録したKernelのロガーに記録される
- [ ] slug重複登録(Kernelをまたぐ場合を含む)が `LogicException`
- [ ] gettextのソース文字列が英語で、ja訳(`languages/`)が`init`で読み込まれる(統合テスト)
- [ ] 旧 `action` クエリパラメータに相当するファイルinclude機構が存在しない(コードレビュー項目)

## tmswp/ja-toolkit

- [ ] `JaToolkit::enable()` 未呼び出し時、本パッケージはWPの挙動を一切変更しない(フック登録ゼロ)
- [ ] `init` 発火後の `enable()` が `LogicException`
- [ ] 必須引数モジュールのclass-string指定が `LogicException`
- [ ] 収載モジュールが日本語固有の3つ(`JapaneseSlug` / `FullwidthSearchNormalizer` / `DisableTexturize`)のみで、汎用クリーンアップ系モジュールが存在しない
- [ ] `KanaTextField` がカタカナ/ひらがなの正規化と検証を行う
- [ ] 3.6対応表で本パッケージ担当の各モジュールについて、旧AbstractHook相当の挙動が再現される統合テスト(例: JapaneseSlugで日本語タイトル投稿のスラッグが `{post_type}-{id}` になる、FullwidthSearchNormalizerで `?s=東京%E3%80%80タワー`(全角スペース)の検索クエリが半角正規化される、DisableTexturize有効時にタイトル・本文へwptexturizeが適用されない)
- [ ] CF7/Yoast/ACF関連のフック名(`wpcf7_*`/`wpseo_*`/`acf*`)がパッケージ内に存在しない(grep検証)
- [ ] `$_GET`/`$_POST`/`$_REQUEST` への書き込みが存在しない(grep検証)

検証コマンド(monorepo共通): `composer qa` / `composer test -- --testsuite=unit` / `composer wp:start && composer test:integration`
