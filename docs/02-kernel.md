# 02. Kernel / Discovery / Container 設計書

## 目的

TMStack WordPress Framework の起動機構を定義する。本文書が確定させるのは次の4点である。

1. **Kernel** — テーマ・プラグインから呼ばれる唯一のエントリポイント `Kernel::boot()` の完全仕様。多重boot・複数Kernel共存(テーマ+プラグイン併用)時の独立性を含む。
2. **Discovery / DiscoveryFile** — `app/` 配下に置かれたクラス/enum を PHP8 Attribute で発見し(クラスレベルのコンポーネント属性と、`#[AsHook]` クラスのメソッドレベル `#[Action]` / `#[Filter]` の両方)、その結果を探索コマンド `wp tmswp discover` が探索結果ファイルとして生成する機構。探索結果ファイルはコードと一緒にコミット・デプロイし、本番の boot はそれを読むだけで走査しない。決定的ロード順・探索失敗の明示エラーを保証する。
3. **コンポーネント初期化スケジューリング** — 各コンポーネント種別(`#[AsPostType]` 等)をどのWPフック・どのpriorityで初期化するかの正準タイミング表。即時同期初期化は全面禁止する(DR-04)。
4. **Container** — PSR-11準拠の軽量DIコンテナと、テンプレート向け静的ファサードとの接続方法(DR-09)。

読者はこの文書のみを参照して `packages/core` の `Kernel\*` / `Container\*` / 関連 `Exception\*` を実装できること。

## スコープ(対象パッケージ)

- `packages/core` (tmswp/core)
  - `src/Kernel/Kernel.php` — `TMSWP\Core\Kernel\Kernel`
  - `src/Kernel/KernelConfig.php` — `TMSWP\Core\Kernel\KernelConfig`
  - `src/Kernel/KernelContext.php` — `TMSWP\Core\Kernel\KernelContext` (enum、本文書で新設定義)
  - `src/Kernel/Discovery.php` — `TMSWP\Core\Kernel\Discovery`
  - `src/Kernel/DiscoveryResult.php` — `TMSWP\Core\Kernel\DiscoveryResult` (本文書で新設定義)
  - `src/Kernel/DiscoveredComponent.php` — `TMSWP\Core\Kernel\DiscoveredComponent` (本文書で新設定義)
  - `src/Kernel/DiscoveredHookMethod.php` — `TMSWP\Core\Kernel\DiscoveredHookMethod` (本文書で新設定義)
  - `src/Kernel/DiscoveryFile.php` — `TMSWP\Core\Kernel\DiscoveryFile`(探索結果ファイルの生成と読み込み。§6)
  - `src/Kernel/DiscoverCommand.php` — `TMSWP\Core\Kernel\DiscoverCommand`(WP-CLI `wp tmswp discover`。§3.6)
  - `src/Kernel/ComponentInitializerInterface.php` — `TMSWP\Core\Kernel\ComponentInitializerInterface` (本文書で新設定義)
  - `src/Kernel/ExtensionInterface.php` — `TMSWP\Core\Kernel\ExtensionInterface`(Kernel ごとに登録する拡張の入口。§3.10)
  - `src/Kernel/PsrInterfaceCheck.php` / `src/Kernel/InstalledVersionCheck.php` — boot 時の psr/container・psr/log の検査と、tmswp/core の版の照合(`@internal`。§3.8・§3.9)
  - `src/Attribute/ComponentAttributeInterface.php` — `TMSWP\Core\Attribute\ComponentAttributeInterface` (本文書で新設定義)
  - `src/Attribute/Action.php` — `TMSWP\Core\Attribute\Action` (メソッドレベルフック属性。本文書で新設定義。§5)
  - `src/Attribute/Filter.php` — `TMSWP\Core\Attribute\Filter` (メソッドレベルフック属性。本文書で新設定義。§5)
  - `src/Attribute/AsHook.php` / `src/Hook/Hook.php` / `src/Hook/HookInitializer.php` — `#[AsHook]` 属性・Hook 基底・Hook の Initializer(本文書が正準定義。§5)
  - 各コンポーネント属性は本文書の `ComponentAttributeInterface` 契約に従う。コア属性は6種(`AsPostType` / `AsTaxonomy` / `AsUserRole` / `AsModel` / `AsHook` / `AsOptionGroup`)。属性のパラメータ仕様と各 Initializer の中身は各機能文書が定義する。ただし `AsHook` と Hook 基底・`HookInitializer`、メソッドレベルのフック属性 `#[Action]` / `#[Filter]` は本文書が正準定義する(§5)
  - `src/Container/Container.php` — `TMSWP\Core\Container\Container`
  - `src/Exception/TmswpExceptionInterface.php` / `LogicException.php` / `RuntimeException.php` / `KernelException.php` / `DiscoveryException.php` / `ContainerException.php` / `NotFoundException.php` / `CircularDependencyException.php`(§9)

スコープ外: 各属性クラス(`AsPostType` 等)のパラメータ仕様と各初期化処理の中身(register_post_type の発行内容等)。これらは各エンティティ文書が定義する。本文書はそれらが従うべき**契約(インターフェース)とタイミング**のみを確定する。

## 依存文書

- 01 全体アーキテクチャ文書(リポジトリ構成・コーディング規約・例外方針) — 例外基底の整合を取ること
- エンティティ定義文書(PostType / Taxonomy / UserRole) — `AsPostType` / `AsTaxonomy` / `AsUserRole` 属性と各 Initializer の定義元
- Meta / Option 文書 — `AsOptionGroup` 属性と OptionGroup Initializer の定義元
- Model 文書 — `AsModel` 属性・`Migrator`(`wp tmswp migrate` による適用と、管理画面の通知)の定義元
- Log 文書 — `Log::error()` 等の静的ファサード(本文書は PSR-3 レベル名の静的メソッド `Log::error(string|\Stringable $message, array $context = []): void` が存在することを前提とする)
- QA 文書 — `composer test` / `composer qa` 等の検証コマンド定義元

---

## 全体像

```
テーマ functions.php / プラグイン本体ファイル
        │  Kernel::boot(KernelConfig::theme(...) / ::plugin(...))
        ▼
┌─ Kernel (appNamespaceごとに1インスタンス、staticレジストリで管理) ─┐
│  1. KernelConfig 検証・正規化(ファクトリ内で実施済み)                │
│     WordPress の版・psr/container と psr/log の宣言・tmswp/core の版  │
│     の検査(§3.8・§3.9)                                              │
│  2. Container 生成・コアサービス登録・この Kernel のログのレジストリ  │
│  3. Discovery::discover()                                             │
│       ├─ debug時: Discovery::scan() … appDir を毎回走査              │
│       └─ 本番: DiscoveryFile::load() … 探索結果ファイルのincludeのみ │
│  4. scheduleAll() … #[Action]/#[Filter] メソッドを add_filter で直接  │
│     登録し、コンポーネント初期化を (フック名, priority) ごとに         │
│     add_action 登録                                                    │
│       ※ ここでは一切インスタンス化しない(即時同期初期化の禁止)       │
│  5. コンテキスト別の運用フック登録(有効化・無効化フック)            │
│  6. KernelConfig の extensions を順に register()(§3.10)              │
│  7. Kernel のレジストリへの登録と、boot 時の初期化(エンティティの    │
│     メタスキーマを MetaSchemaRegistry に登録。WP の API は呼ばない)  │
│  8. キャッチアップ(§3.3): 本番の遅延bootの発火済みのグループと、    │
│     boot中にdefer()で発火済みのフックへ予約したもの               │
└────────────────────────────────────────┘
        │ WPライフサイクル進行
        ▼
plugins_loaded(0)/after_setup_theme(0) … #[AsHook] register() 例外手段(オーバーライド宣言クラスのみ)
plugins_loaded(1)/after_setup_theme(1) … #[AsModel] レジストリ登録
init(5)  … #[AsOptionGroup]
init(9)  … #[AsTaxonomy]
init(10) … #[AsPostType]
init(11) … #[AsUserRole]
init(20) … MetaSchema の WordPress への登録(register_*_meta)
宣言フック発火時 … #[Action]/#[Filter] メソッド(Hookクラスはここで初ロード)
```

boot() 時点で発生する副作用は **add_action / add_filter(`#[Action]`/`#[Filter]` メソッドの直接登録) / register_activation_hook / WP-CLIコマンド登録** に限定される。boot はファイルを書かない(探索結果ファイルを書くのは探索コマンドだけ。§3.6)。`register_post_type`・`register_*_meta` 等のWP登録系APIはすべて上記フック発火時に実行される。WordPress の状態を変えない処理として、boot はエンティティのメタスキーマを Kernel の `MetaSchemaRegistry` に登録する(boot 時の初期化。§7)。そのため boot の後なら、init の前でも `meta()` が使える。

Kernel はコアの翻訳(テキストドメイン `tmswp`)を `init`(priority 1)でロードする(ロード手順の正準は 01-architecture §7.3)。CPT/Taxラベル生成は gettext 非依存のテンプレート方式(DR-17)であり、コアの例外・ログメッセージは翻訳対象外の開発者向け英語で固定する。

---

# 設計本体

## 1. KernelContext (enum)

### 役割

Kernel が「テーマとして動いているか、プラグインとして動いているか」を表す。コンテキストによって変わるのは (a) ブート用最速フック名 (b) 有効化・無効化フックの契機、の2点のみ。

### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

enum KernelContext: string
{
    case Theme = 'theme';
    case Plugin = 'plugin';

    /**
     * このコンテキストで利用できる最速の初期化フック名を返す。
     * Theme  => 'after_setup_theme'
     * Plugin => 'plugins_loaded'
     */
    public function bootHook(): string;
}
```

### 振る舞い仕様

- テーマの `functions.php` は `setup_theme` と `after_setup_theme` の間にロードされるため、テーマで利用可能な最速フックは `after_setup_theme`。
- プラグイン本体は通常リクエストでは `plugins_loaded` 発火前にロードされるため、プラグインの最速フックは `plugins_loaded`。mu-plugins も `plugins_loaded` 前にロードされるため Plugin コンテキストをそのまま使える(後述のmu-plugin注意点参照)。
- WordPressのプラグイン有効化サンドボックス(`WP_SANDBOX_SCRAPING`)では、プラグイン本体が `plugins_loaded` 後に読み込まれる。この経路では通常の属性初期化スケジューリングは行わず、`register_activation_hook` / `register_deactivation_hook` だけを登録する(Migrator の管理画面の通知(§3 手順6b)も登録しない。有効化のリクエストは通知を表示せずにリダイレクトするため)。拡張の `register()` も呼ばないが、既定のメタ・オプションのストレージは通常の boot と同じく解決して固定する(§3 手順7a。boot の後の差し替えを受け付けない)。通常リクエストの遅延bootは引き続き例外とする。

## 2. KernelConfig

### 役割

boot に必要な全設定を保持する不変値オブジェクト。生成はファクトリメソッド `theme()` / `plugin()` のみ(正準ブートAPI)。ファクトリ内で検証・正規化まで完了させ、不正な KernelConfig インスタンスは存在し得ないものとする。

### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

final readonly class KernelConfig
{
    /**
     * @param KernelContext $context     動作コンテキスト
     * @param string        $appDir      app/ の絶対実パス(realpath済み・末尾スラッシュなし)
     * @param string        $appNamespace アプリ名前空間ルート(先頭・末尾の \ を除去した正規形。例: 'App')
     * @param string|null   $pluginFile  プラグイン本体ファイルの絶対実パス。Themeコンテキストでは必ずnull
     * @param bool          $debug       デバッグモード(app/ の走査と探索結果ファイルの読み込みの切替・例外化の挙動を切替)
     * @param list<ExtensionInterface> $extensions この Kernel で有効にする拡張(§3.10)
     * @param (\Closure(): array<string, \TMSWP\Core\Log\LogChannel>)|null $logChannels この Kernel のログのチャンネルを返すクロージャ(07-support §5)
     * @param \TMSWP\Core\Label\LabelLanguage $labelLanguage labelLanguage() を指定しない PostTypeDefinition・TaxonomyDefinition のラベル言語(03-entities §2.2)
     */
    private function __construct(
        public KernelContext $context,
        public string $appDir,
        public string $appNamespace,
        public ?string $pluginFile,
        public bool $debug,
        public array $extensions,
        public ?\Closure $logChannels,
        public LabelLanguage $labelLanguage,
    ) {
    }

    public static function theme(
        string $appDir,
        string $appNamespace = 'App',
        ?bool $debug = null,
        array $extensions = [],
        ?\Closure $logChannels = null,
        LabelLanguage $labelLanguage = LabelLanguage::En,
    ): self;

    public static function plugin(
        string $file,
        string $appDir,
        string $appNamespace = 'App',
        ?bool $debug = null,
        array $extensions = [],
        ?\Closure $logChannels = null,
        LabelLanguage $labelLanguage = LabelLanguage::En,
    ): self;

    /** KernelConfigを返すPHPファイル(tmswp-kernel.php)を読み込む(利用例) */
    public static function load(string $file): self;

    /**
     * テーマ・プラグインのルートディレクトリ = dirname(appDir)。探索結果ファイルの置き場で、
     * Log の静的な呼び出しが記録先の Kernel を決める範囲でもある(Kernel::forFile())。
     */
    public function rootDir(): string;

    /**
     * 探索結果ファイルの絶対パス。appDir の親ディレクトリ(テーマ・プラグインのルート)直下に固定する。
     * 形式: rootDir() . '/tmswp-discovery.php'
     * 例: '/path/to/wp-content/themes/my-theme/tmswp-discovery.php'
     */
    public function discoveryFile(): string;
}
```

### 振る舞い仕様(ファクトリ内の検証・正規化)

`theme()` / `plugin()` は以下を順に行い、失敗時は `TMSWP\Core\Exception\KernelException` を投げる(探索失敗の黙殺禁止はここから始まる)。

1. `$appNamespace` を `trim($appNamespace, '\\')` で正規化し、`/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/` に一致しなければ例外。`TMSWP` または `TMSWP\` で始まる名前空間は予約済みとして例外(コア名前空間の走査を構成段階で遮断する)。
2. `realpath($appDir)` が `false`、またはディレクトリでない、または読取不可なら例外。メッセージには渡されたパスをそのまま含める(例: `app directory not found: {path}`)。
3. `plugin()` のみ: `realpath($file)` が `false` またはファイルでないなら例外。さらに `$appDir` が `$file` の属するディレクトリ配下にあることは要求しない(シンボリックリンク構成を許容)。
4. 探索結果ファイルの置き場は `discoveryFile()` が返す `dirname(appDir) . '/tmswp-discovery.php'` に固定し、引数では変えない(テーマなら `<theme>/tmswp-discovery.php`、プラグインなら `<plugin>/tmswp-discovery.php`)。コードと一緒にコミット・デプロイされる場所に置くためである(§6)。探索結果ファイルは Kernel ごとに1つで、同じ親ディレクトリに複数の appDir を置く構成(`<root>/app-a` と `<root>/app-b`)は同じファイルを共有してしまうため、2つ目の boot が `KernelException` になる(§3 手順2-c)。
5. `$debug` が null の場合は `defined('WP_DEBUG') && WP_DEBUG` を採用する。`WP_DEBUG` の真偽で決め、`true` との厳密比較はしない(`1` 等の真値でも debug になる)。debug の判定はこの `KernelConfig::$debug` に一本化し、各モジュールは `WP_DEBUG` を直接参照しない(00-overview §5.6)。拡張も、自分を登録した Kernel の `debug` に従う(§3.10)。
6. `$extensions` に同じクラスの拡張が2つ以上あれば、debug に関係なく例外(`extension {class} is registered more than once in the same kernel`)。別の Kernel が同じクラスの拡張を登録するのは正当である(テーマとプラグインがそれぞれ同じ拡張を使う構成)。
7. `$logChannels` はここでは呼ばない。boot 中に、psr/log の検査(§3.8)の後で1回だけ呼ぶ(`SimpleLogger` 等の `Psr\Log\LoggerInterface` を実装するクラスを、検査より前に読み込まないため。§3 手順3)。null なら遅延デフォルトチャンネル(07-support §5.4)。
8. `$labelLanguage` の既定は `LabelLanguage::En`。`labelLanguage()` を指定しない PostTypeDefinition・TaxonomyDefinition は、ラベルを組み立てる時点(初期化時)にこの言語を使う(03-entities §2.2)。

`theme()` は `pluginFile = null`、`context = KernelContext::Theme` で構築する。`plugin()` は `context = KernelContext::Plugin` で構築する。

### エッジケース

- `appDir` がシンボリックリンク: realpath により実体パスへ解決される。`discoveryFile()` も実体パス基準となる。
- 1つのテーマ・プラグインのルートに複数の appDir を置く: ファクトリは受け付けるが、`discoveryFile()` が同じになるため、2つ目の Kernel の boot が例外になる(§3 手順2-c)。
- WP外(純ユニットテスト)での生成: 手順5の既定値により WP定数なしで構築可能。WP関数は一切呼ばない(`untrailingslashit` 等のWP関数は使用禁止。正規化は `rtrim($path, '/\\')` で行う)。

## 3. Kernel

### 役割

FWの起動・コンポーネント初期化スケジューリング・Kernelインスタンスレジストリ(複数共存管理)を担う。1つの Kernel は1つのアプリ(=1テーマ or 1プラグインの `app/`)に対応し、専用の Container と探索結果ファイル(`DiscoveryFile`)を持つ。

### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use TMSWP\Core\Container\Container;

final class Kernel
{
    /** この tmswp/core の版。boot 時に installed.php の tmswp/core の版と照合する(§3.9)。リリースごとにタグの版へ更新する */
    public const string VERSION = '0.2.0';

    /** @var array<string, self> 正規化済みappNamespace => Kernel のグローバルレジストリ */
    private static array $kernels = [];

    /** @var string|null boot が進行中の Kernel の appNamespace(boot の再入の禁止。§3.1) */
    private static ?string $booting = null;

    /** @var bool WP-CLIコマンド登録済みフラグ(プロセス内で1回のみ登録) */
    private static bool $cliRegistered = false;

    /** @var bool FWの翻訳ローダー(init 1)登録済みフラグ(プロセス内で1回のみ登録) */
    private static bool $textdomainRegistered = false;

    private readonly KernelConfig $config;
    private readonly Container $container;
    private readonly \TMSWP\Core\Log\LoggerRegistry $logger;

    /** @var list<DiscoveredComponent> 発見済みコンポーネント(FQCN昇順) */
    private array $components;

    /** @var list<DiscoveredHookMethod> 発見済みフックメソッド((class, method, hook, priority, kind) 昇順) */
    private array $hookMethods;

    private function __construct(KernelConfig $config);

    /**
     * FWを起動する。テーマはfunctions.php直下、プラグインは本体ファイル直下で呼ぶこと。
     *
     * @throws \TMSWP\Core\Exception\KernelException WordPress 7.0 未満・psr/container と psr/log の非互換・tmswp/core の版の食い違い・logChannels の不正(以上は debug に関係なく常に)・boot の再入(別の Kernel の boot の途中からの boot)・多重boot・名前空間/ディレクトリ衝突・遅延boot(debug時)
     * @throws \TMSWP\Core\Exception\DiscoveryException 探索失敗(debug時)、探索結果ファイルが無い・構成と合わない(本番)
     */
    public static function boot(KernelConfig $config): self;

    /**
     * 起動済みKernelを名前空間で取得する。
     * @throws \TMSWP\Core\Exception\KernelException 未boot
     */
    public static function get(string $appNamespace): self;

    /**
     * FQCNが属するKernelを最長一致で解決する。静的ファサードの正準解決経路。
     * @param class-string|string $class
     * @throws \TMSWP\Core\Exception\KernelException どのKernelにも属さない場合
     */
    public static function forClass(string $class): self;

    /**
     * ファイルの実パス(realpath())を含むルートディレクトリ(KernelConfig::rootDir()。テーマ形態では有効な子テーマのディレクトリも。§3.5)を持つKernelを、最も長く一致するルートで解決する。
     * Log の静的な呼び出しが記録先の Kernel を決める経路(07-support §5.2)。
     * @throws \TMSWP\Core\Exception\KernelException ファイルの実パスが取れない、またはどのKernelのルートにも含まれない場合
     */
    public static function forFile(string $file): self;

    /** @return array<string, self> 正規化済みappNamespace => Kernel */
    public static function all(): array;

    /**
     * 全Kernelレジストリを破棄する。テスト専用。
     * @internal
     */
    public static function reset(): void;

    public function config(): KernelConfig;

    public function container(): Container;

    /** この Kernel のログのレジストリ(KernelConfig の logChannels で宣言したチャンネル)。Container の LoggerRegistry::class と同一 */
    public function logger(): \TMSWP\Core\Log\LoggerRegistry;

    /** @return list<DiscoveredComponent> FQCN昇順 */
    public function components(): array;

    /**
     * コールバックを (フック, priority) の時点で引数なしで実行するよう予約する(§3.3)。通常は add_action() と同じ。
     * bootの中(フックの登録の後からキャッチアップの終わりまで)に発火済みのフックへ予約したものは、bootの最後の
     * キャッチアップの中で実行する。コンポーネントの初期化が、同じKernelの後のpriorityの初期化を待つ処理(03-entities §9.2)と、
     * 拡張(ExtensionInterface)のregister()が、遅延bootでも確実に動かしたいフックの処理の予約に使う
     * @param \Closure(): void $callback
     */
    public function defer(string $hook, int $priority, \Closure $callback): void;
}
```

### boot() の完全仕様

擬似コード。番号は実装順序として正準。

```
boot(config):
  再入の検査(§3.1): 別の Kernel の boot が進行中(self::$booting !== null)なら
        → throw KernelException("cannot boot the TMSWP kernel for namespace '{ns}' while the kernel
          for namespace '{booting}' is booting; ...")
     self::$booting = config.appNamespace とし、以下の手順0〜10を try で囲む。finally で
     self::$booting = null に戻す(成功・例外・プラグインの有効化のサンドボックスでの早期 return のどれでも)
  0. WordPress の版の検査(下記)。7.0 未満なら
        → throw KernelException("TMSWP requires WordPress 7.0 or later (running {version})")
     PsrInterfaceCheck::assertCompatible()     // §3.8。PSR の interface を実装するクラスより先に行う
     InstalledVersionCheck::assertInstalledVersions(Kernel::VERSION)   // §3.9
  1. ns = config.appNamespace
     if self::$kernels に ns が存在
        → throw KernelException("TMSWP kernel for namespace '{ns}' is already booted")
  2. 衝突検査: 既存の各Kernel k について
        a. ns と k.ns が前方一致関係(片方+'\\' がもう一方の接頭辞)
           → throw KernelException(入れ子名前空間の禁止)
        b. config.appDir と k.config.appDir が同一、または一方が他方の配下
           → throw KernelException(appDirの共有・入れ子の禁止)
        c. config.discoveryFile() と k.config.discoveryFile() が同一(同じ親ディレクトリに
           置いた兄弟の appDir。§2)
           → throw KernelException("app directories {appDir} and {k.appDir} share the discovery
             file {path}; ...")。探索コマンドが同じファイルを2つの Kernel の内容で
             上書きし合うのを防ぐ
  3. kernel = new self(config)
     container = new Container()
     logger = new LoggerRegistry(config.logChannels === null ? [] : (config.logChannels)())
        // 戻り値がチャンネル名(文字列)=> LogChannel の空でない配列でなければ
        // → throw KernelException("logChannels must return ...")(debug に関係なく)
     以下のコアサービスを登録:
        container->set(Kernel::class, kernel)                       // 即値
        container->set(KernelConfig::class, config)                 // 即値
        container->set(Container::class, container)                 // 即値(自己参照)
        container->alias(\Psr\Container\ContainerInterface::class, Container::class)
        container->set(DiscoveryFile::class, new DiscoveryFile(config))  // 即値
        container->set(LoggerRegistry::class, logger)                    // 即値(Kernel::logger() と同一)
        container->set(MetaStorageInterface::class, WpMetaStorage::class)      // 既定のメタのストレージ(04 §4.16)
        container->set(OptionStorageInterface::class, WpOptionStorage::class)  // 既定のオプションのストレージ(04 §4.16)
        container->set(Connection::class, fn() => Connection::fromGlobal())     // 06-model §7。boot では解決しない
        container->set(Migrator::class, fn(Container c) => new Migrator(c->get(Connection::class)))
        container->set(Discovery::class, fn(Container c) => new Discovery(
            config, c->get(DiscoveryFile::class),
        ))
  4. result = container->get(Discovery::class)->discover()
     kernel.components = result.components
     kernel.hookMethods = result.hookMethods
     // 本番(debug=false): 探索結果ファイル1つのincludeのみ。Reflectionゼロ・appクラスのロードゼロ
     // 探索結果ファイルが無い・構成と合わない場合は DiscoveryException(§6)
  try:
    5. missed = kernel.scheduleAll()  // §7。遅延bootの検査を最初に行い(debug の遅延bootはここで、何も登録しない
                                      // うちに例外)、add_filter/add_actionを登録する(コンポーネント・MetaSchema・
                                      // #[Action]/#[Filter])。発火済みフックのグループ(本番のみ)を返し、まだ実行しない
    5a. kernel.prepareCatchUp(missed) // キャッチアップの列をmissedで用意する。ここから手順8aまでのdefer()の予約
                                      // (拡張のregister()・boot時の初期化から)は、発火済みのフックならこの列に入る
    6. kernel.registerLifecycleHooks()  // コンテキスト別運用フック(下記)
    6a. TempFileGc::register(kernel)    // フォームの一時ファイルの gc を WP-Cron のフックと tmswp/deactivated に登録する
                                        // (プロセスで1回。05-form §WP-Cronによるgc)。フック登録のみ
    6b. Migrator::listen(kernel)        // Model のテーブル定義と DB の食い違いの通知(admin_notices)と wp tmswp migrate を
                                        // 登録する(06-model §5)。フック登録のみで、Migrator・Connection を解決しない
    7. config.extensions を渡した順に extension->register(kernel)    // §3.10。キャッチアップより前
    7a. container->get(MetaStorageInterface::class)                  // 既定のストレージを解決して固定する(04 §4.16)。
        container->get(OptionStorageInterface::class)                // 拡張が登録した実装、無ければ WpMetaStorage・WpOptionStorage。
                                                                     // 型の合わない factory はここで ContainerException。以後の差し替え
                                                                     // (解決済み id への set()・alias())は ContainerException
    8. self::$kernels[ns] = kernel      // キャッチアップより前に登録する(§3.3)
       kernel.initializeAtBoot()        // boot 時の初期化(§7)。拡張の登録より後、キャッチアップより前
    8a. kernel.catchUp()                // キャッチアップ(§3.3)。列が空なら何もしない
  catch (Throwable e):
    unset(self::$kernels[ns])          // 手順8で登録した Kernel を取り除く(未登録なら何もしない)
    throw e                            // 例外はそのまま投げ直す
  9. WP-CLI環境(defined('WP_CLI') && WP_CLI)かつ未登録なら
        WP_CLI::add_command('tmswp discover', new DiscoverCommand()) を登録(§3.6)
        self::$cliRegistered = true
  10. return kernel
```

**WordPress の版の検査(手順0)**: 対応範囲(WordPress 7.0 以上。01-architecture §5.2)の下限未満は設定の誤りであり、debug に関係なく常に `KernelException` で起動を拒否する(00-overview §5.6)。

- 版は、WordPress が `wp-includes/version.php` で公開するグローバル `$wp_version`(`$GLOBALS['wp_version']`)から取る。文字列でない・空なら版が分からないため、同じく `KernelException`(`cannot determine the WordPress version: ...`)にする。
- 比較は `version_compare($release, '7.0', '<')` で行う。`$release` は `$wp_version` の最初の `-` より前の部分である。WordPress 自身の「Requires at least」の判定(`is_wp_version_compatible()`)と同じく `-RC1`・`-beta2`・`-alpha-12345-src` 等の接尾辞を除くため、7.0 のリリース候補・開発版は 7.0 として受け付け、`6.9-RC1` 等は拒否する。

#### 3.1 多重bootの挙動

- **同一 appNamespace への2回目の boot は常に `KernelException`。** 冪等に既存インスタンスを返す仕様にはしない。理由: 同一 functions.php の二重読込や copy&paste 起因の二重bootは構成バグであり、黙って既存を返すと「2回目の config の差分が無視される」というサイレント失敗になる(旧FWの黙殺文化の排除)。
- 異なる appNamespace + 異なる appDir であれば回数無制限に boot できる(テーマ1 + プラグインN の共存)。
- **boot の途中から別の Kernel を boot することはできない(再入の禁止)。** logChannels(手順3)・拡張の `register()`(手順7)・本番の遅延bootのキャッチアップで動く初期化(手順8a)の中から `Kernel::boot()` を呼ぶと、内側の boot は `KernelException`(進行中の名前空間と、新しく boot しようとした名前空間を含む)になり、外側の boot もその例外で失敗する(レジストリには何も残らない)。理由: 手順8より前の logChannels・拡張の `register()` の時点では外側の Kernel はまだレジストリに無いため、再入を許すと手順1・2の同一・入れ子の名前空間・appDir の検査をすり抜け、`forClass()` の一致が1つに決まる前提(§3.5)も崩れる。キャッチアップの初期化からの boot も、経路ごとに条件を分けず同じく禁止する。正しい構成では boot の中から boot する必要は無い(テーマは functions.php、プラグインはプラグインのファイルで、それぞれ独立に boot する)。進行中の印(`self::$booting`)は boot の終わりに finally で必ず外すため、失敗した boot の後でも次の boot はこの検査に掛からない。
- 例外発生時、レジストリには残らない。手順8のレジストリ登録は手順1〜7がすべて成功してから行い、その後の手順8の boot 時の初期化(§7)か手順8aのキャッチアップ(§3.3)で例外が起きれば、レジストリから取り除いてから例外を投げ直す。
- debug の遅延boot(§3.3)の検査は、手順5 `scheduleAll` の最初、どのフックの登録(`#[Action]`/`#[Filter]` の `add_filter` を含む)よりも先に行う。そのため遅延bootの例外では、フックは1つも登録されず、初期化も実行されない。
- 手順5で、探索結果ファイルの属性を復元できない場合(`DiscoveryException`)も、どのフックの登録よりも先に例外になる。
- 本番の遅延bootのキャッチアップ(§3.3)で初期化が例外(設定の誤り。§7)を投げた場合は、それまでに登録したフックと実行済みの初期化が残る。設定の誤りは直すまで起動できない構成のバグであり、これは許容する。手順6 `registerLifecycleHooks`・手順6a `TempFileGc::register`・手順6b `Migrator::listen` はフック登録のみで例外を投げない。手順7の拡張の `register()` が投げた例外も同じく設定の誤りとしてそのまま伝わり、それまでに登録したフックは残る。

#### 3.2 複数Kernel共存時の独立性(テーマ+プラグイン併用)

サイト内に「TMSWPで作られたテーマ」+「TMSWPで作られたプラグイン複数」が共存するケースを正式サポートする。独立性の保証は以下の通り。

| 共有されるもの | 共有されないもの |
|---|---|
| `Kernel::$kernels` レジストリ(プロセス内static) | Container(コアサービス含め完全に別インスタンス) |
| `tmswp/core` のコード自体(後述の注意参照) | 探索結果ファイル(Kernel ごとに appDir の親ディレクトリに置く) |
| WP-CLI `tmswp discover`(全Kernel横断で1回登録) | Discovery・components / hookMethods リスト |
| | 有効化・無効化フック |
| | ログのチャンネル(`LoggerRegistry`。Kernel ごとに `KernelConfig` で宣言し、呼び出したファイルの Kernel に記録する。07-support §5) |
| | 拡張(`KernelConfig` の `extensions`。Kernel ごとにインスタンスを登録する。§3.10) |
| | ラベル言語の既定(`KernelConfig` の `labelLanguage`) |

- **appNamespace の一意性は boot 時に強制される。** スターター既定の `App` のまま複数のテーマ・プラグインを併用すると2つ目の boot が `KernelException` で停止する。これはPHPレベルでも必然である: 同名 FQCN `App\Foo` が複数の vendor/autoload に登録されると先勝ちで誤ったファイルがロードされるため、FWで救済できない。**rules/ に「配布・併用前提のプラグインは appNamespace を固有名(例: `MyShopPlugin`)へ変更すること」を必須規約として記載する。**
- 同一 (フック, priority) に複数Kernelのグループランナーが並ぶ場合、実行順は `add_action` の登録順=boot順=WPのロード順(プラグイン → テーマ)となる。**Kernel間でコンポーネントの初期化順に依存してはならない**(規約)。あるKernelのapp/クラスが別Kernelのapp/クラスを参照する構成は禁止し、連携が必要な場合はWPフック(do_action/apply_filters)経由とする。
- 注意: 各テーマ・プラグインが自前の `vendor/` に `tmswp/core` を抱えるため、**最初にロードされた `tmswp/core` のコードが全Kernelで共有される**(PHPのクラスは再定義できない)。そのため同じサイトのテーマ・プラグインは同じ版の tmswp/core を同梱することを必須とし、boot 時に `Kernel::VERSION`(読み込まれた core の版)と、読み込まれたすべての vendor の installed.php にある tmswp/core の版を照合して、食い違いを `KernelException` にする(§3.9)。クラスは1組しか使われないが、Composer の installed.php は vendor ごとに読まれるため、食い違いはこのデータで検出できる。

#### 3.3 遅延boot(対象フック逸失)時の挙動

`scheduleAll()` は、どのフックを登録するよりも先に、各 (フック名, priority) グループ(コンポーネントの初期化のグループと、MetaSchema の WordPress への登録(`init` 20))について、そのフックが発火済みかを WordPress のグローバル `$wp_actions` で検査する。`$wp_actions` にキーがある場合(=boot が遅すぎてそのフックが既に発火済み):

- `config.debug === true`: `KernelException("Kernel::boot() was called after the '{hook}' hook had fired. Boot from the top level of functions.php or the plugin main file.")` を投げる。発火済みのグループが複数あるときは、下記の順で最初のグループのフック名を示す。`#[Action]`/`#[Filter]` の `add_filter` を含め、どのフックも登録せず、どのランナーも実行しない。
- `config.debug === false`(本番): その Kernel のログ(`Kernel::logger()->error()`)に同内容を記録した上で、**当該グループのランナーを即時実行する(キャッチアップ)**。キャッチアップは、コアのフックの登録(手順5〜6a)、拡張の登録(手順7)、Kernel のレジストリ(`self::$kernels`)への登録(手順8)の後に実行する(手順8a)。拡張が Container に入れるサービスを、キャッチアップの初期化でも、init 未発火の通常の boot と同じく使えるようにするためである。レジストリに先に登録するのは、キャッチアップで動くアプリのコード(コンポーネントの初期化、`register()` を上書きした `#[AsHook]` 等)が、`Kernel::forClass()` や `Log` の静的な呼び出し(`Kernel::forFile()`)でこの Kernel を引けるようにするためである。キャッチアップの途中で例外が起きたら、レジストリからこの Kernel を取り除いてから例外を投げ直す(boot は例外になり、起動していない Kernel はレジストリに残らない。§3.1)。debug の遅延bootは従来どおり、何も登録しないまま例外にする(下記)。サイト全体の機能停止より縮退動作を優先する。キャッチアップは発火済みフックのグループのみで、未発火フックのグループは通常通り `add_action` される(未発火のグループの実行順は WordPress が priority で決める)。

キャッチアップの順序は、**WordPress でフックが最初に発火した順**、同じフックの中では **priority の昇順**とする(同じ (フック, priority) のグループは1つにまとめてあるため、この2つで順序が決まる)。プラグインの遅延 boot では `plugins_loaded` のグループが `init` のグループより先に動く。最初に発火した順は `$wp_actions` のキーの並びから取る。`do_action()` / `do_action_ref_array()` はフックが初めて発火したときに `$wp_actions` へキーを足し、2回目以降は値(発火回数)を増やすだけなので、キーの並びが最初に発火した順になる(WP 7.0 の wp-includes/plugin.php の `do_action()`・`do_action_ref_array()`。PHP の配列は挿入順を保つ)。`$wp_actions` が配列でなければ発火済みのフックが分からないため、`KernelException` を投げる。

**初期化からの予約(`defer()`)**: コンポーネントの初期化が、同じKernelの後のpriorityの初期化を待って行う処理(builtinのタクソノミーの紐付けの再試行を`init` 11で行う。03-entities §9.2)は、`add_action()`ではなく`Kernel::defer($hook, $priority, $callback)`で予約する。拡張(`ExtensionInterface`)の`register()`も、遅延bootでも確実に動かしたいフックの処理を`defer()`で予約してよい(例: acf-bridgeの`init` 6の管理キーの申告。09-acf-bridge §2.1)。bootの外(bootの後のフックの中等)では`add_action($hook, $callback, $priority, 0)`と同じである。bootの中(フックの登録(手順5)の後からキャッチアップ(手順8a)の終わりまで。拡張の`register()`、boot時の初期化、キャッチアップ中の初期化)で発火済みのフックへ予約した場合は、`add_action()`では二度と実行されないため、キャッチアップの列の(最初に発火した順, priority)の位置(同じ位置のグループの後)に入れて、bootの最後のキャッチアップの中で実行する。拡張の`register()`が本番の遅延bootで`init` 6に予約したものは、`init` 10のコンポーネントの初期化と`init` 20(MetaSchemaのWordPressへの登録)より前に実行される。テーマのKernelの拡張が、テーマの読み込みの前に発火済みの`plugins_loaded`へ予約したものも、遅延bootでなくても同じくキャッチアップの中で実行される(debugでも実行する。debugの遅延bootの例外は、手順5でこれより先に投げる)。例えば`init` 9のグループの中で`init` 11に予約したものは、`init` 10のグループと`init` 11のグループ(ロールの登録)の後、`init` 20(MetaSchemaのWordPressへの登録)の前に実行される。予約したものには遅延bootのログを記録しない(ログは発火済みのグループごとに1回)。未発火のフックへの予約は、キャッチアップ中でも`add_action()`で登録する。

これは「即時同期初期化の禁止」(DR-04)の例外ではなく、誤用に対する縮退仕様である。正常なboot位置(functions.php / プラグイン本体の直下)では、`scheduleAll()`のグループ(コンポーネントの初期化とMetaSchemaのWordPressへの登録)の遅延初期化のキャッチアップは発生しない。ただし正常な位置でも、bootの中で`defer()`が発火済みのフックへ予約したもの(テーマのKernelの拡張が`plugins_loaded`へ予約したもの等)は、上の「初期化からの予約」のとおり、bootの最後のキャッチアップの中で実行する(遅延bootのログは記録しない)。

#### 3.4 registerLifecycleHooks() — テーマとプラグインの差異

| 項目 | Theme | Plugin |
|---|---|---|
| ブート用最速フック | `after_setup_theme` | `plugins_loaded` |
| 有効化契機 | `after_switch_theme` アクション | `register_activation_hook(pluginFile, ...)` |
| 無効化契機 | `switch_theme` アクション(切替元テーマ側で発火) | `register_deactivation_hook(pluginFile, ...)` |
| 有効化時処理 | `delete_option('rewrite_rules')` + `tmswp/activated` の発火 | `delete_option('rewrite_rules')` + `tmswp/activated` の発火 |
| 無効化時処理 | `delete_option('rewrite_rules')` + `tmswp/deactivated` の発火 | `delete_option('rewrite_rules')` + `tmswp/deactivated` の発火 |

具体仕様:

1. **Plugin コンテキスト**: `register_activation_hook($config->pluginFile, $callback)` を登録する。callback は (a) `delete_option('rewrite_rules')` (b) `do_action('tmswp/activated', $kernel)` を行う。(b) はアプリと拡張が有効化時の処理を購読するための契約フックである。Model のテーブル定義は有効化では適用しない(適用は `wp tmswp migrate` だけ。Model文書 §5)。`register_deactivation_hook` の callback は `delete_option('rewrite_rules')` と `do_action('tmswp/deactivated', $kernel)` を行う。`tmswp/deactivated` は Form の `TempFileGc` が購読し、無効になる Kernel がレジストリの最後の1つなら、一時ファイルの gc の WP-Cron のイベントを解除する(05-form §WP-Cronによるgc)。
2. **Theme コンテキスト**: `add_action('after_switch_theme', ...)` で (a) `delete_option('rewrite_rules')` (b) `do_action('tmswp/activated', $kernel)`。`add_action('switch_theme', ...)`(切替「元」テーマの最終リクエストで発火)で `delete_option('rewrite_rules')` と `do_action('tmswp/deactivated', $kernel)` を行う。
3. **rewrite ルールの作り直し**: Kernel はルールをその場で作り直さず、`rewrite_rules` のオプションを消すだけにする。WordPress は `rewrite_rules` が空なら、次にルールが必要になったときに作り直す(`WP_Rewrite::wp_rewrite_rules()`)。そのとき `wp_loaded` がまだなら、ルールが揃っていないため保存せず、`wp_loaded` で作り直して保存する(`WP_Rewrite::refresh_rewrite_rules()` と `flush_rules()`。WP 7.0 の wp-includes/class-wp-rewrite.php)。`wp_loaded` は `init` より後なので、作り直したルールはそのリクエストで登録された CPT・タクソノミーを反映する。
   - 有効化の次のリクエストでは、有効にしたテーマ・プラグインの CPT を含むルールになる(有効化のリクエストでは CPT が未登録のため、その場で作り直すと新しいルールが入らない)。
   - 無効化の次のリクエストでは、無効にしたテーマ・プラグインは読み込まれないため、その CPT のルールが残らない(無効化のリクエストでは CPT が登録されたままなので、その場で `flush_rewrite_rules()` するとルールが残る)。
   - 有効化の確認のためのオプションや、毎リクエストの `init` での確認は持たない。通常のリクエストで Kernel が DB のクエリを増やさない。
   - 同じリクエストのうち `delete_option` の後(`wp_loaded` の後)に他のコードがルールを求めると、WordPress はそのリクエストの登録状態でルールを作って保存する。有効化・無効化のリクエストは通常、直後にリダイレクトするため、この経路は通らない。
4. **mu-plugin**: `KernelConfig::plugin()` で動作するが、mu-plugin には有効化フックが発火しない(`tmswp/activated` も発火しない)。Model のテーブル定義の適用は、mu-plugin でも通常のプラグイン・テーマと同じく `wp tmswp migrate`(Model文書 §6)で行う。

#### 3.5 get() / forClass() / all() / reset()

- `get($appNamespace)`: 入力を `trim($appNamespace, '\\')` で正規化して完全一致検索。不在なら `KernelException("no TMSWP kernel booted for namespace '{ns}'")`。
- `forClass($class)`: レジストリ内の各 ns について `str_starts_with($class, $ns . '\\')` を判定し、一致した中で最長の ns を持つ Kernel を返す(boot時に入れ子名前空間を禁止しているため一致は高々1つだが、アルゴリズムとしては最長一致で定義する)。一致なしは `KernelException("class '{fqcn}' does not belong to any booted TMSWP application")`。null や空文字を返すサイレント失敗は禁止。
- `forFile($file)`: `realpath($file)` でファイルを実パスにする(シンボリックリンクや `..` を含む別の表記でも、実体の場所で判定する。存在しない等で実パスが取れなければ、パスを含む `KernelException("cannot resolve the real path of '{file}' to find its TMSWP kernel")`)。そのうえで、レジストリ内の各 Kernel の各ルート(実パス)について `str_starts_with($realFile, $root . DIRECTORY_SEPARATOR)` を判定し、一致した中で最長のルートを持つ Kernel を返す。Kernel のルートは `$kernel->config()->rootDir()` で、Theme コンテキストの Kernel では、有効な子テーマのディレクトリ(`get_stylesheet_directory()` の実パス)が親テーマのディレクトリ(`get_template_directory()` の実パス)と異なる場合に、それもルートに含める(`Template::part()` は子テーマのテンプレートを先に探すため、子テーマのテンプレートから `Log` を呼べるようにする)。子テーマのディレクトリは判定のたびに取る。Theme コンテキストの Kernel は functions.php で boot され、WordPress 7.0 の wp-settings.php は `setup_theme` の発火と `wp_templating_constants()`・`wp_set_template_globals()` の後に有効なテーマの functions.php を読み込むため、レジストリにテーマの Kernel がある時点で有効なテーマは決まっている。テーマのディレクトリの実パスが取れなければ `KernelException`。ルートは入れ子になりうる(例: テーマの中にプラグインのルートがある。appDir の入れ子は boot で禁止しているが、ルートの入れ子は禁止しない)ため、最長一致で定義する。一致なしは `KernelException("file '{file}' is not inside the root directory of any booted TMSWP kernel")`(実パスが表記と異なるときは `(real path '{realFile}')` も含める)。Log の静的な呼び出しが記録先の Kernel を決めるのに使う(07-support §5.2)。
- `all()`: レジストリのコピーを返す(内部配列への参照を漏らさない)。キーは正規化済みappNamespace。
- `reset()`: プロセス内の静的な状態を起動前に戻す。行うのは次の5つだけである。(a) `self::$kernels = []`(Kernel のレジストリ) (b) `self::$cliRegistered = false`(`wp tmswp discover` の登録済みフラグ) (c) `self::$textdomainRegistered = false`(FW の翻訳を読み込む `init` 1 のローダーの登録済みフラグ) (d) `MigrateCommand::reset()`(`wp tmswp migrate` の登録済みフラグ。Model文書) (e) `TempFileGc::reset()`(フォームの一時ファイルの gc のフックの登録済みフラグ。05-form §WP-Cronによるgc)。これにより、reset 後の boot はこれらの登録をもう一度行う。boot の進行中の印(`self::$booting`)は boot の finally で必ず外れる(§3.1)ため、reset() では扱わない。登録済み `add_action` の解除は行わない(Brain Monkey 環境ではフック自体がモックであり、統合テストでは1テスト1プロセスで運用する)。`@internal` を付与し、PHPStan ルールで、PHPUnit の `TestCase` の子孫クラスの外からの参照を報告する(識別子 `tmswp.kernelReset`)。ルールは PHPStan が渡すノードの種類ごとに3つあり(`KernelResetOutsideTestRule`=静的呼び出しとインスタンス経由の呼び出し、`KernelResetStaticCallableOutsideTestRule`=`Kernel::reset(...)`、`KernelResetMethodCallableOutsideTestRule`=`$kernel->reset(...)`)、判定は `KernelResetCallChecker` が共有する。packages/core/phpstan/extension.neon で登録する。
  - メソッド名: `reset` の識別子と、型が定数文字列で `reset` に一致する式(`Kernel::{'reset'}()`、`$method = 'reset'; Kernel::$method(...)`。大文字・小文字を区別しない)。定数に定まらない動的な名前は判定できないため対象外。
  - 呼び出し先: クラス名(`use` の別名を含む)、式の型のオブジェクトのクラス名(`$kernel::reset()`・`$kernel->reset()`)、`class-string<Kernel>` の型、定数文字列のクラス名のどれかが `Kernel`(またはそのサブクラス)であるもの。
  - テストのクラスの中(クロージャを含む)は許可される。`call_user_func` 等に渡す callable の配列・文字列(`[Kernel::class, 'reset']`、`'TMSWP\Core\Kernel\Kernel::reset'`)は対象外である。

#### 3.6 WP-CLI コマンド

boot 時、`WP_CLI` 環境であれば次の1コマンドを登録する(プロセス内1回のみ)。

```
wp tmswp discover [--check]
```

実装は `TMSWP\Core\Kernel\DiscoverCommand` で、`Kernel::all()` の全 Kernel について、debug に関係なく `Discovery::scan()`(§4)で appDir を走査し、各 Kernel の探索結果ファイル(§6)を書く。

- 全 Kernel の走査を終えてから書く(後の Kernel の走査の失敗で、前の Kernel のファイルだけが更新された状態を作らない)。内容が同じファイルは書き換えない。
- 走査の失敗・書き込みの失敗は `WP_CLI::error()` で非0終了する。起動中の Kernel が1つも無い場合も `WP_CLI::error()` にする(照合が何も比べずに成功しないようにする)。
- `--check` はファイルを書かず、メモリ上で生成した内容と各 Kernel のファイルをバイト単位で比べる。ファイルが無い、または内容が違う Kernel があれば、その appNamespace とファイルパスを列挙して `WP_CLI::error()` で非0終了する。CI はこれで探索結果ファイルが最新かを照合する(10-quality §5)。
- 本番(debug=false)で探索結果ファイルが無い・構成と合わない場合は boot が例外になる(§4)ため、そのサイトでは WP-CLI のコマンドも動かない。探索結果ファイルは debug の環境(開発環境)で生成し、コードと一緒にデプロイする。

マイグレーション系コマンド(`wp tmswp migrate` 等)は Model 文書が同じ `tmswp` トップレベル名前空間配下に定義する。

#### 3.7 テンプレート前処理とテンプレート階層

Kernel はテンプレート階層とテンプレートの選択に介入しない。boot は `template_include`・`template_redirect` 等のテンプレートのフックに何も登録しない(原則1)。

- テーマ形態のテンプレート単位の前処理(表示する値の用意・フォームの駆動・PRG リダイレクト)は、テンプレートの先頭で app 層の前処理クラスを明示的に呼んで行う(`$vm = ContactPage::handle();`。DR-19)。前処理クラスはコンポーネント属性を持たず、Discovery の発見対象・探索結果ファイル・初期化タイミング表のいずれにも現れない。前処理クラスの規約は 07-support §1A が定義する。
- プラグイン形態はトップレベルのテンプレートを供給しないため、前処理クラスを使わない。テンプレートを持たない文脈(ショートコード等)の前処理は `#[AsHook]` クラスの `#[Action]`/`#[Filter]` メソッド(§5)へ書く(05-form §5)。

#### 3.8 psr/container・psr/log の非互換の検出

TMSWP の `Container` は `Psr\Container\ContainerInterface` を、`LoggerRegistry`・`ScopedLog`・`SimpleLogger` は `Psr\Log\LoggerInterface` を実装し、レベル別メソッドを `Psr\Log\LoggerTrait` から取り込む。PHP は interface・trait を最初に読み込んだ定義で固定するため、他のプラグインや WP-CLI の phar が古い psr/container・psr/log を先に読み込むと、TMSWP のクラスを読み込んだ時点で宣言が合わずに Fatal error になる。例えば WP-CLI の phar は psr/container 1.0 を含み、その `get($id)` は引数の型が無いため、`Container::get(string $id)` は引数の型を狭めることになる。

boot は手順0で、PSR の interface を実装する TMSWP のクラスを読み込む前に、`PsrInterfaceCheck::assertCompatible()` で次の宣言を検査する。対象は、TMSWP のクラスが実装・取り込むメソッドに限る(Fatal error になるのはこれらの宣言が合わないときである)。

| interface・trait | 要求する版 | 照合する宣言(引数の型の並びと戻り値の型) |
|---|---|---|
| `Psr\Container\ContainerInterface` | psr/container ^2.0 | `get(string)`、`has(string): bool` |
| `Psr\Log\LoggerInterface` | psr/log ^3.0 | レベル別の8メソッドが `(string\|Stringable, array): void`、`log((型なし), string\|Stringable, array): void` |
| `Psr\Log\LoggerTrait` | psr/log ^3.0 | LoggerInterface と同じ9メソッド(`log` は abstract) |

- 宣言は、tmswp/core の composer.json が要求する版(psr/container 2・psr/log 3)と同じであることを求める。メソッドの過不足(interface のメソッド、trait の abstract メソッド)も違いとして扱う。union 型はメンバーを並べ替えて比べる。
- 未読み込みの interface・trait は、この検査で(最初に登録されたオートローダから)読み込む。読み込まれた定義がそのまま検査の対象になる。
- 合わなければ debug に関係なく `KernelException` を投げる。メッセージには、要求する版、その interface・trait を定義したファイル(`ReflectionClass::getFileName()` をそのまま。WP-CLI の phar なら `phar://.../vendor/psr/container/...` になり、原因が分かる)、違う宣言を含める。
- 利用条件(他のプラグインや WP-CLI の phar が古い psr/container・psr/log を先に読み込む構成では動かないこと、検出されたときの対処)は 01-architecture §3.3 と packages/core/README.md に記す。

#### 3.9 tmswp/core の版の照合

boot は手順0で、`InstalledVersionCheck::assertInstalledVersions(Kernel::VERSION)` により、Composer の `InstalledVersions::getAllRawData()`(登録済みのすべての Composer のオートローダの vendor の installed.php のデータ)にある tmswp/core の版を `Kernel::VERSION` と照合する。

- 照合の対象は、tmswp/core を実際に入れた記載(`pretty_version` がある記載)のうち、版がリリース版の形(`x.y.z`。タグの `vX.Y.Z` の先頭の `v` は除いて比べる)のものに限る。
- **dev 版は照合の対象外とする。** `dev-*` のブランチ、monorepo の path repository、`x.y.x-dev` のブランチの別名、プレリリース(`x.y.z-beta1` 等)は、`Kernel::VERSION` と比べられる版を持たないためである。replace・provide だけの記載(monorepo のルートが `tmswp/core` を replace する等)も、tmswp/core を入れたものではないため対象外とする。これは黙った代替経路ではなく、照合の範囲の定義である。
- 対象の版が `Kernel::VERSION` と食い違えば、debug に関係なく `KernelException` を投げる。メッセージには、食い違う版と、その記載の `install_path`(`{vendor}/composer/../tmswp/core` の形で、どの vendor かが分かる)を含める。
- `Composer\InstalledVersions` が無い(Composer 2 のオートローダを通さずに読み込んだ)場合は、照合できないため `KernelException` を投げる。
- `Kernel::VERSION` は、リリースのたびにタグの版(先頭の `v` を除く)へ更新する。

#### 3.10 拡張の登録

拡張パッケージ(acf-bridge・settings-page・thumbnail・ja-toolkit)は、`KernelConfig` の `extensions` に渡したインスタンスだけで有効になる(各拡張の移行は 08・09 の担当版で行う)。

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

interface ExtensionInterface
{
    /**
     * 登録した Kernel の boot 中に1回呼ばれる。debug は $kernel->config()->debug、ログは $kernel->logger()、
     * サービスは $kernel->container() から取る。投げた例外は boot の例外としてそのまま伝わる。
     */
    public function register(Kernel $kernel): void;
}
```

- boot の手順7 で、`extensions` に渡した順に1回ずつ `register($kernel)` を呼ぶ。本番の遅延bootのキャッチアップ(手順8a)より前なので、拡張が Container に入れたサービスはキャッチアップの初期化でも使える。
- 拡張の `register()` は、Kernel の既定のメタ・オプションのストレージ(Container の `MetaStorageInterface`・`OptionStorageInterface` の id)を別の実装に差し替える唯一の場所でもある(04-meta-options §4.16。テストでメモリの実装に差し替える用途)。boot は手順7aでこの2つの id を解決して固定するため、boot の後の差し替えは Container が `ContainerException` にする。プラグインの有効化のサンドボックス(`WP_SANDBOX_SCRAPING`。§1)では、コンポーネントの初期化と同じく呼ばない。`register()` の中から別の Kernel を boot すると `KernelException` になる(boot の再入の禁止。§3.1)。
- 拡張は、受け取った Kernel の debug に従い(`WP_DEBUG` を直接見ない)、ログは受け取った Kernel の `logger()` に記録する(07-support §5.1)。拡張のクラスは先に読み込まれた tmswp/* の1組が全 Kernel で共有されるため、プロセス全体の static な状態に Kernel ごとの設定を持たない。
- 同じ Kernel に同じクラスの拡張を2回登録すると、`KernelConfig` の生成時に例外になる(§2 手順6)。テーマとプラグインが、それぞれの Kernel に同じクラスの拡張を登録するのは正当である。
- 拡張のコンストラクタはWordPressの関数を呼ばない。WordPressに触れる処理(フック・オプション・テーマやプラグインのパスの取得等)は`register()`以降で行う。拡張は`tmswp-kernel.php`(利用例)の中で`KernelConfig`と一緒に生成され、このファイルはWordPressを起動しない`tms-acf`(09-acf-bridge §5.1)も読むためである。`KernelConfig::theme()`・`plugin()`もWordPressの関数を呼ばない。

## 4. Discovery

### 役割

`appDir` 配下のPSR-4クラス/enumを列挙し、(a) コンポーネント属性(`ComponentAttributeInterface` 実装属性)が付与されたクラス/enumを `DiscoveredComponent` のリストとして、(b) `#[AsHook]` クラスのメソッドレベルフック属性(`#[Action]` / `#[Filter]`。§5)を `DiscoveredHookMethod` のリストとして収集する。`discover()` が正面玄関で、debug 時は `scan()` で appDir を走査し、本番(debug=false)は探索結果ファイル(§6)を読む。

### 走査対象の原則

1. **走査対象は `config.appDir` 配下の `.php` ファイルのみ。** コア(`TMSWP\`)・拡張パッケージ・vendor/ は決して走査しない。
   - 旧iwf2bのLoaderは自分自身(`src/Iwf2b`)を自己スキャンし、コア同梱の具象クラス(Post/PagePost/CategoryTax/PostTagTax、abstract修飾漏れのAbstractUser)まで毎リクエスト自動インスタンス化していた。本FWではコア側にコンポーネント属性を持つ具象クラスを置かないことに加え、**走査範囲を構成上 appDir に限定することで構造的に再発を防ぐ**。`KernelConfig` ファクトリの `TMSWP` 名前空間予約(§2)が二重の防壁となる。
2. app/ のクラスは利用側 composer の PSR-4 オートロード(`"App\\": "app/"`)で解決可能でなければならない。Discovery は **ファイルを自前で require しない**。走査では `class_exists()` 経由(=オートローダ経由)でロードする。理由: 本番のランタイムと同一のロード機構で検証することで「走査では見えるが実行時にロードできない」乖離を排除する。オートロード未設定の場合は全クラスが探索失敗として明示エラーになる(後述)。
3. 属性はクラス自身に付与されたもののみを見る。**PHPの属性は継承されないため、基底クラスに付与しても子クラスは発見されない**。各具象クラスに付与する(rules/ に明記)。メソッドレベルの `#[Action]` / `#[Filter]`(§5)は `ReflectionClass::getMethods()` が返す public インスタンスメソッド(継承メソッドを含む)を対象とする。

### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

final class Discovery
{
    public function __construct(
        private readonly KernelConfig $config,
        private readonly DiscoveryFile $file,
    ) {}

    /**
     * debug 時は appDir を走査し、それ以外は探索結果ファイルを読む(走査しない)。
     *
     * @throws \TMSWP\Core\Exception\DiscoveryException 走査の失敗、または探索結果ファイルが無い・構成と合わない場合
     */
    public function discover(): DiscoveryResult;

    /**
     * appDir を走査する。失敗は debug に関係なく DiscoveryException にし、途中までの結果は返さない。
     *
     * @throws \TMSWP\Core\Exception\DiscoveryException
     */
    public function scan(): DiscoveryResult;
}
```

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

final readonly class DiscoveryResult
{
    /**
     * @param list<DiscoveredComponent>  $components  (class, attributeClass) 複合キー昇順
     * @param list<DiscoveredHookMethod> $hookMethods (class, method, hook, priority, kind) 複合キー昇順
     */
    public function __construct(
        public array $components,
        public array $hookMethods,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use TMSWP\Core\Attribute\ComponentAttributeInterface;

final readonly class DiscoveredComponent
{
    /**
     * @param class-string         $class              コンポーネントクラスFQCN
     * @param class-string         $attributeClass     付与されていたコンポーネント属性のFQCN
     * @param array<array-key, mixed> $attributeArguments ReflectionAttribute::getArguments() の生値(スカラー/配列/enumのみ)
     */
    public function __construct(
        public string $class,
        public string $attributeClass,
        public array $attributeArguments,
    ) {}

    /**
     * 属性インスタンスを再構築する。呼び出しごとに新規生成(軽量・readonlyのためメモ化しない)。
     * 属性クラスが存在しない/ComponentAttributeInterface非実装/属性の生成で例外(引数の不一致による ArgumentCountError・
     * TypeError 等の Error を含む)が起きた場合は DiscoveryException。生成で起きた例外は previous に持ち、メッセージには
     * 探索結果ファイルの作り直しの案内(`wp tmswp discover`)を含める。コンストラクタが投げた DiscoveryException は包まずにそのまま投げる
     * (探索結果ファイルが古いシナリオ。設定の誤りのため、本メソッドも呼出側の Kernel も debug に関係なく投げる)。
     */
    public function attribute(): ComponentAttributeInterface;
}
```

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

final readonly class DiscoveredHookMethod
{
    /**
     * @param class-string $class        #[AsHook] クラスのFQCN
     * @param string       $method       public インスタンスメソッド名
     * @param string       $hook         WPフック名(#[Action]/#[Filter] の第1引数)
     * @param int          $priority     属性宣言のpriority(既定 10)
     * @param int          $acceptedArgs メソッドの宣言引数数から自動導出(手書き不可。§5)
     * @param HookMethodKind $kind       宣言した属性の種類(HookMethodKind::Action / HookMethodKind::Filter)
     */
    public function __construct(
        public string $class,
        public string $method,
        public string $hook,
        public int $priority,
        public int $acceptedArgs,
        public HookMethodKind $kind,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

enum HookMethodKind: string
{
    case Action = 'action';
    case Filter = 'filter';

    /** WordPress に登録するときの引数の数。Action は acceptedArgs、Filter は max(1, acceptedArgs)(§5) */
    public function registeredArgs(int $acceptedArgs): int;
}
```

### scan() のアルゴリズム(正準)

DR-04 の「クラスマップ走査」とは、旧Loaderのような「発見即インスタンス化」ではなく「ファイル→FQCN対応表(クラスマップ)を構築して属性メタデータのみを収集する走査」を指す。正準手順:

```
scan():
  1. 列挙: RecursiveDirectoryIterator(appDir, SKIP_DOTS) で再帰列挙。
     - basename が '.' で始まるディレクトリ、'vendor'/'node_modules' ディレクトリは枝刈り(防御的)
     - ファイルはbasenameが /^[A-Z][A-Za-z0-9]*\.php$/ に一致するもののみクラス候補とし、
       appDirからの相対パス => 実パス を記録。一致しない .php(例: helpers.php)は走査対象外
       (エラーにもしない。関数定義ファイル等を置く余地を残す)。実パスを解決できないファイルは探索失敗
  2. 候補を相対パスの strcmp 昇順に並べる ※決定性確保
  3. 各候補ファイル file について(手順 b〜g の処理全体で起きた DiscoveryException 以外の
     \Throwable は、元の例外を previous に持ち、クラスとファイルを含む探索失敗に変換する
     ("failed to read {fqcn}: {元のメッセージ}")。getAttributes(..., IS_INSTANCEOF) による
     属性クラスの autoload の ParseError や、getArguments() で評価する定数式の未定義のクラス定数等):
     a. FQCN導出(PSR-4): appNamespace . '\\' . (相対パスから '.php' を除き、区切りを '\\' に変換)
     b. class_exists(fqcn, true) を評価(オートローダ経由ロード)。オートロード中に起きた
        \Throwable(ParseError・未定義の親クラス等の Error を含む)は探索失敗としてラップする
        ("failed to autoload {fqcn}: {元のメッセージ}"。元の例外を previous に持つ)。
        falseの場合、interface_exists/trait_exists/enum_exists(fqcn, false) も false なら探索失敗
        ("file {file} is expected to define class {fqcn}; check the PSR-4 autoload
          mapping '{appNamespace}\\' => '{appDir}' in composer.json and run
          'composer dump-autoload' if the mapping looks correct")。
        2つ目以降は再オートロードしない(読み込んだファイルが別の名前を定義していた場合に、
        同じファイルを二重に読み込んで致命的エラーにしないため)。
        interface/trait であれば候補から黙ってスキップ(正当な配置)
     c. ref = new ReflectionClass(fqcn)
        - ref->getName() !== fqcn(大文字小文字の不一致。macOS等の
          大文字小文字非区別FSで顕在化)なら探索失敗
        - realpath(ref->getFileName()) !== file(同名クラスが別ファイルから
          ロードされた=オートロード設定の不整合)なら探索失敗
        - #[AsHook] が付いているのに Hook 基底(TMSWP\Core\Hook\Hook)を継承していなければ探索失敗
          ("#[AsHook] class must extend TMSWP\Core\Hook\Hook")。register() という名前の
          メソッドの有無とは無関係に検査する
        - #[OptionSchema](04文書 4.12)の付与先の検査。#[OptionSchema] は #[AsOptionGroup] を
          付けた enum のケースにだけ付けられる。属性のターゲット(TARGET_CLASS_CONSTANT)では
          防げず、そのままでは宣言が使われずに黙って無視されるため、次を探索失敗にする:
          - クラス・enum 自身への付与、enum のケースでない定数(クラスの定数、enum の const)
            への付与("#[OptionSchema] is only allowed on enum cases: {fqcn}" または
            "... : {fqcn}::{定数名}")
          - #[AsOptionGroup] の無い enum のケースへの付与(その enum はオプショングループとして
            探索されない。"#[OptionSchema] requires #[AsOptionGroup] on the enum: {fqcn}::{ケース名}")
     d. attrs = ref->getAttributes(ComponentAttributeInterface::class,
                                   ReflectionAttribute::IS_INSTANCEOF)
        - attrs が空: コンポーネントではない普通のクラス。手順 3-f へ進む
          (メソッド属性の誤用検査のみ行う)
        - attrs があるのに ref->isInstantiable() が false かつ enum でもない
          (abstract/コンストラクタ非public): 探索失敗
          ("component attribute on non-instantiable class {fqcn}")。
          backed enum への付与は正当(例: #[AsOptionGroup]。Option文書)
     e. 各 attr について:
        - args = attr->getArguments()。args を再帰検査し、スカラー・配列・enumケース
          以外(オブジェクト等。PHP8.1+のnew式初期化子)が含まれれば探索失敗
          ("attribute arguments must be exportable to the discovery file (scalar/array/enum)")
        - attr->newInstance() を実行(属性コンストラクタの検証を発火させる)。
          \Throwable は探索失敗としてラップ
        - 属性が AsHook の場合: クラスが register() をオーバーライドしている
          (ReflectionMethod('register') の getDeclaringClass() が Hook 基底以外)
          ときのみ components[] へ追加する(register() 例外手段の priority 0
          初期化対象。§7)。オーバーライドなしの #[AsHook] クラスは components に
          現れず、本番(debug=false)の boot ではフック発火までロードされない
          (遅延ロードの構造的保証。debug の boot はこの走査でクラスを読み込む)
        - それ以外の属性: components[] = new DiscoveredComponent(fqcn, 属性FQCN, args)
        ※1クラスに異種のコンポーネント属性が複数付与されている場合は属性ごとに
          1レコード生成する(同一属性の重複付与はIS_REPEATABLE未指定のためPHPが拒否する)
     f. メソッドレベルフック属性(#[Action] / #[Filter]。§5)の収集:
        ref->getMethods() が返す全メソッド(継承含む)について #[Action]/#[Filter] を取得
        - 1つも無ければスキップ(正常系)
        - クラスに #[AsHook] が無いのに #[Action]/#[Filter] がある: 探索失敗
          ("method-level #[Action]/#[Filter] requires #[AsHook] on class {fqcn}")
        - 付与先メソッドが static・非public・可変長パラメータ(...$args)持ちの
          いずれか: 探索失敗(インスタンスメソッド前提・acceptedArgs を決定できないため)
        - attr->newInstance() で属性コンストラクタの検証を発火(\Throwable は探索失敗と
          してラップ。同一メソッドへの同一属性の重複付与は IS_REPEATABLE 未指定のため
          PHPが拒否する)
        - acceptedArgs = ReflectionMethod::getNumberOfParameters()
          (宣言引数数から自動導出。属性引数での手書きは存在しない。§5)
        - kind = #[Filter] なら HookMethodKind::Filter、#[Action] なら HookMethodKind::Action
        - hookMethods[] = new DiscoveredHookMethod(fqcn, メソッド名, hook名,
          priority, acceptedArgs, kind)
     g. #[AsHook] クラスに #[Action]/#[Filter] メソッドも register() オーバーライドも
        1つも無い場合: 探索失敗("hook class {fqcn} registers no hooks")
        (何も登録しないHookクラスは構成ミス。黙殺しない)
  4. components を (class, attributeClass) の複合キー昇順でソート(どちらも strcmp)
  5. hookMethods を (class, method, hook, priority, kind) の複合キー昇順でソート
     (class・method・hook は strcmp、priority は整数の比較)
     ※文字列の比較に配列の <=> は使わない。数字だけの文字列(例: フック名 '10' と '9')を
       数値として比べ、strcmp と違う順になるため
  6. 環境に依存する絶対パスの検査(結果全体に対して、ここ1か所で行う):
     - 対象は探索結果ファイルに書き出す文字列のうち、components の attributeArguments
       (配列は再帰的に見て、キーと値の両方)と、hookMethods の hook(フック名)。
       class・attributeClass・method は PHP の識別子のため対象外
     - 既知のルートは Kernel のルート(dirname(appDir))と、定義されていれば ABSPATH・
       WP_CONTENT_DIR。Kernel のルートは実パスに正規化し、解決できなければ探索失敗。
       ABSPATH・WP_CONTENT_DIR は設定された値そのものと、realpath() で解決できればその
       実パスの両方をルートにする(値が文字列でなければ探索失敗)。いずれも末尾の区切り文字を
       除き、空になるもの('/' 等)と重複は除く
     - ABSPATH・WP_CONTENT_DIR のディレクトリが存在しなくても探索失敗にしない。WP_CONTENT_DIR は、
       プラグイン・テーマ等を外に置く構成ではディレクトリが無くても WordPress が起動する。
       ABSPATH は存在しなければ WordPress 自体が起動しないが、両定数の扱いを揃えるため、
       存在を探索の前提にしない
     - 文字列の中にルート R が現れ、その直後が文字列の終わりか '/' または '\' のときだけ
       環境に依存するとみなす(R が /srv/a なら /srv/a・/srv/a/x.php・file:///srv/a/x は
       該当し、/srv/abc・/srv/a-b は該当しない)
     - 該当すれば探索失敗。メッセージにクラス(フック名なら class::method)と該当した値を含める
       ("attribute argument of {class} contains the environment-dependent path {R}: '{値}'; ..."、
        "hook name of {class}::{method} contains the environment-dependent path {R}: '{値}'")
  7. return new DiscoveryResult(components, hookMethods)
```

**Composer最適化クラスマップとの突合は行わない(確定判断)**: `spl_autoload_functions()` から `Composer\Autoload\ClassLoader` を探り当て `getClassMap()` とPSR-4導出FQCNを突合する検査は持たない。Composer内部実装への暗黙結合であり、別オートローダ併用環境(Bedrock系カスタムローダ等)で破綻し得るためである。このため「機能破綻を伴わない最適化クラスマップの陳腐化(`composer dump-autoload -o` の実行忘れで古いエントリが残存するが、PSR-4フォールバックで正常動作するケース)」は名指しでは検出しない。これは次回 dump-autoload で自己治癒する一過性の開発時ハイジーンに留まり、**機能破綻を伴う不整合はすべて手順3-b(class_exists 失敗)・3-c(getName/getFileName 突合)が「ファイルパス・期待FQCN・是正ヒント(dump-autoload 含む)」付きの明示エラーで検出する**。

**属性の引数とフック名は環境に依存しない値にする**: コンポーネント属性の引数(配列のキーと値)と `#[Action]` / `#[Filter]` のフック名は、探索結果ファイルにそのまま書き出される(§6)。`__DIR__` 等で絶対パスにすると、探索結果ファイルが環境ごとに変わり、照合(§3.6)が成り立たない。パスはテーマ・プラグインのルートからの相対パスで書く(既知のルートを区切り文字の境界で含む文字列は手順6で探索失敗になる)。コアと拡張パッケージのコンポーネント属性に、絶対パスを引数に取るものは無い。

**探索失敗時の挙動(全箇所共通・正準)**:

- `config.debug` に関係なく、その場で `TMSWP\Core\Exception\DiscoveryException` を投げ、走査を中断する。メッセージには必ず「対象ファイルの絶対パス」「期待FQCN(導出できた場合)」「是正方法のヒント」を含める。
- 途中まで収集した components / hookMethods は返さない。結果は全候補の走査を終えてから組み立てて返すため、例外のときに一部だけ登録された状態は生じない(boot は例外で止まり、フックも Kernel のレジストリも登録しない)。
- 走査は debug 時の boot と探索コマンド(§3.6)でだけ行う。探索の失敗は開発時・ビルド時のエラーになり、本番の boot には持ち込まれない。
- 旧Loaderの `catch (ReflectionException) { continue; }`(完全黙殺)は全面禁止。失敗をスキップして走査を続ける分岐を持たない。

**決定的ロード順**: 手順2・4・5のソートにより、走査結果(components / hookMethods)は OS・ファイルシステムの列挙順に依存しない。探索結果ファイル(§6)は絶対パス・mtime・生成時刻を含まないため、同一のapp/からは、どの環境(ホストと wp-env のコンテナのように絶対パスが違う環境を含む)で生成してもバイト単位で同一になる(受け入れ基準で検証)。

### discover() のアルゴリズム

```
discover():
  if config.debug:
      return scan()          // 毎リクエスト走査し、探索結果ファイルは読まない
  return file.load()         // 走査しない。ファイルが無い・構成と合わなければ DiscoveryException(§6)
```

- 本番(debug=false)の boot は探索結果ファイルの include 1回だけで、**Reflectionゼロ・appクラスのロードゼロ** を保証する(クラスはコンポーネント初期化フック、または `#[Action]`/`#[Filter]` が宣言したフックの発火で初めてロードされる)。探索結果ファイルが無い場合に走査へ切り替える経路は持たない(設定の誤りは起動時に常に例外。00-overview §5.6)。
- debug 時は毎リクエスト走査するため、app/ のクラスの追加・変更はすぐに反映される。走査は対象クラスを全ロードする。
- app/ を変えたら `wp tmswp discover` で探索結果ファイルを作り直してコミットする。CI は `wp tmswp discover --check` でファイルが最新かを照合する(§3.6)。

## 5. ComponentAttributeInterface / ComponentInitializerInterface

### 役割

Discovery が「何を拾うか」、Kernel が「いつ・どう初期化するか」を属性自身に宣言させる契約。コア6属性(`AsPostType` / `AsTaxonomy` / `AsUserRole` / `AsModel` / `AsHook` / `AsOptionGroup`)に加え、拡張パッケージ(例: tmswp/settings-page)が独自属性を追加する際もこの契約を実装するだけでよく、Kernel側の改修や登録テーブルを必要としない。なおテーマ形態のテンプレート前処理は、テンプレートの先頭で app 層の前処理クラスを明示的に呼ぶ方式であり、本契約・Discovery とは無関係である(§3.7)。

### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\KernelContext;

interface ComponentAttributeInterface
{
    /** 初期化を実行するWPフック名(コンテキスト依存の場合は引数で分岐) */
    public function hookName(KernelContext $context): string;

    /** 上記フックでのpriority */
    public function hookPriority(): int;

    /** @return class-string<ComponentInitializerInterface> 初期化処理クラス */
    public function initializerClass(): string;
}
```

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

interface ComponentInitializerInterface
{
    /**
     * コンポーネント1件を初期化する。属性が宣言したフック発火時にKernelから呼ばれる。
     * 実装はKernelのContainerからシングルトンとして解決される(コンストラクタ注入可)。
     */
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void;
}
```

初期化のうち WordPress のフックを待たずに boot の中で行う部分を持つ Initializer は、次の interface を実装する。Kernel は boot の手順8で、components の順(FQCN 昇順)に、Initializer のクラスがこの interface を実装するコンポーネントだけについて `initializeAtBoot()` を1回ずつ呼ぶ(実装しない Initializer は、ここでは Container から取り出さない)。この interface は `@internal` で、実装するのはコアのエンティティの Initializer(`PostTypeInitializer`・`TaxonomyInitializer`・`UserRoleInitializer`)だけである。メタスキーマを `MetaSchemaRegistry::registerEntity()` に登録する(§7)。拡張パッケージの属性・Initializer からの実装は想定せず、拡張が使える契約は `ComponentAttributeInterface` と `ComponentInitializerInterface` だけとする。

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

/** @internal コアのエンティティの Initializer だけが実装する */
interface BootInitializerInterface extends ComponentInitializerInterface
{
    /**
     * コンポーネント1件の boot 時の初期化を行う。Kernel が、拡張の登録とレジストリへの登録の後、本番の遅延 boot の
     * キャッチアップの前に呼ぶ。WordPress の登録 API は呼ばないこと。
     */
    public function initializeAtBoot(DiscoveredComponent $component, Kernel $kernel): void;
}
```

各属性クラス・各Initializerの実装は対応するエンティティ文書が定義する。本文書はそれらが**返すべき値(下表)**を正準として固定する。

### メソッドレベルフック属性 #[Action] / #[Filter](本文書が正準定義)

`#[AsHook]` クラスのWPフック登録は、メソッドレベル属性 `#[Action]` / `#[Filter]` による宣言が第一級の正準手段である。フック名⇔メソッドが1対1で機械可読(grep / PHPStan / AI追跡可)になり、`add_action('save_post', ..., 10, 2)` の引数個数手書き(忘れバグの定番)が構造的に存在しない。

```php
<?php
// src/Attribute/Action.php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Action
{
    public function __construct(
        public string $hook,
        public int $priority = 10,
    ) {
        if ($hook === '') {
            throw new \InvalidArgumentException('hook name must not be empty');
        }
    }
}
```

```php
<?php
// src/Attribute/Filter.php — シグネチャはActionと同一

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Filter
{
    public function __construct(
        public string $hook,
        public int $priority = 10,
    ) {
        if ($hook === '') {
            throw new \InvalidArgumentException('hook name must not be empty');
        }
    }
}
```

振る舞い仕様:

- 両属性は `ComponentAttributeInterface` を**実装しない**(コンポーネント属性ではなく、`#[AsHook]` クラスの public インスタンスメソッドにのみ付与できるフック宣言)。クラスマーカーとしての `#[AsHook]` は維持され、Discovery は `#[AsHook]` クラスのメソッドのみを収集対象とする(`#[AsHook]` なしクラスへの付与は探索失敗。§4手順3-f)。
- **acceptedArgs はメソッドの宣言引数数(`ReflectionMethod::getNumberOfParameters()`)から自動導出する。属性引数での手書きは提供しない**(手書きを許すと引数個数バグの根絶という主目的が失われる)。
- **WordPress に登録する引数の数**: `#[Action]` は acceptedArgs のまま登録する。`#[Filter]` は `max(1, acceptedArgs)` で登録し、引数を宣言しないメソッドでも第1引数(フィルタ対象の値)を受け取る。本番で失敗したときに元の値を返すためである(下記「失敗の隔離」)。どちらも、メソッドには受け取った引数を acceptedArgs の数に切り詰めて(`array_slice($args, 0, acceptedArgs)`)渡す。
- `#[Action]` と `#[Filter]` に機構上の差はない(WPの `add_action()` は `add_filter()` の別名であり、登録はどちらも `add_filter()` で行う。§7)。意味の区別を宣言として残すために2クラスへ分ける。`#[Filter]` メソッドは必ず値を返すこと(rules/ に記載)。
- **呼び出しの型変換**: フック発火時、Kernel はメソッドを `call_user_func_array([$handler, $method], $args)` で呼ぶ。WordPress の `WP_Hook` と同じく内部関数を経由して呼ぶため、strict_types に関係なく WordPress 本来の弱い型変換が効く(数値文字列を `int` 引数に渡すフックが通る)。`$callback(...$args)` や名前空間付きの `\call_user_func_array()` は、PHP がコンパイル時に直接の呼び出しへ置き換え、Kernel のファイルの strict_types が効いて TypeError になるため使わない(php-cs-fixer の `native_function_invocation` から `call_user_func_array` を除外している)。
- **失敗の隔離**: メソッドの呼び出し(Container からのハンドラの解決を含む)が例外を投げた場合、エラー方針(00-overview §5.6)に従う。debug 時はそのまま投げる。本番はその Kernel のロガー(`$this->logger->error('tmswp.kernel: hook method {class}::{method} failed on {hook}', [class, method, hook, exceptionClass, exceptionAt, previousClasses])`)に記録してそのメソッドだけをスキップし、第1引数をそのまま返す(filter の値を変えない。action の戻り値は WordPress が使わない)。同じフックの他のコールバックは WordPress がそのまま続けて呼ぶ。`#[Filter]` は引数を宣言しないメソッドでも第1引数を受け取るため、常に元の値を返せる。引数を宣言しない `#[Action]` は第1引数を受け取らないため `null` を返す(action の戻り値は使われない)。
- **失敗のログの文脈**: 本番のログの文脈には、例外のクラス名(`exceptionClass`)と、例外が投げられた場所(`exceptionAt`。`ファイル:行`)と、previous の例外のクラス名(`previousClasses`)だけを入れる。例外のオブジェクト・メッセージ・トレースは入れない。フックのメソッドはアプリのコードで、例外のメッセージやトレースの引数に利用者の入力値や秘密が入りうるためである(05-form のログと同じ)。開発者は debug で元の例外を見られる。
- 真に動的な登録(ループでフック名を生成する等、メソッド属性で表現できないケース)に限り、Hook 基底の `register()` オーバーライドを例外手段として使う(次項)。`register()` をオーバーライドしたクラスのみが初期化タイミング表(§7)の priority 0 でインスタンス化される。rules/・利用例の第一級の書き方は `#[Action]` / `#[Filter]` に一本化する。

### #[AsHook] / Hook 基底 / HookInitializer(本文書が正準定義)

```php
<?php
// src/Attribute/AsHook.php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use TMSWP\Core\Hook\HookInitializer;
use TMSWP\Core\Kernel\KernelContext;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsHook implements ComponentAttributeInterface
{
    /** テーマ=after_setup_theme / プラグイン=plugins_loaded($context->bootHook()) */
    public function hookName(KernelContext $context): string;

    /** 常に 0 */
    public function hookPriority(): int;

    /** @return class-string<HookInitializer> */
    public function initializerClass(): string;
}
```

```php
<?php
// src/Hook/Hook.php

declare(strict_types=1);

namespace TMSWP\Core\Hook;

abstract class Hook
{
    /** 真に動的な登録のための例外手段。既定の実装は何もしない */
    public function register(): void {}
}
```

```php
<?php
// src/Hook/HookInitializer.php

declare(strict_types=1);

namespace TMSWP\Core\Hook;

use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;

final class HookInitializer implements ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void;
}
```

振る舞い仕様:

- `#[AsHook]` は引数を持たないクラスマーカーである。app 側の Hook クラスは `Hook\Hook` を継承し、`app/Hook/` に置く。継承しない `#[AsHook]` クラスは走査時の探索失敗になる(§4 手順3-c)。
- Discovery は、`register()` を Hook 基底以外のクラスで宣言している `#[AsHook]` クラスだけを components に含める(§4 手順3-e)。`#[Action]` / `#[Filter]` メソッドだけを持つクラスは components に現れない。
- `HookInitializer::initialize()` は Kernel の Container から `$component->class` を解決する。解決結果が `Hook` のインスタンスでなければ `DiscoveryException`(`hook component must extend Hook: {fqcn}`)を投げる(探索結果ファイルが古い場合の防御。走査では継承を検査済み)。この例外は設定の誤りとして、debug に関係なくそのまま投げる(本番でも隔離しない。§7「エラー方針との対応」)。`Hook` のインスタンスであれば `register()` を1回だけ呼ぶ。
- 初期化のタイミングは §7 の初期化タイミング表(`#[AsHook]` 行)に従う。

## 6. DiscoveryFile(探索結果ファイル)

### 役割

`DiscoveryResult` を探索結果ファイル(`<?php return [...];`)として生成し、本番の boot で読み込む。ファイルは探索コマンド `wp tmswp discover`(§3.6)だけが書き、コードと一緒にコミットして FTP 等でデプロイする。CI は `wp tmswp discover --check` でファイルが最新かを照合する(10-quality §5)。PHPファイル形式の理由: OPcacheに乗るため本番の読み込みコストが実質ゼロになり、unserialize系の脆弱性も持たない。

### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

final class DiscoveryFile
{
    /** 探索結果ファイルの形式の版。格納形式の変更時にインクリメントする */
    public const SCHEMA_VERSION = 4;

    public function __construct(
        private readonly KernelConfig $config,
    ) {}

    /** KernelConfig::discoveryFile() と同じ絶対パス */
    public function path(): string;

    /**
     * 探索結果ファイルを読み込む。ファイルが無い・壊れている・この Kernel の構成と合わない場合は例外を投げる。
     *
     * @throws \TMSWP\Core\Exception\DiscoveryException
     */
    public function load(): DiscoveryResult;

    /** 探索結果をファイルの内容(PHP コード)にする。同じ探索結果からは常に同じバイト列を返す。 */
    public function render(DiscoveryResult $result): string;

    /** ファイルの内容が探索結果と一致するか。ファイルが無ければ false。 */
    public function isUpToDate(DiscoveryResult $result): bool;

    /**
     * 探索結果をファイルに書く。一時ファイルに書いてから rename で置き換える。
     *
     * @throws \TMSWP\Core\Exception\DiscoveryException 書き込みに失敗した場合
     */
    public function write(DiscoveryResult $result): void;
}
```

### ファイル形式と配置

- 配置: `KernelConfig::discoveryFile()` = `dirname(appDir) . '/tmswp-discovery.php'`(テーマなら `<theme>/tmswp-discovery.php`、プラグインなら `<plugin>/tmswp-discovery.php`)。Kernel ごとに1ファイルで、置き場を変える設定は持たない。
- 内容(正準スキーマ。fixture のプラグインの実際の生成物):

```php
<?php

// @generated by 'wp tmswp discover'. Do not edit.

declare(strict_types=1);

return [
    'schema' => 4,
    'appNamespace' => 'TmswpFixturePlugin',
    'appDir' => 'app',
    'components' => [],
    'hookMethods' => [
        [
            'class' => 'TmswpFixturePlugin\\Hook\\PluginProbeHook',
            'method' => 'filter',
            'hook' => 'tmswp_fixture_plugin/filter',
            'priority' => 10,
            'acceptedArgs' => 1,
            'kind' => 'filter',
        ],
        [
            'class' => 'TmswpFixturePlugin\\Hook\\PluginProbeHook',
            'method' => 'handle',
            'hook' => 'tmswp_fixture_plugin/action',
            'priority' => 10,
            'acceptedArgs' => 0,
            'kind' => 'action',
        ],
    ],
];
```

- `components` の各要素は `class`・`attributeClass`・`attributeArguments` を持つ((class, attributeClass) 昇順)。`hookMethods` の各要素は `class`・`method`・`hook`・`priority`・`acceptedArgs`・`kind`(`'action'` / `'filter'`)を持つ((class, method, hook, priority, kind) 昇順。§4)。
- `appDir` は探索結果ファイルのディレクトリからの相対パス(ファイルを appDir の親ディレクトリに置くため、appDir のディレクトリ名になる)。
- **絶対パス・mtime・生成時刻・ファイル一覧を載せない。** ホストと wp-env のコンテナのように絶対パスが違う環境で生成しても、バイト単位で同一になる(CI の照合が環境に依存しない)。
- 配列は短縮構文で書き、スカラー・enumケースの値は `var_export()` で書く(enumケースは有効なPHPコードとして出力される。属性引数をスカラー/配列/enumに制限している(§4手順3-e)のはこのため)。配列に `var_export()` を使わないのは、行末に空白が残り、空白を削るエディタで内容が変わって照合が失敗するためである。
- ファイルは `return` 文のみで構成され、Webから直接アクセスされても何も出力しない。

### write() のアルゴリズム

```
write(result):
  1. contents = render(result)
  2. tmp = path . '.' . uniqid('', true) . '.tmp' へ file_put_contents
     書込バイト数不一致なら tmp を削除して DiscoveryException
  3. rename(tmp, path)   // 同一FS内の原子的置換。失敗なら tmp を削除して DiscoveryException
  4. function_exists('opcache_invalidate') なら opcache_invalidate(path, true)
```

### load() の検証

以下のいずれかに該当すれば `DiscoveryException` を投げる。メッセージにはファイルのパスと「`wp tmswp discover` で生成し、コードと一緒にデプロイする」という案内を含める。走査に切り替える経路は持たない(§4)。

1. ファイルが無い
2. `include` が throw する(元の例外を previous に持つ)、または配列以外を返す
3. `schema !== DiscoveryFile::SCHEMA_VERSION`
4. `appNamespace` が KernelConfig の appNamespace と違う
5. `appDir`(相対パス)を探索結果ファイルのディレクトリから解決した実パスが、KernelConfig の appDir と違う(ファイルの取り違え)
6. `components` / `hookMethods` の要素の型が違う(破損)

## 7. コンポーネント初期化スケジューリング

### 初期化タイミング表(正準)

各属性の `hookName()` / `hookPriority()` はこの表の値を返さなければならない。各エンティティ文書はこの表に従って属性を実装する。

| 属性 | フック (Theme) | フック (Plugin) | priority | 初期化内容(概要。詳細は各文書) |
|---|---|---|---|---|
| `#[AsHook]` | `after_setup_theme` | `plugins_loaded` | 0 | **`register()` をオーバーライドするクラスのみ**: Containerからインスタンス化し `register()`(真に動的な登録の例外手段。§5)を呼ぶ。`#[Action]`/`#[Filter]` メソッドの登録はこの表の外(boot手順5で直接 `add_filter`。下表)(§5) |
| `#[AsModel]` | `after_setup_theme` | `plugins_loaded` | 1 | TableDefinition をモデルレジストリへ登録。**DBスキーマ適用はここでは行わない**(下記) |
| `#[AsOptionGroup]` | `init` | `init` | 5 | オプショングループの `OptionRepository` の作成と `#[OptionSchema]` の検証(Option文書)。`register_setting()` は呼ばない |
| `#[AsTaxonomy]` | `init` | `init` | 9 | register_taxonomy(Tax文書)。メタスキーマの登録は boot 時の初期化で行う(下記) |
| `#[AsPostType]` | `init` | `init` | 10 | register_post_type(Post文書)。メタスキーマの登録は boot 時の初期化で行う(下記) |
| `#[AsUserRole]` | `init` | `init` | 11 | ロール定義の登録。DB書込(add_role)はUserRole層が差分検出して冪等に行い、宣言とDBの差分(表示名・宣言に無いcap)は Kernel のロガーに warning で通知する(User文書) |

メソッドレベル `#[Action]` / `#[Filter]`(Discovery が hookMethods として収集。§4・§5)はこの属性スケジューリング表の外にあり、boot 時に直接フック登録される(下表)。

| 機構 | フック | priority | 形態・発火条件 | 内容(概要。詳細は各文書) |
|---|---|---|---|---|
| `#[Action]` / `#[Filter]` メソッド | 属性が宣言した各フック | 属性宣言値(既定 10) | 両形態。boot手順5(scheduleAll)で登録、宣言フック発火時に実行 | `add_filter($hook, クロージャ, $priority, $kind->registeredArgs($acceptedArgs))` を直接登録(`#[Filter]` は最低 1。§5)。クロージャは発火時に `container->get($class)` してメソッドを呼ぶため、**本番(debug=false)の boot では、Hookクラスはフック発火まで未ロード(遅延)**(debug の boot は走査(§4)でクラスを読み込む)。acceptedArgs はメソッド宣言引数数から自動導出(§4・§5) |

MetaSchema の WordPress への登録(`MetaSchemaRegistry::registerAll()`。`register_post_meta`・`register_term_meta`・`register_meta('user', ...)`)は、コンポーネントではないが、Kernel が `init` 20 のグループとして同じ規則(遅延bootの検査とキャッチアップ。§3.3)で登録する。

補足仕様:

- **メタスキーマはbootの中で集め、WordPressへは`init` 20で登録する(確定判断)。** エンティティのInitializerは`BootInitializerInterface`(§5)を実装し、bootの手順8で`MetaSchemaRegistry::registerEntity()`を呼ぶ(エンティティの一覧は探索結果ファイル(本番)か走査(debug)のcomponentsから取る。スキーマ自体は探索結果ファイルに載せず、エンティティのクラスの定数と属性(`#[MetaSchema]`・`#[UseMetaStorage]`・`#[WithMetaRevisions]`)をリフレクションで読む。04-meta-options §4.9)。そのため、bootの後ならinitの前でも、同じpriorityでエンティティの登録より先に動くappの`#[Action('init')]`の中でも`meta()`が使え、スキーマの宣言の誤りはdebugに関係なくbootの例外になる。ただし`sanitize`を宣言したキーへの書き込みは、WordPressがsanitizeを適用する`init` 20の登録の後でなければ`LogicException`になる(04-meta-options §4.8)。WordPressへの登録を`init` 20にするのは、(1) acf-bridgeが`init` 6で外部管理のキーを申告した後に流すため(同じキーを申告と違うストレージのエンティティが`#[MetaSchema]`で宣言していれば、スキーマがbootで揃っているため申告の時点で`DuplicateMetaKeyException`になり、`register_*_meta`に進まない。例外のメッセージは`#[MetaSchema]`の定数名とACFのフィールドグループのkeyを含む。`#[UseMetaStorage(AcfMetaStorage::class)]`のエンティティの宣言は申告と衝突せず、宣言どおり登録する。09-acf-bridge §7.3)、(2) WordPressの`register_meta()`は`revisions_enabled`を`post_type_supports($subtype, 'revisions')`で確かめるため、投稿タイプの登録(`init` 10)より後でなければならないためである。boot時の初期化はエンティティのクラスを読み込み、`definition()`を呼ぶ(スラッグと`supports`の取得)。`definition()`はWP関数を呼ばない純粋関数とする規約(03-entities)はこのためにも必要である。
- **UserRole(11) を PostType(10) の後に登録する(確定判断)。** 同じ priority に置くと FQCN の順で混ざるため、priority で順序を固定する。
- **Taxonomy(9) を PostType(10) より先に登録する(確定判断)。** 根拠: `register_post_type()` の `taxonomies` 引数は内部で `register_taxonomy_for_object_type()` を呼ぶが、これは**未登録のタクソノミー名に対して false を返して黙って失敗する**。逆方向(`register_taxonomy()` の `$object_type` に未登録のCPT名を渡す)は文字列として保持され後から解決されるため問題ない。したがって「Taxonomy先・PostType後」が唯一安全な順序である。WPコアの `create_initial_post_types` / `create_initial_taxonomies` は init 0 で動くため、5/9/10 はいずれもコア登録より後になる。
- `#[AsModel]` のスキーマ適用(dbDelta)の契機は WP-CLI の `wp tmswp migrate` だけであり、Model 文書の Migrator が定義する。起動時・`admin_init`・ajax・cron・有効化では適用せず、テーブル定義のハッシュが DB と食い違えば `admin_notices` で権限のある管理者に通知する。本表の plugins_loaded(1) は「`InquiryModel::query()` が呼べるようにレジストリへ載せる」だけの軽量処理である。
- `#[MetaSchema]` / `#[UseMetaStorage]` / `#[WithMetaRevisions]` / `#[OptionSchema]` は独立コンポーネントではなく、親コンポーネントの Initializer が処理する(前の3つはエンティティの Initializer の boot 時の初期化、`#[OptionSchema]` は `#[AsOptionGroup]` の Initializer の初期化)。Discovery の発見対象にはならない(`ComponentAttributeInterface` を実装しない)。
- AsHook(0。`register()` 例外手段)と AsModel(1) を別priorityに分離しているのは依存関係のためではなく、決定的順序を priority レベルで固定するためである。Hookクラスのコンストラクタでモデルのインスタンスが必要な場合も、モデルは静的ファサード(`InquiryModel::query()`)であり実際のレジストリ参照はクエリ実行時まで遅延するため問題にならない。

### scheduleAll() のアルゴリズム

```
scheduleAll():
  1. groups = []   // "{hook}\n{priority}" => list<array{component, attribute}>
     for c in this.components (FQCN昇順で格納済み):
        attr = c.attribute()               // 復元できなければ DiscoveryException(設定の誤り。debug に関係なく投げる)
        key = attr->hookName(config.context) . "\n" . attr->hookPriority()
        groups[key][] = [c, attr]
     各グループの runner = function () { foreach (group as [c, attr]) this.runInitializer(c, attr); }
     groups[] = (init, 20, MetaSchemaRegistry::registerAll)   // MetaSchema の WordPress への登録も同じ規則で扱う
  2. fired = $wp_actions のキー => 最初に発火した順位    // §3.3
     missed = groups のうち hook が fired にあるもの、pending = それ以外
     missed を (fired[hook], priority) の昇順に並べる
     if missed !== [] && debug:
        throw KernelException(遅延boot。missed の先頭のフック名。§3.3)   // まだ何も登録していない
  3. for hm in this.hookMethods ((class, method, hook, priority, kind) 昇順で格納済み):
        add_filter(hm.hook, function (mixed ...$args) use (hm): mixed {
            try:
                return call_user_func_array([container->get(hm.class), hm.method],
                    array_slice($args, 0, hm.acceptedArgs))
            catch \Throwable e:                       // §5 の「失敗の隔離」
                debug ? throw e : (this.logger.error; return $args[0] ?? null)
        }, hm.priority, hm.kind->registeredArgs(hm.acceptedArgs))   // Filter は max(1, acceptedArgs)
     // #[Action] も add_filter で登録する(add_action は add_filter の別名。§5)。
     // クロージャが捕捉するのは DiscoveredHookMethod(FQCN文字列とメソッド名)のみで、
     // 本番(debug=false)の boot では、Hookクラスはフック発火時の container->get() まで未ロード(遅延。
     // debug の boot は走査(§4)でクラスを読み込む)
  4. for (hook, priority, runner) in pending:
        add_action(hook, runner, priority, 0)          // グループごとに1クロージャ
  5. return missed                                    // bootの手順5aでキャッチアップの列にし、手順8a(catchUp)で、拡張とレジストリへの登録の後に実行する

initializeAtBoot():                // boot の手順8。失敗は設定の誤り。debug に関係なく例外をそのまま投げる
  for c in this.components (FQCN昇順):
     class = c.attribute()->initializerClass()
     if class が BootInitializerInterface を実装する:
        container->get(class)->initializeAtBoot(c, this)   // エンティティ: MetaSchemaRegistry::registerEntity()

prepareCatchUp(missed):            // bootの手順5a
  this.catchUpQueue = missed                       // (最初に発火した順, priority)の昇順に並べ済み。debugでは常に空
                                                   // (debugの遅延bootは手順5で例外のため)

catchUp():                         // bootの手順8a(§3.3)。列は本番の遅延bootの発火済みのグループと、手順5a以降に
                                   // defer()で発火済みのフックへ予約したもの。列が空なら何もしない
  try:
     while catchUpQueue が空でない:
        (hook, priority, runner, deferred) = catchUpQueue の先頭を取り出す
        if !deferred: this.logger.error(遅延boot)
        runner()                                   // キャッチアップ。失敗は boot の catch でレジストリから取り除いて投げ直す
                                                   // runner の中の defer() は catchUpQueue の (発火順, priority) の位置に入る(§3.3)
  finally:
     this.catchUpQueue = null                      // 手順5aより前・bootの後のdefer()はadd_action

runInitializer(c, attr):           // 失敗は設定の誤り。debug に関係なく例外をそのまま投げる
  initializer = container->get(attr->initializerClass())
  if !(initializer instanceof ComponentInitializerInterface):
      throw DiscoveryException("initializer '{class}' must implement ComponentInitializerInterface (component {fqcn})")
  initializer->initialize(c, this)
```

**エラー方針(00-overview §5.6)との対応**: コンポーネントの初期化(boot 時の初期化を含む)は、投稿タイプ・タクソノミー・メタスキーマ・オプション等の登録そのものであり、そこで起きる失敗(Initializer の例外、Initializer が `ComponentInitializerInterface` を実装していない、探索結果ファイルの属性を復元できない)は「設定の誤り」に当たる。そのため debug に関係なく例外をそのまま投げ、本番でも隔離しない(必要な投稿タイプが登録されないまま Kernel が成功扱いになることを防ぐ)。boot 時の初期化の失敗(メタスキーマの宣言の誤り等)は boot の例外になる。本番でエンティティの `register()` が例外を投げた場合も、ログに記録して続けることはせず、例外のまま `init` から伝わる(そのリクエストは失敗する)。メタスキーマは boot 時に `MetaSchemaRegistry` へ登録済みのため、`register()` の失敗によってスキーマの登録が黙って飛ばされることはない。本番で隔離するのは、§5 の `#[Action]`/`#[Filter]` メソッドの実行時の失敗(古い探索結果によるハンドラの解決の失敗を含む)と、§3.3 の遅延bootのキャッチアップの2つだけである。

補足(`#[Action]`/`#[Filter]` 登録の確定仕様):

- 遅延boot検査(`$wp_actions`)とキャッチアップ(§3.3)は、コンポーネント初期化グループとMetaSchemaのWordPressへの登録に適用する(bootの中で`defer()`が発火済みのフックへ予約した処理(拡張の`register()`・boot時の初期化・キャッチアップ中の初期化から)も、キャッチアップの中で実行する。§3.3)。`#[Action]`/`#[Filter]`はハンドラ登録であり「過去の発火に対するキャッチアップ」が定義できないためキャッチアップの対象外とする。ただしdebugの遅延boot例外は`#[Action]`/`#[Filter]`の登録よりも前に投げるため、例外のときは`#[Action]`/`#[Filter]`も登録されない(§3.1)。
- クロージャ登録のため、第三者コードからの `remove_action` / `remove_filter` による個別解除は事実上できない。登録クロージャを保持して解除APIを提供することは実需要が出るまで行わない(YAGNI。受託で第三者がHookを外す要件は稀)。

**正確に1回の初期化(Kernel契約)**: Kernel は Discovery が収集した components リストを1巡だけ走査し、各コンポーネントの Initializer(エンティティ基底の `register()` を含む)を**正確に1回**呼ぶ。冪等性の保証はこの1巡走査のみであり、エンティティ基底の `register()` 側は再入ガード・重複防止を持たない(エンティティ文書)。

順序保証のまとめ:

- 異なるフック間: WPのフック発火順。
- 同一フック・異なるpriority間: priority昇順(WP仕様)。Taxonomy(9)→PostType(10)→UserRole(11)→MetaSchema の WordPress への登録(20)。
- 同一(フック, priority)・同一Kernel内のコンポーネント初期化: **components の FQCN 昇順**(グループランナーが1クロージャで順次実行するため、他プラグインの add_action に割り込まれない)。
- 同一(フック, priority)内の `#[Action]`/`#[Filter]` メソッド間: hookMethods の (class, method, hook, priority, kind) 昇順=登録順。コンポーネント初期化グループとの相対順は「hookMethods 先・グループランナー後」(scheduleAll の手順3・4の順)で決定的だが、この相対順に依存してはならない(規約)。
- 同一(フック, priority)・複数Kernel間: boot順(=WPロード順)。Kernel間の順序依存は規約で禁止(§3.2)。

旧FWの静的 `$dependencies` 配列による依存宣言は**廃止**する。初期化順の制御手段はこの表のフック/priorityのみであり、コンポーネント間にグラフ状の依存宣言機構を持たない(よって初期化順での循環依存は構造的に発生しない)。インスタンスレベルの依存はコンストラクタ注入で表現し、循環は Container が検出する(§8)。

## 8. Container (PSR-11)

### 役割

Kernel ごとに1つ存在する軽量DIコンテナ。役割は (1) コアサービス・Initializer・app側サービスの生成と共有 (2) コンストラクタ注入の自動解決(autowiring) (3) 循環依存の検出。設定ファイル・コンパイル・タグ機構は持たない(WP受託に過剰なため。DR-09)。公開APIは `set` / 単段 `alias` / `get` / `has` のみとし、スコープは共有(シングルトン)のみ(実利用が実証された機能だけを収載する。原則3・DR-14)。

### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Container;

use Psr\Container\ContainerInterface;

final class Container implements ContainerInterface
{
    /** @var array<string, \Closure(Container): mixed> 共有定義(初回解決後 $instances へ昇格) */
    private array $definitions = [];

    /** @var array<string, mixed> 解決済み共有インスタンス */
    private array $instances = [];

    /** @var array<string, string> alias => 正規id(単段のみ) */
    private array $aliases = [];

    /** @var list<string> 解決中idスタック(循環依存検出用) */
    private array $resolving = [];

    /**
     * 共有(シングルトンスコープ)登録。
     * - \Closure: 遅延ファクトリ。初回get時に $factory($this) を実行し共有
     * - その他 object: 即値インスタンスとして共有
     * - string: クラス名束縛(interface => 実装クラス等)。初回get時に autowire して共有
     * idがクラス名・interface名なら、登録の時点で型を確かめる(下記「登録の時点の型の確認」)。
     *
     * @throws \TMSWP\Core\Exception\ContainerException 解決済みidへの再登録・型の合わない登録
     */
    public function set(string $id, object|string $concrete): void;

    /**
     * 別名登録(単段のみ)。get($alias) は get($id) と等価になる。
     *
     * alias名がクラス名・interface名なら、alias先との型の互換性を登録の時点で確かめる。
     *
     * @throws \TMSWP\Core\Exception\ContainerException 自己参照・$idが別のaliasである(連鎖の禁止)・解決済みidへの再登録・型の合わないalias
     */
    public function alias(string $alias, string $id): void;

    /**
     * PSR-11。未登録のclass-stringはautowireで解決し共有する。
     * クラス名・interface名のidは、その型のインスタンスに解決されることをContainerが保証する。
     *
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @throws \TMSWP\Core\Exception\NotFoundException 解決不能なid
     * @throws \TMSWP\Core\Exception\CircularDependencyException 循環依存
     * @throws \TMSWP\Core\Exception\ContainerException 解決不能なコンストラクタ引数・factoryの失敗・型の食い違い等
     *
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed;

    /**
     * PSR-11。get() が解決できるidだけ true(下記「has() の判定」)。インスタンスは作らない。
     * factory は実行しないため、factory が投げれば true でも get() は失敗しうる。
     */
    public function has(string $id): bool;
}
```

### 振る舞い仕様

**登録(set / alias)**

- 同一idへの再登録は、未解決(まだ get されていない)であれば後勝ちで置換できる(テストでの差し替え用途)。**解決済み(`$instances` に存在する)idへの再登録は `ContainerException`**(「設定したのに反映されない」サイレント事故の禁止)。
- `alias()` は**単段のみ**。`$alias === $id`(自己参照)、または `$id` 自体が登録済みのalias(連鎖 A→B→C の試み)は `ContainerException`。これにより alias 解決は配列参照1回で完結し、循環aliasは構造的に発生しない。

**登録の時点の型の確認**(fail fast): idがクラス名・interface名(`class_exists() || interface_exists()`)のとき、登録の時点で次を確かめ、合わなければ `ContainerException` を投げる。

- `set()` の即値の object: `$concrete instanceof $id` であること。
- `set()` のクラス名束縛: 束縛先が実在し(`class_exists() || interface_exists()`)、`is_a($concrete, $id, true)` であること。
- `set()` に alias 名を渡した場合は、alias 名と alias 解決後のidの両方について確かめる。
- `alias()`: alias名がクラス名・interface名なら、alias先のidが alias名の型に解決されうることを確かめる。alias先にインスタンスがあればその型、クラス名束縛があれば束縛先、未登録のクラス名なら alias先そのものを `is_a` で比べる。alias先が factory(`\Closure`)や未登録のサービス名で型が分からない場合は確かめず、`get()` の時点の確認に任せる。
- factory(`\Closure`)の結果の型は、登録の時点では分からないため `get()` の時点で確かめる。

**解決(get)** — 擬似コード:

```
get(requestedId):
  id = aliases[requestedId] ?? requestedId               // 単段解決。requestedId は保持する
  if instances has id:
      return assertType(requestedId, id, instances[id])  // キャッシュから返す経路も両方のidを確かめる
  if id in resolving:
      throw CircularDependencyException(
          'circular dependency: ' . implode(' -> ', resolving) . ' -> ' . id)
  resolving[] = id
  try:
      if definitions has id:
          d = definitions[id]
          if d instanceof \Closure:
              try: value = d(this)
              catch ContainerExceptionInterface e: throw e                    // 入れ子の解決の例外は包まない
              catch \Throwable e: throw ContainerException(
                  "factory for container entry '{id}' threw {class}: {message}", previous: e)
          else:                                                              // クラス名束縛
              if !class_exists(d): throw NotFoundException("defined class '{d}' not found for '{id}'")
              value = this.instantiate(d)
      elseif class_exists(id):
          value = this.instantiate(id)                               // autowire
      else:
          throw NotFoundException("no entry or class found for '{id}'")
      instances[id] = assertType(requestedId, id, value)            // 両方のidの確認を通った値だけを保存する。失敗すれば何も保存せずに投げる
      return instances[id]
  finally:
      array_pop(resolving)

assertType(requestedId, id, value):                      // alias解決後のidと要求されたidの両方を確かめる
  for t in [id, requestedId]:
      if (class_exists(t) || interface_exists(t)) && !(value instanceof t):
          throw ContainerException("container entry '{t}' resolved to {type}, which is not an instance of '{t}'")
  return value
```

- 即値の object は `set()` 時に型を確かめたうえで `instances` へ入る。即値も factory の結果も、返すときは同じ `assertType` を通るため、alias を経由しても扱いは変わらない。
- **型の保証**: クラス名・interface名のidは、その型のインスタンスに解決されることを Container 自身が保証する(登録の時点と `get()` の時点で確かめ、違えば `ContainerException`)。これにより `get()` の戻り値型を `@template` の条件付き型で宣言でき、呼び出し側は戻り値を `instanceof` で確かめない(PHPStan が `get(Foo::class)` を `Foo` と推論する)。クラス名でないid(サービス名等)の戻り値は `mixed` のままで、呼び出し側が型を確かめる。
- **factory の例外**: factory(`\Closure`)の中で投げられた例外は、解決しようとしたidをメッセージに含む `ContainerException` で包み、元の例外を `previous` に1段だけ保持する。すでに `ContainerExceptionInterface` である例外(入れ子の解決で起きた `NotFoundException`・`CircularDependencyException`・包み済みの `ContainerException`)は二重に包まずにそのまま投げ直す。autowire のコンストラクタが投げた例外は包まない。

**autowire(instantiate)** — 引数の得方の規則(`autowirePlan`)は `get()` と `has()` が共有する。擬似コード:

```
autowirePlan(class):                            // インスタンスを作らずに、各引数の得方を決める
  ref = new ReflectionClass(class)              // 不存在は呼出側で確認済み
  if !ref->isInstantiable(): return 失敗("class '{class}' is not instantiable")
  plan = []
  for param in (ref->getConstructor()?->getParameters() ?? []):
      if param->isVariadic(): continue          // 可変長パラメータは渡さない
      if type が ReflectionNamedType かつ非builtin: plan[] = 依存(type->getName())
      elif param->isDefaultValueAvailable():     plan[] = デフォルト値
      elif type !== null && type->allowsNull():  plan[] = null
      else: return 失敗("unresolvable parameter \${name} of {class}::__construct(); " .
                        "register the entry explicitly or add a default value")
  return plan

instantiate(class):
  plan = autowirePlan(class)
  if 失敗: throw ContainerException(理由)
  args = [依存なら this.get(依存のid)(再帰解決。循環はスタックで検出)、それ以外はデフォルト値または null]
  return ref->newInstanceArgs(args)
```

- クラス・interface型の引数は、デフォルト値や null 許容があっても、その型をidとして解決する(解決できなければ `get()` は失敗する)。
- Union型・Intersection型のパラメータはautowire不能とし、デフォルト値があればそれを使い、なければ null 許容なら null、どちらでもなければ `ContainerException`(推測による解決はしない)。

**has() の判定**: `has($id)` は、`get($id)` が解決できる場合だけ true を返す。インスタンスは作らず、`get()` と同じ順で解決の経路をたどる。alias を解決した正規idについて、次のどれかに当たるときだけ true になる。

1. 解決済み、または登録済みのインスタンスがある(要求されたidと正規idの型に合う)。
2. factory(`\Closure`)が登録されている。factory は実行しないため、factory が投げれば `get()` は失敗しうる。
3. クラス名の束縛先、または未登録のid自身が、インスタンス化できる実在のクラスで(要求されたidと正規idの型に合う)、`autowirePlan` が失敗せず、依存の引数の型がすべて `has()` で true になる(再帰する)。
   - 再帰の途中で、解決中のid(`get()` の `resolving` スタックを含む)に戻ったら循環として false にする。
   - 1回の `has()` の中では、判定の結果を、渡されたid(alias解決前。結果が要求されたidの型の制約に依存するため)ごとに覚え、同じidを再計算しない。これで、共有する依存を持つ依存グラフでも判定の回数がidの数に比例する。覚えた結果は Container に保存せず、その `has()` の中だけで使う(登録を変えた後の `has()` に古い判定を持ち越さない)。循環で得た false も覚える。あるidの解決の途中に循環があれば、どこから `get()` しても `CircularDependencyException` で失敗するため、入口によらず正しい。
   - 既定値の無い組み込み型の引数や、autowire が扱わない型の引数があれば false になる。

**循環依存検出**: `resolving` スタックは `get()` の解決経路全体(autowireの再帰解決を含む)で共有され、`A → B → A` のような間接循環も検出される。`CircularDependencyException` のメッセージには必ず解決チェーン全体(`A -> B -> A`)を含める。旧Loaderの「直接自己依存のみ検出、間接循環は無限再帰でクラッシュ」の逆転仕様である。

**スコープまとめ**:

| 経路 | スコープ |
|---|---|
| `set()` 登録 + `get()` | 共有(シングルトン) |
| 未登録class-string + `get()`(autowire) | 共有(シングルトン) |

非共有(都度生成)の機構はコンテナ本体に持たない(確定判断)。`factory()` / `make()` / 多段aliasは設計書全体(00〜11)に実利用が1件も存在せず、「実利用が実証された機能だけを最小のAPIサーフェスで」(原則3・DR-14)に従い収載しない。PSR-11の必須は `get` / `has` のみであり実装として過小にならない。都度生成が将来必要になればファクトリ自体をサービスとして `set()` し `$c->get(FooFactory::class)->create()` で表現する(コンテナ本体にスコープ概念を持ち込まない)。テストでの引数上書き付き生成は `new` 直書きで足りる(コンストラクタ注入のテスト戦略と一致)。

### 静的ファサードとの接続(DR-09 二層構成)

テンプレート・app側コードに見せる静的API(`ProductPost::permalink()` / `InquiryModel::query()` / `ContactForm::create()` 等)は、**内部で `Kernel::forClass(static::class)->container()` から必要なサービスを解決する薄い皮**として実装する。正準パターン:

```php
// コア基底クラス内部の実装イメージ(各基底の正準APIは各エンティティ文書が定義する)
abstract class PostType
{
    /** 自クラスが属するKernelのContainerを返す。基底クラス共通のヘルパ */
    final protected static function container(): \TMSWP\Core\Container\Container
    {
        return \TMSWP\Core\Kernel\Kernel::forClass(static::class)->container();
    }

    // 例: メタアクセサがレジストリをコンテナから解決する
    // public static function meta(\WP_Post|int $post): \TMSWP\Core\Meta\MetaBag
    // {
    //     return static::container()->get(MetaSchemaRegistry::class)->bagFor(static::class, $post);
    // }
}
```

規約(rules/ へ転記する):

- ファサードは**状態を持たない**。すべての状態はコンテナ管理下のサービスに置く。これにより `Kernel::reset()` + 新規boot だけでテスト間の完全隔離が成立する(旧 `AbstractSingleton` の `static::$instances` 汚染問題の解消)。
- `__callStatic` による動的ファサードは全面禁止(共通規約)。ファサードメソッドはすべて実メソッドとして書く(PHPStan / IDE補完が常に効く)。
- app側サービス(属性なしの普通のクラス)は登録不要で `$container->get(PriceCalculator::class)` のように autowire 解決できる。インターフェースに対する実装の差し替えが必要な場合のみ `#[AsHook]` クラスの早期 `#[Action]` メソッド(または `register()` 例外手段)や `tmswp/activated` 等のタイミングで `set()` する。

## 9. 例外階層

TMSWP のすべての例外は、マーカー interface `TmswpExceptionInterface` を実装する。利用者は `catch (TmswpExceptionInterface $e)` で TMSWP が投げた例外だけをまとめて捕まえられる。マーカーはメソッドを持たない。SPL の型階層(`\LogicException` / `\RuntimeException` 等)はそのまま保つため、`catch (\RuntimeException $e)` のような SPL の型での捕まえ方も変わらない。

本文書は、マーカーと FW の基底例外、Kernel・Container の例外を定義する。すべて `packages/core/src/Exception/` に配置する。

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Exception;

/** TMSWP のすべての例外が実装するマーカー。メソッドを持たない */
interface TmswpExceptionInterface extends \Throwable
{
}

/** FW の基底: プログラムの誤り(宣言・設定・API の誤用)を表す */
class LogicException extends \LogicException implements TmswpExceptionInterface
{
}

/** FW の基底: 実行時にしか検出できない失敗を表す */
class RuntimeException extends \RuntimeException implements TmswpExceptionInterface
{
}

/** Kernel構成・boot手順の誤りを表す。FW利用者(開発者)が修正すべきエラー */
class KernelException extends RuntimeException
{
}

/** app/走査・探索結果ファイルの読み書きの失敗を表す */
class DiscoveryException extends KernelException
{
}

/** コンテナの登録・解決エラー(PSR-11準拠) */
class ContainerException extends RuntimeException implements \Psr\Container\ContainerExceptionInterface
{
}

/** 解決不能なエントリ(PSR-11準拠) */
class NotFoundException extends ContainerException implements \Psr\Container\NotFoundExceptionInterface
{
}

/** コンストラクタ注入の循環依存。メッセージに解決チェーン全体を含めること */
class CircularDependencyException extends ContainerException
{
}
```

継承の規則:

- 個別の例外は、FW の基底 `LogicException` / `RuntimeException` / `InvalidArgumentException`(`07-support.md` §6)のいずれかを継承する。SPL の例外を直接継承しない。拡張パッケージの例外も同じ規則に従う。
- 仕様が特定の SPL 型を指定している例外は、その型を継承し、マーカーを直接実装する(`07-support.md` §6 の `UnknownLogChannelException extends \OutOfBoundsException`)。
- PSR-11 の例外は、PSR の interface を実装したうえで FW の基底 `RuntimeException` を継承する(上記)。

全パッケージの例外の継承関係(名前空間を省いたものは `TMSWP\Core\Exception\`。括弧内は定義している文書):

```
\LogicException
├── LogicException
│   ├── EntityDefinitionException                     (03 §1.7)
│   ├── InvalidSchemaException                        (04 §4.17)
│   ├── DuplicateMetaKeyException                     (04 §4.17)
│   ├── EntityNotRegisteredException                  (04 §4.17)
│   ├── SchemaException                               (06 R4)
│   ├── InvalidQueryException                         (06 R4)
│   └── Form\FormConfigException                      (05)
│       └── Form\UnknownFieldException                (05)
└── \InvalidArgumentException
    └── InvalidArgumentException                      (07 §6)
        └── InvalidTemplateSlugException              (07 §6)
\RuntimeException
├── RuntimeException
│   ├── KernelException                               (本文書)
│   │   └── DiscoveryException                        (本文書)
│   ├── ContainerException                            (本文書)
│   │   ├── NotFoundException                         (本文書)
│   │   └── CircularDependencyException               (本文書)
│   ├── EntityPersistenceException                    (03 §1.7)
│   ├── EntityDataException                           (03 §1.7)
│   ├── QueryException                                (03 §1.7、06 R4)
│   ├── MigrationException                            (06 R4)
│   ├── HydrationException                            (06 R4)
│   ├── Form\TempFileException                        (05)
│   ├── TemplateNotFoundException                     (07 §6)
│   ├── TMSWP\AcfBridge\Exception\AcfBridgeException  (09。abstract)
│   │   ├── AcfNotActiveException
│   │   ├── AcfBridgeRuntimeException
│   │   └── GenerationException
│   ├── TMSWP\Thumbnail\Exception\InvalidThumbSourceException (08)
│   └── TMSWP\Thumbnail\Exception\InvalidThumbOptionException (08)
└── \OutOfBoundsException
    └── UnknownLogChannelException                    (07 §6。マーカーを直接実装)
```

- 本文書の例外は `final` にしない(数少ない例外)。
- 例外メッセージは英語で固定し、必ず「何が・どこで(パス/FQCN)・どう直すか」を含める。

---

# 利用例(app側コード)

### テーマのtmswp-kernel.phpとfunctions.php

Kernelの構成は、テーマ・プラグインのルート(探索結果ファイル`tmswp-discovery.php`と同じ場所。§6)の`tmswp-kernel.php`に置き、このファイルが`KernelConfig`を返す。functions.php・プラグイン本体は`Kernel::boot(KernelConfig::load(__DIR__ . '/tmswp-kernel.php'))`でbootする。`KernelConfig::load($file)`はファイルを読み込み、戻り値が`KernelConfig`ならそれを返す。ファイルが無い・読めない場合と、戻り値が`KernelConfig`でない場合は、ファイルのパス(後者は受け取った型も)を含む`KernelException`を投げる(代わりの構成は使わない)。`require`の戻り値を`Kernel::boot()`に直接渡さないのは、戻り値の型が`mixed`になり、PHPStanが型を確かめられないためである。WordPressを起動しない`tms-acf`(09-acf-bridge §5.1)も同じ`load()`でこのファイルを読むため、WordPressの関数を呼ばない(拡張のコンストラクタも同じ。§3.10)。

```php
<?php
// tmswp-kernel.php

declare(strict_types=1);

return \TMSWP\Core\Kernel\KernelConfig::theme(
    appDir: __DIR__ . '/app',
    appNamespace: 'App',
);
```

```php
<?php
// functions.php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

\TMSWP\Core\Kernel\Kernel::boot(\TMSWP\Core\Kernel\KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
```

テーマの composer.json(スターターが生成):

```json
{
    "require": { "tmswp/core": "^1.0" },
    "autoload": { "psr-4": { "App\\": "app/" } }
}
```

### プラグインのtmswp-kernel.phpと本体(配布・併用前提なので名前空間を固有化)

プラグイン本体のパスは`tmswp-kernel.php`の位置から決める(`__FILE__`は使わない)。プラグインのbootと`tms-acf`のどちらから読んでも同じ値になる。

```php
<?php
// tmswp-kernel.php

declare(strict_types=1);

return \TMSWP\Core\Kernel\KernelConfig::plugin(
    file: __DIR__ . '/my-shop.php',
    appDir: __DIR__ . '/app',
    appNamespace: 'MyShopPlugin',
);
```

```php
<?php
/**
 * Plugin Name: My Shop
 */
// my-shop.php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

\TMSWP\Core\Kernel\Kernel::boot(\TMSWP\Core\Kernel\KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
```

### app/ にクラスを置くだけで発見される

```php
<?php
// app/Post/ProductPost.php — boot側の変更は一切不要

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class ProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 0, showInRest: true)]
    public const META_PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')->label('製品')->labelLanguage(LabelLanguage::Ja)->hasArchive();
    }
}
```

### Hookはメソッド属性で宣言する(本番の boot ではクラスは発火まで未ロード)

```php
<?php
// app/Hook/ProductHook.php — register() の命令的列挙は不要。',10,2' の手書きも存在しない

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class ProductHook extends Hook
{
    #[Action('init', priority: 20)]
    public function registerMenus(): void
    {
        register_nav_menus(['primary' => 'メインメニュー']);
    }

    #[Action('save_post_product')]
    public function onSave(int $postId, \WP_Post $post): void // acceptedArgs=2 を宣言引数数から自動導出
    {
        // ...
    }

    #[Filter('excerpt_length')]
    public function excerptLength(int $length): int
    {
        return 40;
    }
}
```

`register()` をオーバーライドしないため、このクラスは本番(debug=false)の boot 時には一切ロードされず、宣言した各フックの発火時に初めて Container 経由でインスタンス化される(§4・§7)。debug の boot は走査(§4)でクラスを読み込む。

### app側サービスとコンストラクタ注入

```php
<?php
// app/Service/PriceCalculator.php — 属性なし。発見・初期化はされないが、autowireで解決できる

declare(strict_types=1);

namespace App\Service;

final class PriceCalculator
{
    public function __construct(
        private readonly TaxRateProvider $taxRates, // 同じくapp/のクラス。再帰的にautowire
    ) {
    }

    public function withTax(int $price): int
    {
        return (int) round($price * (1 + $this->taxRates->current()));
    }
}
```

```php
// 利用側(テンプレート外のコード)
$calculator = \TMSWP\Core\Kernel\Kernel::get('App')->container()->get(\App\Service\PriceCalculator::class);
```

### テスト(ユニット層: WP非依存 + Brain Monkey)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Exception\CircularDependencyException;

final class ContainerTest extends TestCase
{
    protected function tearDown(): void
    {
        \TMSWP\Core\Kernel\Kernel::reset(); // テスト間のKernelレジストリ隔離
        parent::tearDown();
    }

    public function test_circular_dependency_is_detected(): void
    {
        $container = new Container();
        $this->expectException(CircularDependencyException::class);
        $container->get(CircularA::class); // CircularA(CircularB $b) / CircularB(CircularA $a)
    }
}
```

---

# 旧iwf2bからの設計根拠(簡潔に)

| 旧Loader/AbstractSingletonの問題(実コードで確認) | 本設計での逆転 |
|---|---|
| 毎リクエスト、対象ディレクトリ全 .php を `new ReflectionClass` で実ロード(キャッシュなし) | Attributeメタデータを探索コマンドが探索結果ファイルに生成してコミットする。本番の boot はinclude 1回・Reflectionゼロ・appクラスロードゼロ(§4・§6) |
| Loaderコンストラクタが `src/Iwf2b` を自己スキャンし、コア同梱具象(Post/PagePost/CategoryTax/PostTagTax、abstract漏れのAbstractUser)を無条件初期化 | 走査範囲を appDir に構成上限定+`TMSWP` 名前空間を予約として boot 時に拒否(§2・§4)。コアに自動初期化される具象を置かない |
| ロード順が RecursiveDirectoryIterator の列挙順(OS・FS依存・非決定) | files/components を strcmp ソート。環境非依存の決定的順序を受け入れ基準で保証(§4) |
| `get_dependencies()` の間接循環(A→B→A)が無限再帰でクラッシュ。直接自己依存しか検出しない | 静的依存宣言そのものを廃止(初期化順はタイミング表で固定)。インスタンス依存はコンテナの解決スタックで間接循環まで検出し、チェーン付き `CircularDependencyException`(§7・§8) |
| 依存先が auto_init=false / 走査対象外だと初期化を黙ってスキップ。`ReflectionException` も `continue` で黙殺 | 探索失敗・初期化失敗は debug に関係なく常に例外(設定の誤り)。本番で隔離するのはフックメソッドの実行時の失敗と遅延 boot のキャッチアップだけで、必ずその Kernel のロガー(`$kernel->logger()`)に `error` で記録する。黙殺経路ゼロ(§4・§7) |
| `bootstrap()` 呼出位置で `initialize()` が即時同期実行され、フック登録が早すぎ/遅すぎる事故 | boot は add_action のみ。全初期化をWPフックに整合させ、正準タイミング表で固定。遅延bootは検出して例外/ログ+キャッチアップ(§3.3・§7) |
| `AbstractSingleton` の `final private __construct` + `static::$instances` でDI・モック・テスト不能。`func_get_args` 転送はデッドコード | PSR-11コンテナ+コンストラクタ注入。ファサードは無状態の薄い皮で、`Kernel::reset()` によりテスト隔離可能(§8) |
| 起動規約が未文書化(リポジトリ内に bootstrap 呼び出し例ゼロ)で、利用側は暗黙知のコピペ | `Kernel::boot()` + `KernelConfig` ファクトリを正準ブートAPIとして文書化し、スターターに同梱(DR-13) |
| Loaderコンストラクタが `load_textdomain` / `untrailingslashit` 等のWP関数へ密結合し単体テスト不能 | Kernel/Discovery/Container はWP関数依存を `add_action` / `did_action` / 定数参照の最小限に限定。KernelConfig/Container/DiscoveryFile はWP関数ゼロでユニットテスト可能(§2・§6・§8) |

---

# 受け入れ基準

実装は以下をすべて満たすこと。検証は`composer qa`(= lint + analyse + acf:check:fixtures + discover:check + test)およびwp-env統合テストで行う。

## 機能(ユニットテストで検証)

- [ ] `KernelConfig::theme()/plugin()` が不正入力(不在ディレクトリ・不正名前空間・`TMSWP` 予約・プラグインファイル不在)で `KernelException` を投げ、メッセージに対象パス/名前空間を含む
- [ ] 同一 appNamespace への2回目の `Kernel::boot()` が `KernelException` になる(多重boot禁止)
- [ ] 入れ子の appNamespace(`App` と `App\Sub`)・入れ子/同一の appDir・探索結果ファイルが同じになる兄弟の appDir の boot が `KernelException` になり、先に boot した Kernel の探索結果ファイルは上書きされない
- [ ] boot の途中(拡張の `register()`・`logChannels`・キャッチアップの初期化)から別の Kernel を boot すると `KernelException` になり、外側の boot も失敗してレジストリに何も残らない。失敗した boot の後でも、同じ名前空間を boot できる
- [ ] `Kernel::forClass()` が最長一致でKernelを返し、未該当FQCNで `KernelException` を投げる(null返しのサイレント失敗がない)
- [ ] Container: `set`(Closure/object/string束縛)・単段 `alias`・autowire が共有(シングルトン)スコープで動作し、公開APIが `set` / `alias` / `get` / `has` のみである(`factory()` / `make()` / 多段aliasが存在しない)
- [ ] Container: 間接循環依存(A→B→A、3クラス以上の循環含む)が**無限再帰せず** `CircularDependencyException` となり、メッセージに解決チェーン全体(`A -> B -> A`)を含む
- [ ] Container: 未解決idの `get()` が `NotFoundException`、解決不能なコンストラクタ引数が `ContainerException`、解決済みidへの再登録・自己参照alias・alias済みidを指す `alias()`(連鎖の試み)が `ContainerException` になる
- [ ] Container: `has()` が false のidは `get()` も失敗し(必須の組み込み型引数・束縛の無い interface への依存・循環依存・実在しないクラスへの束縛)、true のidは `get()` が成功する(依存の束縛付きの autowire・既定値と null 許容の引数・interface の束縛・サブクラスへの束縛・alias)。`has()` は factory を実行しない
- [ ] Container: クラス名・interface名のidへの、型の合わないインスタンス・クラスの束縛・alias が登録の時点で `ContainerException` になり、factory の結果の型の食い違いは `get()` の時点で `ContainerException` になる(alias を経由しても同じ)。PHPStan が `get(Foo::class)` を `Foo` と推論する
- [ ] Container: factory の中で投げられた例外が、id を含む `ContainerException` に包まれて `previous` に元の例外を持ち、`NotFoundException`・`CircularDependencyException` は二重に包まれない
- [ ] 全パッケージの `src/` の例外クラスが `TmswpExceptionInterface` を実装する(§9)
- [ ] Discovery: 同一のapp/ファイル群に対し、ファイルの列挙順・作成順に関係なく、`DiscoveryResult` が同じ内容・同じ並びになる(components は (class, attributeClass)、hookMethods は (class, method, hook) の中で priority と kind の順。文字列は strcmp で比べ、数字だけのフック名も strcmp の順になる)。候補ファイルは appDir からの相対パスの strcmp 順に読む
- [ ] Discovery: クラス名とファイル名/名前空間の不一致が debug に関係なく `DiscoveryException`(ファイルパス・期待FQCN入り)になる。黙殺・スキップされる分岐が存在しない
- [ ] Discovery: 走査中のオートロードで起きた Error(ParseError・未定義の親クラスを含む)と、属性の読み取りで起きた Error(属性クラスの ParseError・属性引数の未定義のクラス定数)が、元の例外を previous に持ち、クラス名とファイルを含む `DiscoveryException` になる
- [ ] Discovery: 属性引数(配列のキーと値。入れ子を含む)とフック名に、Kernel のルート・ABSPATH・WP_CONTENT_DIR が区切り文字の境界で現れると探索失敗になる(ルート /srv/a に対し /srv/a・/srv/a/x.php・file:///srv/a/x は失敗、/srv/abc・/srv/a-b は通る)。ABSPATH・WP_CONTENT_DIR は設定された値の表記と実パスのどちらで現れても失敗になり(symlink のときは両方、末尾に区切り文字を付けた定義も同じ)、ディレクトリが存在しないときは環境パスを含まない app の探索が成功する。同じソースを絶対パスの違う2つのルートに置いて生成した探索結果ファイルが、バイト単位で一致する
- [ ] Discovery: Hook 基底を継承しない `#[AsHook]` クラス(`register()` という名前のメソッドを持つ場合を含む)が探索失敗になる
- [ ] Discovery: 探索失敗のときに途中まで収集した登録が残らない(boot が例外になり、先に走査したクラスのフックも Kernel のレジストリも登録されない)
- [ ] Discovery: 属性なしクラス・interface・trait・小文字始まりファイル(`helpers.php`)はエラーなくスキップされ、abstractクラスへのコンポーネント属性付与は探索失敗として扱われ、backed enum への付与(`#[AsOptionGroup]`)は正常に収集される
- [ ] Discovery: `#[AsHook]` クラスの `#[Action]`/`#[Filter]` メソッドが (class, method, hook, priority, acceptedArgs=宣言引数数, kind=属性の種類) の `DiscoveredHookMethod` として収集され、`register()` をオーバーライドしない `#[AsHook]` クラスは components に現れない
- [ ] Discovery: `#[Action]`/`#[Filter]` の誤用(`#[AsHook]` なしクラスへの付与・static/非public/可変長引数メソッドへの付与・フックを1つも宣言しない `#[AsHook]` クラス)が探索失敗として扱われる
- [ ] Discovery: 属性引数にオブジェクト(new式)を渡した場合に探索失敗となる(探索結果ファイルに書き出せる値に限る)
- [ ] Discovery: `#[OptionSchema]` の付与先の誤り(クラス自身・クラスの定数・グループの enum のケースでない定数・`#[AsOptionGroup]` の無い enum のケース)が探索失敗となり、`#[AsOptionGroup]` を付けた enum のケースへの付与は正常に収集される(ユニットテスト)
- [ ] DiscoveryFile: write→load の往復で `DiscoveryResult` が完全復元される(enum引数・hookMethods 含む)。ファイルが無い・`include` が throw する/配列以外を返す・schema不一致・appNamespace不一致・appDir不一致・破損エントリで、ファイルパスと `wp tmswp discover` の案内を含む `DiscoveryException` を投げる
- [ ] DiscoveryFile: 同じ探索結果からは、絶対パスの違う場所でもバイト単位で同一の内容を生成し、内容に絶対パス・mtime・生成時刻を含まない。書き込みの失敗は `DiscoveryException` になる
- [ ] Kernel: debug=false の boot が探索結果ファイルだけを読み、app/ を走査しない。ファイルが無ければ boot が `DiscoveryException`(ファイルパス入り)になる。debug=true の boot は毎回走査し、探索結果ファイルを読まない
- [ ] `wp tmswp discover` が全 Kernel の探索結果ファイルを書き、走査の失敗ではどのファイルも書かずに非0で終わる。`--check` はファイルが無い・内容が違う Kernel を appNamespace とファイルパスで列挙して非0で終わり、すべて最新なら成功する。起動中の Kernel が無い場合は非0で終わる
- [ ] `Kernel::reset()` 後に同一名前空間で再bootできる(テスト隔離)
- [ ] `Kernel::boot()` が、WordPress 7.0 未満(`$wp_version` の最初の `-` より前を比べる。`6.9-RC1` を含む)で debug に関係なく `KernelException` を投げ、`7.0-RC1` 等の 7.0 のリリース候補・開発版と 7.0 以降は受け付ける。`$wp_version` が無いと `KernelException` を投げる
- [ ] `KernelConfig` の debug を省略すると、`WP_DEBUG` の真偽が既定値になる(`1` 等の真値でも true、未定義・偽値なら false)
- [ ] 拡張の `register()` で Container の `MetaStorageInterface`・`OptionStorageInterface` の id に登録した実装を、`#[UseMetaStorage]` の無いエンティティの `meta()` と `storage` を指定しないオプショングループが使う。何も登録しなければ `WpMetaStorage`・`WpOptionStorage` になる。interface を実装しない登録は登録の時点か boot の時点で `ContainerException` になって boot が失敗し、boot の後の差し替えは `ContainerException` になる(04-meta-options §4.16)
- [ ] `KernelConfig` の `extensions` の拡張が、boot 中に渡した順で1回ずつ、その Kernel を受け取って呼ばれ、拡張はその Kernel の debug を受け取る。同じ Kernel に同じクラスの拡張を2回渡すと debug に関係なく `KernelException` になり、別の Kernel が同じクラスを登録するのは通る。プラグインの有効化のサンドボックスでは呼ばれない
- [ ] `KernelConfig` の `logChannels` で宣言したチャンネルが `Kernel::logger()`(Container の `LoggerRegistry::class` と同一)になり、戻り値がチャンネル名 => `LogChannel` の空でない配列でなければ boot が `KernelException` になる
- [ ] 他の vendor の installed.php の tmswp/core のリリース版が `Kernel::VERSION` と食い違うと、boot が版と install_path を含む `KernelException` になる。同じ版・dev 版・プレリリース・replace だけの記載は通る
- [ ] 古い psr/container(1.0)・psr/log(1.x)の interface を先に読み込んだ別プロセスで、boot が、interface を定義したファイルと要求する版を含む `KernelException` になり、`Container`・`LoggerRegistry` は読み込まれない
- [ ] `Kernel::forFile()` が最も長く一致するルートの Kernel を返し、どのルートにも含まれないファイルで `KernelException` を投げる。子テーマが有効なとき、子テーマのファイルはテーマの Kernel に解決され、プラグインの Kernel のルートには含まれない
- [ ] 本番の遅延bootのキャッチアップで動くアプリのコードの `Log::error()` が、その Kernel に記録される。キャッチアップが失敗した boot の後、`Kernel::all()` にその Kernel が残らない
- [ ] 拡張の `register()` が Container に入れたサービスを、init 10 の初期化が、init 未発火の通常の boot と、init 発火済みの本番の遅延boot(キャッチアップ)の両方で使える
- [ ] `Kernel::forFile()` が、シンボリックリンクを経由したパスを実体の Kernel に解決し、`..` でルートの外へ出るパスと存在しないパスで `KernelException` を投げる
- [ ] 本番の遅延bootのキャッチアップが、`$wp_actions` で最初に発火した順、同じフックの中では priority の昇順で実行される(`plugins_loaded` のグループが `init` より先。MetaSchema の WordPress への登録(`init` 20)も同じ規則で、メタスキーマの `MetaSchemaRegistry` への登録(boot 時の初期化)はキャッチアップより先)。未発火のフックのグループは `add_action` される。debug の遅延bootは、最初に発火したフックのグループで `KernelException` になり、`#[Action]`/`#[Filter]` を含めどのフックも登録せず、どのグループも実行しない
- [ ] キャッチアップ中の初期化が`defer()`で発火済みのフックへ予約した処理が、キャッチアップの中の(最初に発火した順, priority)の位置で、遅延bootのログを記録せずに実行される(例: `init` 9のタクソノミーの初期化が予約した`init` 11の再試行が、`init` 10の投稿タイプの登録の後、`init` 20の前に動く。統合テスト)。拡張の`register()`が`defer()`で発火済みのフックへ予約した処理も、同じくキャッチアップの中で実行される(遅延bootでも、正常なboot位置のテーマのKernelが発火済みの`plugins_loaded`へ予約した場合も)。手順5aより前かbootの後の予約と、未発火のフックへの予約は`add_action`で登録される
- [ ] initの前のbootで、エンティティのメタスキーマが`MetaSchemaRegistry`に登録され、`register_post_meta()`は呼ばれない。`#[MetaSchema]`と同じキーの外部管理の申告(`markExternallyManaged()`)は、申告のストレージが宣言したエンティティのストレージと違えば、その時点で`DuplicateMetaKeyException`になり、メッセージは宣言の定数名と申告元(ACFのフィールドグループのkey)を含む。ストレージが同じなら衝突しない
- [ ] コンポーネントの初期化の失敗(Initializer の例外、`ComponentInitializerInterface` を実装しない Initializer、探索結果ファイルの属性を復元できない)が、本番(debug=false)でも例外になる(例: `PostType` を継承しない `#[AsPostType]` のコンポーネントは、boot 時の初期化で boot の例外になる)。属性を復元できない場合(属性クラスが無い・`ComponentAttributeInterface` を実装しない・属性の生成で例外が起きる)は、メッセージに探索結果ファイルの作り直しの案内(`wp tmswp discover`)を含む `DiscoveryException` になり、属性の生成で起きた例外(引数の不一致による `ArgumentCountError`・`TypeError` 等の Error を含む)を previous に持つ。本番のキャッチアップ中の失敗では boot が例外になる
- [ ] `#[Action]`/`#[Filter]` メソッドの失敗(Container での解決の失敗を含む)が、debug では例外のまま伝わり、本番ではその Kernel のロガー(`$kernel->logger()`)に `error` で記録されてそのメソッドだけスキップされ、第1引数が返る。引数を宣言しない `#[Filter]` も第1引数を受け取って登録され、失敗時は元の値を、成功時はメソッドの戻り値を返し、メソッドには引数を渡さない(宣言引数数に切り詰める)
- [ ] 本番の `#[Action]`/`#[Filter]` メソッドの失敗のログの文脈が、`class`・`method`・`hook`・`exceptionClass`・`exceptionAt`(ファイル:行)・`previousClasses` だけで、例外のオブジェクト・メッセージ・トレースを含まない
- [ ] WordPress なしのユニットテストで、グローバルの `$wpdb` を置かずに Kernel を boot できる(boot は `$wp_version` と `$wp_actions` だけを要し、Migrator・Connection を解決しない)
- [ ] 有効化・無効化のコールバックが `delete_option('rewrite_rules')` と `tmswp/activated`・`tmswp/deactivated` の発火だけを行い(`flush_rewrite_rules()`・`add_option()` を呼ばない)、boot が毎リクエストの確認(`init` での `get_option()` 等)を登録しない
- [ ] PHPStan ルールが、PHPUnit の `TestCase` の子孫クラスの外からの `Kernel::reset()` の参照(静的呼び出し、インスタンス経由の呼び出し、両者の第一級 callable、定数文字列のメソッド名、`class-string<Kernel>`・定数文字列のクラス名)を報告し、子孫クラスの中(クロージャを含む)、Kernel 以外の `reset()`、定数に定まらない名前、callable の配列・文字列は報告しない(§3.5)

## 統合(wp-env + WordPress のテストライブラリ(WP_TESTS_DIR)で検証)

- [ ] 本番相当(debug=false)で、探索結果ファイルが無いと boot が例外になる。ファイルがあれば走査せずに起動する(app/ を走査すれば見つかるクラスのフックが登録されず、そのクラスもロードされず、ファイルにだけ書いたフックが登録される)
- [ ] `boot()` 直後(フック発火前)の時点で `register_post_type` / `register_taxonomy` / `add_role` / DB書込が一切発生していない(即時同期初期化の禁止)
- [ ] init 完走後、`#[AsTaxonomy]`(init 9)→`#[AsPostType]`(init 10)→`#[AsUserRole]`(init 11)の順で登録され、`PostTypeDefinition` の taxonomies 連携が機能する。ロールは FQCN の順に関係なく投稿タイプの後に登録される
- [ ] 本番相当(debug=false)の init の前の boot の後、init の前でも、app の `#[Action('init')]`(エンティティの登録と同じ priority 10 で先に動く)の中でも `meta()` が使え、`register_post_meta()` は `init` 20 で行われる。`#[UseMetaStorage]` と `#[WithMetaRevisions]` が探索結果ファイルの経路でも保持される
- [ ] 本番相当(debug=false)でエンティティの `register()` が例外を投げても、メタスキーマは boot で登録済みで、例外はログに記録されるだけで済まされず init から伝わる
- [ ] acf-bridgeの外部管理のキーの申告(`init` 6)が、申告と違うストレージのエンティティの`#[MetaSchema]`と同じキーなら、その時点で`DuplicateMetaKeyException`になり、`register_post_meta()`(`init` 20)には進まない
- [ ] `#[Action]`/`#[Filter]` メソッドが宣言どおりの(フック, priority)と、引数の数(`#[Action]` は宣言引数数、`#[Filter]` は `max(1, 宣言引数数)`)で `add_filter` 登録され、フック発火時に Container 経由で1回だけインスタンス化されて呼ばれる。属性宣言のみ(`register()` 非オーバーライド)の `#[AsHook]` クラスは、本番相当(debug=false)の boot で、宣言フックの発火までロードされない(`get_declared_classes()` の差分で検証。debug の boot は走査ですべてのクラスを読み込む)
- [ ] Kernel が各コンポーネントの Initializer(エンティティ基底の `register()` を含む)を1リクエスト内で正確に1回だけ呼ぶ(Initializerへのスパイで検証。register() 側に再入ガードは存在しない)
- [ ] テーマ(`App`)+プラグイン(`MyShopPlugin`)の2 Kernel併用で、Container・探索結果ファイル・components が相互に独立し、`Kernel::all()` にテーマとプラグインの2つの Kernel が含まれる
- [ ] テーマとプラグインの2つの Kernel が、それぞれ同じクラスの拡張を登録して共存し、各拡張が自分を登録した Kernel だけを受け取る
- [ ] 他の vendor(別のプラグインの Composer のオートローダ)の installed.php に別の版の tmswp/core があると、boot が `KernelException` になる
- [ ] Kernel の `labelLanguage` が Ja のとき、`labelLanguage()` を指定しない投稿タイプのラベルが日本語で登録される
- [ ] `#[Action]`/`#[Filter]` メソッドが WordPress と同じ弱い型変換で呼ばれ、数値文字列を `int` 引数に渡すフックが通る
- [ ] テーマ切替(`after_switch_theme`)とプラグイン有効化で `rewrite_rules` が消え、次のリクエストで CPT のルールを含めて作り直される。プラグイン無効化で `rewrite_rules` が消え、次のリクエスト(CPT が登録されない)のルールにその CPT のルールが残らない
- [ ] 前提の確認: `rewrite_rules` が空のとき、WordPress が `wp_loaded` より前に作り直したルールを保存せず、`wp_loaded` での作り直しを登録する
- [ ] 通常のリクエストで Kernel が DB のクエリを増やさない(boot と、通常のリクエストで発火するフックに Kernel が登録したコールバックの実行の前後で `$wpdb->num_queries` が変わらない)
- [ ] 発火済みフックに対する遅延bootが、debug=true のときに `KernelException`、本番相当でログ+キャッチアップ実行になる。debug=true の例外の後に `$wp_filter` への登録が増えていない(コンポーネントと `#[Action]`/`#[Filter]` メソッドの両方を持つ Kernel で確かめる)
- [ ] テーマ形態・プラグイン形態の boot が `template_include` にコールバックを登録しない(テンプレート階層に介入しない。§3.7)
- [ ] コミット済みの fixture のテーマ・プラグインの探索結果ファイルが、走査結果と一致する(統合テストと `composer discover:check`。10-quality §5)

## 品質

- [ ] `composer analyse`: PHPStan level max・baselineなしで通過(szepeviktor/phpstan-wordpress)
- [ ] `composer lint`: PER Coding Style 2.0(php-cs-fixer の `@PER-CS2x0`)+ `declare(strict_types=1)` 全ファイル適用
- [ ] Kernel/Discovery/Container/DiscoveryFile/KernelConfig に `__callStatic` / `extract()` / グローバル可変状態(`Kernel::$kernels`・`$booting`・`$cliRegistered`・`$textdomainRegistered` を除く)が存在しない。グローバル変数は、ソースのトークンで `global` 文を禁止し、`$GLOBALS` はクラスごとに許可した名前を `$x = $GLOBALS['<名前>'] ?? null;`(代入先はスーパーグローバル・`$this` 以外の変数)で読む形だけを許可して確かめる。参照(`=&` 等)でわざと作った別名への代入と、可変変数による `${'GLOBALS'}` は対象外
- [ ] KernelConfig・Container・DiscoveryFile・DiscoveryResult・DiscoveredComponent のユニットテストがWP関数のスタブなし(または Brain Monkey の最小スタブのみ)で動作する

## 検証コマンド

```bash
composer lint     # php-cs-fixer (@PER-CS2x0 + strict_types)
composer analyse  # PHPStan level max
composer test     # test:unit(PHPUnit 11 ユニット層、Brain Monkey)+ test:integration(wp-env 統合層)
composer qa       # lint + analyse + acf:check:fixtures + discover:check + testを一括
# 統合層
composer wp:start && composer test:integration   # 正式コマンド名はQA文書に従う
composer discover:check                            # fixture の探索結果ファイルの照合(wp-env)
```
