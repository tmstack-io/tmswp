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
  - `src/Attribute/ComponentAttributeInterface.php` — `TMSWP\Core\Attribute\ComponentAttributeInterface` (本文書で新設定義)
  - `src/Attribute/Action.php` — `TMSWP\Core\Attribute\Action` (メソッドレベルフック属性。本文書で新設定義。§5)
  - `src/Attribute/Filter.php` — `TMSWP\Core\Attribute\Filter` (メソッドレベルフック属性。本文書で新設定義。§5)
  - `src/Attribute/AsHook.php` / `src/Hook/Hook.php` / `src/Hook/HookInitializer.php` — `#[AsHook]` 属性・Hook 基底・Hook の Initializer(本文書が正準定義。§5)
  - 各コンポーネント属性は本文書の `ComponentAttributeInterface` 契約に従う。コア属性は6種(`AsPostType` / `AsTaxonomy` / `AsUserRole` / `AsModel` / `AsHook` / `AsOptionGroup`)。属性のパラメータ仕様と各 Initializer の中身は各機能文書が定義する。ただし `AsHook` と Hook 基底・`HookInitializer`、メソッドレベルのフック属性 `#[Action]` / `#[Filter]` は本文書が正準定義する(§5)
  - `src/View/ActionRouter.php` — `TMSWP\Core\View\ActionRouter`(本文書はboot時の生成・登録のみを定義。dispatch の正準仕様・actions ファイル規約・View 変数バッグは View/Support/Log 文書が定義する)
  - `src/Container/Container.php` — `TMSWP\Core\Container\Container`
  - `src/Exception/KernelException.php` / `DiscoveryException.php` / `ContainerException.php` / `NotFoundException.php` / `CircularDependencyException.php`

スコープ外: 各属性クラス(`AsPostType` 等)のパラメータ仕様と各初期化処理の中身(register_post_type の発行内容等)。これらは各エンティティ文書が定義する。本文書はそれらが従うべき**契約(インターフェース)とタイミング**のみを確定する。

## 依存文書

- 01 全体アーキテクチャ文書(リポジトリ構成・コーディング規約・例外方針) — 例外基底の整合を取ること
- エンティティ定義文書(PostType / Taxonomy / UserRole) — `AsPostType` / `AsTaxonomy` / `AsUserRole` 属性と各 Initializer の定義元
- Meta / Option 文書 — `AsOptionGroup` 属性と OptionGroup Initializer の定義元
- Model 文書 — `AsModel` 属性・`Migrator`(有効化時/admin_init 版数比較・WP-CLI)の定義元
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
│  2. Container 生成・コアサービス登録                                  │
│  3. Discovery::discover()                                             │
│       ├─ debug時: Discovery::scan() … appDir を毎回走査              │
│       └─ 本番: DiscoveryFile::load() … 探索結果ファイルのincludeのみ │
│  4. scheduleAll() … #[Action]/#[Filter] メソッドを add_filter で直接  │
│     登録し、コンポーネント初期化を (フック名, priority) ごとに         │
│     add_action 登録                                                    │
│       ※ ここでは一切インスタンス化しない(即時同期初期化の禁止)       │
│  5. コンテキスト別の運用フック登録(有効化・無効化フック)            │
└────────────────────────────────────────┘
        │ WPライフサイクル進行
        ▼
plugins_loaded(0)/after_setup_theme(0) … #[AsHook] register() 例外手段(オーバーライド宣言クラスのみ)
plugins_loaded(1)/after_setup_theme(1) … #[AsModel] レジストリ登録
init(5)  … #[AsOptionGroup]
init(9)  … #[AsTaxonomy]
init(10) … #[AsPostType] / #[AsUserRole]
宣言フック発火時 … #[Action]/#[Filter] メソッド(Hookクラスはここで初ロード)
```

boot() 時点で発生する副作用は **add_action / add_filter(`#[Action]`/`#[Filter]` メソッドの直接登録、テーマ形態の ActionRouter の `template_include` 登録) / register_activation_hook / WP-CLIコマンド登録** に限定される。boot はファイルを書かない(探索結果ファイルを書くのは探索コマンドだけ。§3.6)。`register_post_type` 等のWP登録系APIはすべて上記フック発火時に実行される。

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
- WordPressのプラグイン有効化サンドボックス(`WP_SANDBOX_SCRAPING`)では、プラグイン本体が `plugins_loaded` 後に読み込まれる。この経路では通常の属性初期化スケジューリングは行わず、`register_activation_hook` / `register_deactivation_hook` と Model migrator の購読だけを登録する。通常リクエストの遅延bootは引き続き例外とする。

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
     * @param bool          $actions     テーマ形態のテンプレート前処理(ActionRouter)を有効化するか(既定 true)。Pluginコンテキストでは常に false へ正規化される(§3.7)
     */
    private function __construct(
        public KernelContext $context,
        public string $appDir,
        public string $appNamespace,
        public ?string $pluginFile,
        public bool $debug,
        public bool $actions,
    ) {
    }

    public static function theme(
        string $appDir,
        string $appNamespace = 'App',
        ?bool $debug = null,
        bool $actions = true,
    ): self;

    public static function plugin(
        string $file,
        string $appDir,
        string $appNamespace = 'App',
        ?bool $debug = null,
    ): self;

    /**
     * 探索結果ファイルの絶対パス。appDir の親ディレクトリ(テーマ・プラグインのルート)直下に固定する。
     * 形式: dirname(appDir) . '/tmswp-discovery.php'
     * 例: '/path/to/wp-content/themes/my-theme/tmswp-discovery.php'
     */
    public function discoveryFile(): string;

    /**
     * このアプリ構成を一意に識別するID。rewrite flush フラグのオプション名等に使用する。
     * 形式: strtolower(str_replace('\\', '-', appNamespace)) . '-' . substr(md5(appDir), 0, 12)
     * 例: 'app-3f9a2b1c0d4e'
     */
    public function instanceId(): string;
}
```

### 振る舞い仕様(ファクトリ内の検証・正規化)

`theme()` / `plugin()` は以下を順に行い、失敗時は `TMSWP\Core\Exception\KernelException` を投げる(探索失敗の黙殺禁止はここから始まる)。

1. `$appNamespace` を `trim($appNamespace, '\\')` で正規化し、`/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/` に一致しなければ例外。`TMSWP` または `TMSWP\` で始まる名前空間は予約済みとして例外(コア名前空間の走査を構成段階で遮断する)。
2. `realpath($appDir)` が `false`、またはディレクトリでない、または読取不可なら例外。メッセージには渡されたパスをそのまま含める(例: `app directory not found: {path}`)。
3. `plugin()` のみ: `realpath($file)` が `false` またはファイルでないなら例外。さらに `$appDir` が `$file` の属するディレクトリ配下にあることは要求しない(シンボリックリンク構成を許容)。
4. 探索結果ファイルの置き場は `discoveryFile()` が返す `dirname(appDir) . '/tmswp-discovery.php'` に固定し、引数では変えない(テーマなら `<theme>/tmswp-discovery.php`、プラグインなら `<plugin>/tmswp-discovery.php`)。コードと一緒にコミット・デプロイされる場所に置くためである(§6)。探索結果ファイルは Kernel ごとに1つで、同じ親ディレクトリに複数の appDir を置く構成(`<root>/app-a` と `<root>/app-b`)は同じファイルを共有してしまうため、2つ目の boot が `KernelException` になる(§3 手順2-c)。
5. `$debug` が null の場合は `defined('WP_DEBUG') && WP_DEBUG` を採用する。

`theme()` は `pluginFile = null`、`context = KernelContext::Theme`、`actions` は引数値(既定 true)で構築する。`plugin()` は `context = KernelContext::Plugin`、`actions = false`(プラグイン形態は ActionRouter を持たない。§3.7)で構築する。

### エッジケース

- `appDir` がシンボリックリンク: realpath により実体パスへ解決される。`instanceId()` と `discoveryFile()` も実体パス基準となる。
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
    /** @var array<string, self> 正規化済みappNamespace => Kernel のグローバルレジストリ */
    private static array $kernels = [];

    /** @var bool WP-CLIコマンド登録済みフラグ(プロセス内で1回のみ登録) */
    private static bool $cliRegistered = false;

    private readonly KernelConfig $config;
    private readonly Container $container;

    /** @var list<DiscoveredComponent> 発見済みコンポーネント(FQCN昇順) */
    private array $components;

    /** @var list<DiscoveredHookMethod> 発見済みフックメソッド((class, method, hook, priority) 昇順) */
    private array $hookMethods;

    private function __construct(KernelConfig $config);

    /**
     * FWを起動する。テーマはfunctions.php直下、プラグインは本体ファイル直下で呼ぶこと。
     *
     * @throws \TMSWP\Core\Exception\KernelException 多重boot・名前空間/ディレクトリ衝突・遅延boot(debug時)
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

    /** @return array<string, self> 正規化済みappNamespace => Kernel */
    public static function all(): array;

    /**
     * 全Kernelレジストリを破棄する。テスト専用。
     * @internal
     */
    public static function reset(): void;

    public function config(): KernelConfig;

    public function container(): Container;

    /** @return list<DiscoveredComponent> FQCN昇順 */
    public function components(): array;
}
```

### boot() の完全仕様

擬似コード。番号は実装順序として正準。

```
boot(config):
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
     以下のコアサービスを登録:
        container->set(Kernel::class, kernel)                       // 即値
        container->set(KernelConfig::class, config)                 // 即値
        container->set(Container::class, container)                 // 即値(自己参照)
        container->alias(\Psr\Container\ContainerInterface::class, Container::class)
        container->set(DiscoveryFile::class, new DiscoveryFile(config))  // 即値
        container->set(Discovery::class, fn(Container c) => new Discovery(
            config, c->get(DiscoveryFile::class),
        ))
  4. result = container->get(Discovery::class)->discover()
     kernel.components = result.components
     kernel.hookMethods = result.hookMethods
     // 本番(debug=false): 探索結果ファイル1つのincludeのみ。Reflectionゼロ・appクラスのロードゼロ
     // 探索結果ファイルが無い・構成と合わない場合は DiscoveryException(§6)
  5. kernel.scheduleAll()      // §7。add_filter/add_actionの登録のみ
  6. kernel.registerLifecycleHooks()  // コンテキスト別運用フック(下記)
  7. kernel.registerActionRouter()    // §3.7。Themeかつconfig.actions=trueのときのみ
  8. self::$kernels[ns] = kernel      // 手順1〜7がすべて成功してから登録(例外時に半端な状態を残さない)
  9. WP-CLI環境(defined('WP_CLI') && WP_CLI)かつ未登録なら
        WP_CLI::add_command('tmswp discover', new DiscoverCommand()) を登録(§3.6)
        self::$cliRegistered = true
  10. return kernel
```

#### 3.1 多重bootの挙動

- **同一 appNamespace への2回目の boot は常に `KernelException`。** 冪等に既存インスタンスを返す仕様にはしない。理由: 同一 functions.php の二重読込や copy&paste 起因の二重bootは構成バグであり、黙って既存を返すと「2回目の config の差分が無視される」というサイレント失敗になる(旧FWの黙殺文化の排除)。
- 異なる appNamespace + 異なる appDir であれば回数無制限に boot できる(テーマ1 + プラグインN の共存)。
- 例外発生時、レジストリには登録されない(手順8のレジストリ登録は手順1〜7がすべて成功してから行われる)。`add_action` 済みのスケジュール(手順5 `scheduleAll`)が残る可能性があるが、手順6 `registerLifecycleHooks`・手順7 `registerActionRouter` はフック登録のみで例外を投げないため、手順5以降で投げる例外は遅延boot(debug)のみ。遅延boot例外は開発時にしか発生しないため許容する。

#### 3.2 複数Kernel共存時の独立性(テーマ+プラグイン併用)

サイト内に「TMSWPで作られたテーマ」+「TMSWPで作られたプラグイン複数」が共存するケースを正式サポートする。独立性の保証は以下の通り。

| 共有されるもの | 共有されないもの |
|---|---|
| `Kernel::$kernels` レジストリ(プロセス内static) | Container(コアサービス含め完全に別インスタンス) |
| `tmswp/core` のコード自体(後述の注意参照) | 探索結果ファイル(Kernel ごとに appDir の親ディレクトリに置く) |
| WP-CLI `tmswp discover`(全Kernel横断で1回登録) | Discovery・components / hookMethods リスト |
| Log層(グローバル。Log文書参照) | 有効化・無効化フック |

- **appNamespace の一意性は boot 時に強制される。** スターター既定の `App` のまま複数のテーマ・プラグインを併用すると2つ目の boot が `KernelException` で停止する。これはPHPレベルでも必然である: 同名 FQCN `App\Foo` が複数の vendor/autoload に登録されると先勝ちで誤ったファイルがロードされるため、FWで救済できない。**rules/ に「配布・併用前提のプラグインは appNamespace を固有名(例: `MyShopPlugin`)へ変更すること」を必須規約として記載する。**
- 同一 (フック, priority) に複数Kernelのグループランナーが並ぶ場合、実行順は `add_action` の登録順=boot順=WPのロード順(プラグイン → テーマ)となる。**Kernel間でコンポーネントの初期化順に依存してはならない**(規約)。あるKernelのapp/クラスが別Kernelのapp/クラスを参照する構成は禁止し、連携が必要な場合はWPフック(do_action/apply_filters)経由とする。
- 注意: 各テーマ・プラグインが自前の `vendor/` に `tmswp/core` を抱えるため、**最初にロードされた `tmswp/core` のコードが全Kernelで共有される**(PHPのクラスは再定義できない)。互換性はSemVer(DR-11)で担保し、メジャーバージョンの異なる core を同一サイトで併用することは非サポートと明記する(`Kernel::boot` 内で `Kernel::VERSION` 定数とパッケージバージョンの照合は行わない。クラスが共有される以上検出不能であり、運用ルールとする)。

#### 3.3 遅延boot(対象フック逸失)時の挙動

`scheduleAll()` は各 (フック名, priority) グループの登録前に `did_action($hook) > 0` を検査する。真の場合(=boot が遅すぎてそのフックが既に発火済み):

- `config.debug === true`: `KernelException("Kernel::boot() was called after the '{hook}' hook had fired. Boot from the top level of functions.php or the plugin main file.")` を投げる。
- `config.debug === false`(本番): `Log::error()` で同内容を記録した上で、**当該グループのランナーを即時実行する(キャッチアップ)**。サイト全体の機能停止より縮退動作を優先する。キャッチアップは発火済みフックのグループのみで、未発火フックのグループは通常通り `add_action` される。

これは「即時同期初期化の禁止」(DR-04)の例外ではなく、誤用に対する縮退仕様である。正常な boot 位置(functions.php / プラグイン本体の直下)ではキャッチアップは決して発生しない。

#### 3.4 registerLifecycleHooks() — テーマとプラグインの差異

| 項目 | Theme | Plugin |
|---|---|---|
| ブート用最速フック | `after_setup_theme` | `plugins_loaded` |
| 有効化契機 | `after_switch_theme` アクション | `register_activation_hook(pluginFile, ...)` |
| 無効化契機 | `switch_theme` アクション(切替元テーマ側で発火) | `register_deactivation_hook(pluginFile, ...)` |
| 有効化時処理 | rewrite flushフラグ設定 + Migrator実行通知 | rewrite flushフラグ設定 + Migrator実行通知 |
| 無効化時処理 | `tmswp/deactivated` の発火 | `flush_rewrite_rules()` 即時実行 + `tmswp/deactivated` の発火 |

具体仕様:

1. **Plugin コンテキスト**: `register_activation_hook($config->pluginFile, $callback)` を登録する。callback は (a) オプション `tmswp_flush_rewrite_{instanceId}` に `1` を保存 (b) `do_action('tmswp/activated', $kernel)` を発火する。(b) は Model文書の Migrator が購読してスキーマ適用を行うための契約フックである(Kernel自身はマイグレーションの中身を知らない)。`register_deactivation_hook` の callback は `flush_rewrite_rules()` と `do_action('tmswp/deactivated', $kernel)` を行う。
2. **Theme コンテキスト**: `add_action('after_switch_theme', ...)` で (a) flushフラグ保存 (b) `do_action('tmswp/activated', $kernel)`。`add_action('switch_theme', ...)`(切替「元」テーマの最終リクエストで発火)で `do_action('tmswp/deactivated', $kernel)` を行う。
3. **rewrite flush の遅延実行**: 有効化フック時点ではCPT/Taxが未登録のため、その場で `flush_rewrite_rules()` を呼んでも新ルールが反映されない。そこで両コンテキスト共通で `add_action('init', $callback, 99)` を登録し、callback はフラグオプションが存在する場合のみ `flush_rewrite_rules()` 実行 + フラグ削除を行う(CPT登録は init 10 までに完了しているため99で安全)。フラグオプションは `autoload = false` で保存する。
4. **mu-plugin**: `KernelConfig::plugin()` で動作するが、mu-plugin には有効化フックが発火しない。この場合マイグレーションは (a) WP-CLI `wp tmswp migrate`(Model文書) (b) Migrator の admin_init 版数比較、で代替される。rules/ にこの注意を記載する。

#### 3.5 get() / forClass() / all() / reset()

- `get($appNamespace)`: 入力を `trim($appNamespace, '\\')` で正規化して完全一致検索。不在なら `KernelException("no TMSWP kernel booted for namespace '{ns}'")`。
- `forClass($class)`: レジストリ内の各 ns について `str_starts_with($class, $ns . '\\')` を判定し、一致した中で最長の ns を持つ Kernel を返す(boot時に入れ子名前空間を禁止しているため一致は高々1つだが、アルゴリズムとしては最長一致で定義する)。一致なしは `KernelException("class '{fqcn}' does not belong to any booted TMSWP application")`。null や空文字を返すサイレント失敗は禁止。
- `all()`: レジストリのコピーを返す(内部配列への参照を漏らさない)。キーは正規化済みappNamespace。
- `reset()`: `self::$kernels = []; self::$cliRegistered = false;` のみ行う。登録済み `add_action` の解除は行わない(Brain Monkey 環境ではフック自体がモックであり、統合テストでは1テスト1プロセスで運用する)。`@internal` を付与し、PHPStan ルールで本番コードからの呼び出しを禁止する(テスト名前空間からのみ許可)。

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

#### 3.7 ActionRouter の生成・登録(テーマ形態のテンプレート前処理)

boot 手順7。**Theme コンテキストかつ `config.actions === true`(既定)のときのみ** `TMSWP\Core\View\ActionRouter` を生成し、`template_include`(priority 100)へ `dispatch` を登録する。

```
registerActionRouter():
  if config.context !== KernelContext::Theme: return     // プラグイン形態では生成しない(下記)
  if config.actions !== true: return                      // KernelConfig::theme(actions: false) で無効化
  router = new \TMSWP\Core\View\ActionRouter()            // ステートレス。Discoveryには関与しない
  add_filter('template_include', [router, 'dispatch'], 100, 1)
```

- **ActionRouter は Discovery の発見対象ではない。** `ComponentAttributeInterface` を実装する属性を持たず、`app/` の走査・探索結果ファイル・初期化タイミング表のいずれにも現れない。テーマルート直下の `actions/` ディレクトリに置かれた**ファイルの存在**だけで動作する(完全オプトイン: `actions/` を1つも置かないテーマも全機能が動く)。boot が生成・登録するのは `dispatch` フィルタ1本のみで、この登録自体は `actions/` ディレクトリの有無に依存しない(ディレクトリ探索は dispatch 発火時に行う)。
- **プラグイン形態では ActionRouter を生成しない。** プラグインはトップレベルテンプレートを供給する機構を持たないため `template_include` への介入が意味を持たない。プラグイン側のテンプレート前処理に相当するロジックは `#[AsHook]` クラスの `#[Action]`/`#[Filter]` メソッド(§5)へ書く。
- `actions/` ディレクトリ名は **`'actions'` 固定**(テーマルート直下。リネーム不可)。配置決定木・静的検査・rules/ が無設定で同一前提を共有するための決定的規約である。
- dispatch の正準手順(子→親テーマ解決、static クロージャ内 include、戻り値契約 `array`→View変数バッグ / `ActionRouter::notFound()`→404強制 / `null`→no-op、`$template` の無加工返却原則)・`actions` ファイル規約・`View` 変数バッグ・グローバルヘルパ `tms_var()` は View/Support/Log 文書が定義する。本文書が確定するのは「いつ・どの形態で生成し、どのフックへ登録するか」のみである。

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
     * @param list<DiscoveredHookMethod> $hookMethods (class, method, hook, priority) 複合キー昇順
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
     * 属性クラスが存在しない/ComponentAttributeInterface非実装の場合は DiscoveryException
     * (探索結果ファイルが古いシナリオ。例外化/ログの分岐は呼出側=Kernelが行うため、本メソッドは常に投げる)。
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
     */
    public function __construct(
        public string $class,
        public string $method,
        public string $hook,
        public int $priority,
        public int $acceptedArgs,
    ) {
    }
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
          現れず、フック発火まで決してロードされない(遅延ロードの構造的保証)
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
        - hookMethods[] = new DiscoveredHookMethod(fqcn, メソッド名, hook名,
          priority, acceptedArgs)
     g. #[AsHook] クラスに #[Action]/#[Filter] メソッドも register() オーバーライドも
        1つも無い場合: 探索失敗("hook class {fqcn} registers no hooks")
        (何も登録しないHookクラスは構成ミス。黙殺しない)
  4. components を (class, attributeClass) の複合キー昇順でソート(どちらも strcmp)
  5. hookMethods を (class, method, hook, priority) の複合キー昇順でソート
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

Discovery が「何を拾うか」、Kernel が「いつ・どう初期化するか」を属性自身に宣言させる契約。コア6属性(`AsPostType` / `AsTaxonomy` / `AsUserRole` / `AsModel` / `AsHook` / `AsOptionGroup`)に加え、拡張パッケージ(例: tmswp/settings-page)が独自属性を追加する際もこの契約を実装するだけでよく、Kernel側の改修や登録テーブルを必要としない。なおテーマ形態のテンプレート前処理(`actions/{テンプレート相対パス}.php` 規約)は属性ベースではなく `ActionRouter` がファイル存在で駆動するため、本契約・Discovery とは無関係である(§3.7)。

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
- `#[Action]` と `#[Filter]` に機構上の差はない(WPの `add_action()` は `add_filter()` の別名であり、登録はどちらも `add_filter()` で行う。§7)。意味の区別を宣言として残すために2クラスへ分ける。`#[Filter]` メソッドは必ず値を返すこと(rules/ に記載)。
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
- `HookInitializer::initialize()` は Kernel の Container から `$component->class` を解決する。解決結果が `Hook` のインスタンスでなければ `DiscoveryException`(`hook component must extend Hook: {fqcn}`)を投げる(探索結果ファイルが古い場合の防御。走査では継承を検査済み)。`Hook` のインスタンスであれば `register()` を1回だけ呼ぶ。
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
    public const SCHEMA_VERSION = 3;

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
    'schema' => 3,
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
        ],
        [
            'class' => 'TmswpFixturePlugin\\Hook\\PluginProbeHook',
            'method' => 'handle',
            'hook' => 'tmswp_fixture_plugin/action',
            'priority' => 10,
            'acceptedArgs' => 0,
        ],
    ],
];
```

- `components` の各要素は `class`・`attributeClass`・`attributeArguments` を持つ((class, attributeClass) 昇順)。`hookMethods` の各要素は `class`・`method`・`hook`・`priority`・`acceptedArgs` を持つ((class, method, hook, priority) 昇順。§4)。
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
| `#[AsOptionGroup]` | `init` | `init` | 5 | OptionSchema の register_setting 等(Option文書) |
| `#[AsTaxonomy]` | `init` | `init` | 9 | register_taxonomy + register_term_meta(Tax/Meta文書) |
| `#[AsPostType]` | `init` | `init` | 10 | register_post_type + register_post_meta(Post/Meta文書) |
| `#[AsUserRole]` | `init` | `init` | 10 | ロール定義の登録。DB書込(add_role)はUserRole層が差分検出して冪等に行う(User文書) |

メソッドレベル `#[Action]` / `#[Filter]`(Discovery が hookMethods として収集。§4・§5)と ActionRouter(テンプレート前処理。Discovery 非関与)はこの属性スケジューリング表の外にあり、boot 時に直接フック登録される(下表)。

| 機構 | フック | priority | 形態・発火条件 | 内容(概要。詳細は各文書) |
|---|---|---|---|---|
| `#[Action]` / `#[Filter]` メソッド | 属性が宣言した各フック | 属性宣言値(既定 10) | 両形態。boot手順5(scheduleAll)で登録、宣言フック発火時に実行 | `add_filter($hook, クロージャ, $priority, $acceptedArgs)` を直接登録。クロージャは発火時に `container->get($class)` してメソッドを呼ぶため、**Hookクラスはフック発火まで未ロード(遅延)**。acceptedArgs はメソッド宣言引数数から自動導出(§4・§5) |
| `ActionRouter` | `template_include` | 100 | テーマ形態のみ・フロント本描画リクエストのみ(REST/admin-ajax/管理画面/WP-CLIでは未発火) | 確定済み `$template` に対応する `actions/{テンプレート相対パス}.php` を子→親テーマの順に探索し、存在すれば static クロージャで include(可視変数=$wp_queryのみ)。戻り値契約 `array`→View変数バッグ / `ActionRouter::notFound()`→404強制 / `null`→no-op。`$template` は常に無加工で返す(404強制時のみ `get_404_template()` 差し替え)。Discovery非関与・ファイル存在で完全オプトイン(§3.7。実行機構は View/Support/Log 文書) |

補足仕様:

- **Taxonomy(9) を PostType(10) より先に登録する(確定判断)。** 根拠: `register_post_type()` の `taxonomies` 引数は内部で `register_taxonomy_for_object_type()` を呼ぶが、これは**未登録のタクソノミー名に対して false を返して黙って失敗する**。逆方向(`register_taxonomy()` の `$object_type` に未登録のCPT名を渡す)は文字列として保持され後から解決されるため問題ない。したがって「Taxonomy先・PostType後」が唯一安全な順序である。WPコアの `create_initial_post_types` / `create_initial_taxonomies` は init 0 で動くため、5/9/10 はいずれもコア登録より後になる。
- `#[AsModel]` のスキーマ適用(dbDelta)契機は (a) `tmswp/activated` アクション(§3.4) (b) `admin_init` での版数比較 (c) WP-CLI、であり Model 文書の Migrator が定義する。本表の plugins_loaded(1) は「`InquiryModel::query()` が呼べるようにレジストリへ載せる」だけの軽量処理である。
- `#[MetaSchema]` / `#[OptionSchema]` / `#[UseMetaStorage]` は独立コンポーネントではなく、親コンポーネント(PostType等)の Initializer が初期化の一部として処理する。Discovery の発見対象にはならない(`ComponentAttributeInterface` を実装しない)。
- `ActionRouter`(テンプレート前処理)は属性スケジューリング(`scheduleAll()`)の対象ではなく、boot 手順7(§3.7)で `add_filter('template_include', [$router, 'dispatch'], 100, 1)` として直接登録される。`template_include` はフィルタ(`$template` を受け取り返す)であり、しかもフロントの本描画リクエストでのみ発火する(REST/admin-ajax/管理画面/WP-CLI では発火しない)。`dispatch` は `$template` を無加工で返す(404強制時のみ WP標準の `get_404_template()` 相当へ差し替える)。`actions/` ファイルの探索・include はこのフィルタ発火時(=本描画直前)に行われ、即時同期初期化の禁止に整合する。dispatch の正準手順は View/Support/Log 文書が定義する。
- AsHook(0。`register()` 例外手段)と AsModel(1) を別priorityに分離しているのは依存関係のためではなく、決定的順序を priority レベルで固定するためである。Hookクラスのコンストラクタでモデルのインスタンスが必要な場合も、モデルは静的ファサード(`InquiryModel::query()`)であり実際のレジストリ参照はクエリ実行時まで遅延するため問題にならない。

### scheduleAll() のアルゴリズム

```
scheduleAll():
  1. for hm in this.hookMethods ((class, method, hook, priority) 昇順で格納済み):
        add_filter(hm.hook, function (mixed ...$args) use (hm): mixed {
            return container->get(hm.class)->{hm.method}(...$args);
        }, hm.priority, hm.acceptedArgs)
     // #[Action] も add_filter で登録する(add_action は add_filter の別名。§5)。
     // クロージャが捕捉するのは DiscoveredHookMethod(FQCN文字列とメソッド名)のみで、
     // Hookクラスはフック発火時の container->get() まで未ロード(遅延)
  2. groups = []   // "{hook}\n{priority}" => list<array{component, attribute}>
     for c in this.components (FQCN昇順で格納済み):
        try:
            attr = c.attribute()
        catch DiscoveryException e:        // 探索結果ファイルの陳腐化(属性クラス消滅等)
            debug ? throw e : (Log::error; continue)
        key = attr->hookName(config.context) . "\n" . attr->hookPriority()
        groups[key][] = [c, attr]
  3. for (hook, priority, group) in groups:
        runner = function () use (group): void {
            foreach (group as [c, attr]) { this.runInitializer(c, attr); }
        }
        if did_action(hook) > 0:
            debug ? throw KernelException(遅延boot。§3.3)
                  : (Log::error; runner())          // キャッチアップ
        else:
            add_action(hook, runner, priority, 0)   // グループごとに1クロージャ

runInitializer(c, attr):
  initializer = container->get(attr->initializerClass())
  if !(initializer instanceof ComponentInitializerInterface):
      debug ? throw DiscoveryException : (Log::error; return)
  try:
      initializer->initialize(c, this)
  catch \Throwable e:
      debug ? throw e
            : Log::error('tmswp.kernel: failed to initialize {class}', [
                  'class' => c.class, 'exception' => e,
              ])   // 本番は当該コンポーネントのみスキップし残りを継続
```

補足(`#[Action]`/`#[Filter]` 登録の確定仕様):

- 遅延boot検査(`did_action`)とキャッチアップ(§3.3)は手順3のコンポーネント初期化グループのみに適用する。`#[Action]`/`#[Filter]` はハンドラ登録であり「過去の発火に対するキャッチアップ」が定義できないため検査対象外とする(遅延boot自体の検出・可視化は§3.3が担う)。
- クロージャ登録のため、第三者コードからの `remove_action` / `remove_filter` による個別解除は事実上できない。登録クロージャを保持して解除APIを提供することは実需要が出るまで行わない(YAGNI。受託で第三者がHookを外す要件は稀)。

**正確に1回の初期化(Kernel契約)**: Kernel は Discovery が収集した components リストを1巡だけ走査し、各コンポーネントの Initializer(エンティティ基底の `register()` を含む)を**正確に1回**呼ぶ。冪等性の保証はこの1巡走査のみであり、エンティティ基底の `register()` 側は再入ガード・重複防止を持たない(エンティティ文書)。

順序保証のまとめ:

- 異なるフック間: WPのフック発火順。
- 同一フック・異なるpriority間: priority昇順(WP仕様)。
- 同一(フック, priority)・同一Kernel内のコンポーネント初期化: **components の FQCN 昇順**(グループランナーが1クロージャで順次実行するため、他プラグインの add_action に割り込まれない)。
- 同一(フック, priority)内の `#[Action]`/`#[Filter]` メソッド間: hookMethods の (class, method, hook, priority) 昇順=登録順。コンポーネント初期化グループとの相対順は「hookMethods 先・グループランナー後」(scheduleAll の手順順)で決定的だが、この相対順に依存してはならない(規約)。
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
     *
     * @throws \TMSWP\Core\Exception\ContainerException 解決済みidへの再登録
     */
    public function set(string $id, object|string $concrete): void;

    /**
     * 別名登録(単段のみ)。get($alias) は get($id) と等価になる。
     *
     * @throws \TMSWP\Core\Exception\ContainerException 自己参照・$idが別のaliasである(連鎖の禁止)・解決済みidへの再登録
     */
    public function alias(string $alias, string $id): void;

    /**
     * PSR-11。未登録のclass-stringはautowireで解決し共有する。
     *
     * @throws \TMSWP\Core\Exception\NotFoundException 解決不能なid
     * @throws \TMSWP\Core\Exception\CircularDependencyException 循環依存
     * @throws \TMSWP\Core\Exception\ContainerException 解決不能なコンストラクタ引数等
     */
    public function get(string $id): mixed;

    /** PSR-11。登録済み・alias・解決済み・autowire可能(class_exists かつ instantiable)で true */
    public function has(string $id): bool;
}
```

### 振る舞い仕様

**登録(set / alias)**

- 同一idへの再登録は、未解決(まだ get されていない)であれば後勝ちで置換できる(テストでの差し替え用途)。**解決済み(`$instances` に存在する)idへの再登録は `ContainerException`**(「設定したのに反映されない」サイレント事故の禁止)。
- `alias()` は**単段のみ**。`$alias === $id`(自己参照)、または `$id` 自体が登録済みのalias(連鎖 A→B→C の試み)は `ContainerException`。これにより alias 解決は配列参照1回で完結し、循環aliasは構造的に発生しない。

**解決(get)** — 擬似コード:

```
get(id):
  id = aliases[id] ?? id                                 // 単段解決
  if instances has id: return instances[id]
  if id in resolving:
      throw CircularDependencyException(
          'circular dependency: ' . implode(' -> ', resolving) . ' -> ' . id)
  resolving[] = id
  try:
      if definitions has id:
          d = definitions[id]
          value = d instanceof \Closure ? d(this)
                : is_string(d)          ? this.instantiate(d)       // クラス名束縛
                : d                                                  // 即値(登録時に即instancesへ入れてもよい)
      elseif class_exists(id):
          value = this.instantiate(id)                               // autowire
      else:
          throw NotFoundException("no entry or class found for '{id}'")
      instances[id] = value
      return value
  finally:
      array_pop(resolving)
```

**autowire(instantiate)** — 擬似コード:

```
instantiate(class):
  ref = new ReflectionClass(class)              // 不存在は呼出側でNotFoundException済み
  if !ref->isInstantiable(): throw ContainerException("class '{class}' is not instantiable")
  ctor = ref->getConstructor()
  if ctor === null: return new class()
  args = []
  for param in ctor->getParameters():
      if type が ReflectionNamedType かつ非builtin:
          args[] = this.get(type->getName())     // 再帰解決。循環はスタックで検出
      elif param->isDefaultValueAvailable():     args[] = デフォルト値
      elif type !== null && type->allowsNull():  args[] = null
      else: throw ContainerException(
          "unresolvable parameter \${name} of {class}::__construct(); " .
          "register the entry explicitly or add a default value")
  return ref->newInstanceArgs(args)
```

- Union型・Intersection型のパラメータはautowire不能とし、デフォルト値があればそれを使い、なければ `ContainerException`(推測による解決はしない)。
- 可変長パラメータ(`...$args`)は空として扱う。
- `has()` が true を返すのは: 正規id(alias解決後)が `instances` / `definitions` のいずれかに存在する、または `class_exists($id) && (new ReflectionClass($id))->isInstantiable()` の場合。

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

本文書で定義する例外。すべて `packages/core/src/Exception/` に配置する。

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Exception;

/** Kernel構成・boot手順の誤りを表す。FW利用者(開発者)が修正すべきエラー */
class KernelException extends \RuntimeException
{
}

/** app/走査・探索結果ファイルの読み書きの失敗を表す */
class DiscoveryException extends KernelException
{
}

/** コンテナの登録・解決エラー(PSR-11準拠) */
class ContainerException extends \RuntimeException implements \Psr\Container\ContainerExceptionInterface
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

- これらは `final` にしない(数少ない例外)。FW全体の例外基底クラスを01文書が別途定義する場合、`\RuntimeException` の部分をその基底へ差し替える(本文書側の調整事項として open question に記載)。
- 例外メッセージは英語で固定し、必ず「何が・どこで(パス/FQCN)・どう直すか」を含める。

---

# 利用例(app側コード)

### テーマの functions.php

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

\TMSWP\Core\Kernel\Kernel::boot(
    \TMSWP\Core\Kernel\KernelConfig::theme(
        appDir: __DIR__ . '/app',
        appNamespace: 'App',
    ),
);
```

テーマの composer.json(スターターが生成):

```json
{
    "require": { "tmswp/core": "^1.0" },
    "autoload": { "psr-4": { "App\\": "app/" } }
}
```

### プラグイン本体(配布・併用前提なので名前空間を固有化)

```php
<?php
/**
 * Plugin Name: My Shop
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

\TMSWP\Core\Kernel\Kernel::boot(
    \TMSWP\Core\Kernel\KernelConfig::plugin(
        file: __FILE__,
        appDir: __DIR__ . '/app',
        appNamespace: 'MyShopPlugin',
    ),
);
```

### app/ にクラスを置くだけで発見される

```php
<?php
// app/Post/ProductPost.php — boot側の変更は一切不要

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
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
        return PostTypeDefinition::create('product')->label('製品', '製品')->hasArchive();
    }
}
```

### Hookはメソッド属性で宣言する(クラスは発火まで未ロード)

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

`register()` をオーバーライドしないため、このクラスは boot 時には一切ロードされず、宣言した各フックの発火時に初めて Container 経由でインスタンス化される(§4・§7)。

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
| 依存先が auto_init=false / 走査対象外だと初期化を黙ってスキップ。`ReflectionException` も `continue` で黙殺 | 探索失敗は常に例外、初期化失敗は debug=例外 / 本番=`Log::error`+縮退で必ず可視化。黙殺経路ゼロ(§4・§7) |
| `bootstrap()` 呼出位置で `initialize()` が即時同期実行され、フック登録が早すぎ/遅すぎる事故 | boot は add_action のみ。全初期化をWPフックに整合させ、正準タイミング表で固定。遅延bootは検出して例外/ログ+キャッチアップ(§3.3・§7) |
| `AbstractSingleton` の `final private __construct` + `static::$instances` でDI・モック・テスト不能。`func_get_args` 転送はデッドコード | PSR-11コンテナ+コンストラクタ注入。ファサードは無状態の薄い皮で、`Kernel::reset()` によりテスト隔離可能(§8) |
| 起動規約が未文書化(リポジトリ内に bootstrap 呼び出し例ゼロ)で、利用側は暗黙知のコピペ | `Kernel::boot()` + `KernelConfig` ファクトリを正準ブートAPIとして文書化し、スターターに同梱(DR-13) |
| Loaderコンストラクタが `load_textdomain` / `untrailingslashit` 等のWP関数へ密結合し単体テスト不能 | Kernel/Discovery/Container はWP関数依存を `add_action` / `did_action` / 定数参照の最小限に限定。KernelConfig/Container/DiscoveryFile はWP関数ゼロでユニットテスト可能(§2・§6・§8) |

---

# 受け入れ基準

実装は以下をすべて満たすこと。検証は `composer qa`(= lint + analyse + test)および wp-env 統合テストで行う。

## 機能(ユニットテストで検証)

- [ ] `KernelConfig::theme()/plugin()` が不正入力(不在ディレクトリ・不正名前空間・`TMSWP` 予約・プラグインファイル不在)で `KernelException` を投げ、メッセージに対象パス/名前空間を含む
- [ ] 同一 appNamespace への2回目の `Kernel::boot()` が `KernelException` になる(多重boot禁止)
- [ ] 入れ子の appNamespace(`App` と `App\Sub`)・入れ子/同一の appDir・探索結果ファイルが同じになる兄弟の appDir の boot が `KernelException` になり、先に boot した Kernel の探索結果ファイルは上書きされない
- [ ] `Kernel::forClass()` が最長一致でKernelを返し、未該当FQCNで `KernelException` を投げる(null返しのサイレント失敗がない)
- [ ] Container: `set`(Closure/object/string束縛)・単段 `alias`・autowire が共有(シングルトン)スコープで動作し、公開APIが `set` / `alias` / `get` / `has` のみである(`factory()` / `make()` / 多段aliasが存在しない)
- [ ] Container: 間接循環依存(A→B→A、3クラス以上の循環含む)が**無限再帰せず** `CircularDependencyException` となり、メッセージに解決チェーン全体(`A -> B -> A`)を含む
- [ ] Container: 未解決idの `get()` が `NotFoundException`、解決不能なコンストラクタ引数が `ContainerException`、解決済みidへの再登録・自己参照alias・alias済みidを指す `alias()`(連鎖の試み)が `ContainerException` になる
- [ ] Discovery: 同一のapp/ファイル群に対し、ファイル作成順・列挙順を入れ替えても(テストでイテレータ順をシャッフルしても)`DiscoveryResult` が完全一致する(決定的ロード順=components の (class, attributeClass) の strcmp ソート+hookMethods の (class, method, hook, priority) ソート。数字だけのフック名も strcmp の順になる)
- [ ] Discovery: クラス名とファイル名/名前空間の不一致が debug に関係なく `DiscoveryException`(ファイルパス・期待FQCN入り)になる。黙殺・スキップされる分岐が存在しない
- [ ] Discovery: 走査中のオートロードで起きた Error(ParseError・未定義の親クラスを含む)と、属性の読み取りで起きた Error(属性クラスの ParseError・属性引数の未定義のクラス定数)が、元の例外を previous に持ち、クラス名とファイルを含む `DiscoveryException` になる
- [ ] Discovery: 属性引数(配列のキーと値。入れ子を含む)とフック名に、Kernel のルート・ABSPATH・WP_CONTENT_DIR が区切り文字の境界で現れると探索失敗になる(ルート /srv/a に対し /srv/a・/srv/a/x.php・file:///srv/a/x は失敗、/srv/abc・/srv/a-b は通る)。ABSPATH・WP_CONTENT_DIR は設定された値の表記と実パスのどちらで現れても失敗になり(symlink のときは両方、末尾に区切り文字を付けた定義も同じ)、ディレクトリが存在しないときは環境パスを含まない app の探索が成功する。同じソースを絶対パスの違う2つのルートに置いて生成した探索結果ファイルが、バイト単位で一致する
- [ ] Discovery: Hook 基底を継承しない `#[AsHook]` クラス(`register()` という名前のメソッドを持つ場合を含む)が探索失敗になる
- [ ] Discovery: 探索失敗のときに途中まで収集した登録が残らない(boot が例外になり、先に走査したクラスのフックも Kernel のレジストリも登録されない)
- [ ] Discovery: 属性なしクラス・interface・trait・小文字始まりファイル(`helpers.php`)はエラーなくスキップされ、abstractクラスへのコンポーネント属性付与は探索失敗として扱われ、backed enum への付与(`#[AsOptionGroup]`)は正常に収集される
- [ ] Discovery: `#[AsHook]` クラスの `#[Action]`/`#[Filter]` メソッドが (class, method, hook, priority, acceptedArgs=宣言引数数) の `DiscoveredHookMethod` として収集され、`register()` をオーバーライドしない `#[AsHook]` クラスは components に現れない
- [ ] Discovery: `#[Action]`/`#[Filter]` の誤用(`#[AsHook]` なしクラスへの付与・static/非public/可変長引数メソッドへの付与・フックを1つも宣言しない `#[AsHook]` クラス)が探索失敗として扱われる
- [ ] Discovery: 属性引数にオブジェクト(new式)を渡した場合に探索失敗となる(探索結果ファイルに書き出せる値に限る)
- [ ] DiscoveryFile: write→load の往復で `DiscoveryResult` が完全復元される(enum引数・hookMethods 含む)。ファイルが無い・`include` が throw する/配列以外を返す・schema不一致・appNamespace不一致・appDir不一致・破損エントリで、ファイルパスと `wp tmswp discover` の案内を含む `DiscoveryException` を投げる
- [ ] DiscoveryFile: 同じ探索結果からは、絶対パスの違う場所でもバイト単位で同一の内容を生成し、内容に絶対パス・mtime・生成時刻を含まない。書き込みの失敗は `DiscoveryException` になる
- [ ] Kernel: debug=false の boot が探索結果ファイルだけを読み、app/ を走査しない。ファイルが無ければ boot が `DiscoveryException`(ファイルパス入り)になる。debug=true の boot は毎回走査し、探索結果ファイルを読まない
- [ ] `wp tmswp discover` が全 Kernel の探索結果ファイルを書き、走査の失敗ではどのファイルも書かずに非0で終わる。`--check` はファイルが無い・内容が違う Kernel を appNamespace とファイルパスで列挙して非0で終わり、すべて最新なら成功する。起動中の Kernel が無い場合は非0で終わる
- [ ] `Kernel::reset()` 後に同一名前空間で再bootできる(テスト隔離)

## 統合(wp-env + WordPress のテストライブラリ(WP_TESTS_DIR)で検証)

- [ ] 本番相当(debug=false)で、探索結果ファイルが無いと boot が例外になる。ファイルがあれば走査せずに起動する(app/ を走査すれば見つかるクラスのフックが登録されず、そのクラスもロードされず、ファイルにだけ書いたフックが登録される)
- [ ] `boot()` 直後(フック発火前)の時点で `register_post_type` / `register_taxonomy` / `add_role` / DB書込が一切発生していない(即時同期初期化の禁止)
- [ ] init 完走後、`#[AsTaxonomy]`(init 9)→`#[AsPostType]`(init 10)の順で登録され、`PostTypeDefinition` の taxonomies 連携が機能する
- [ ] `#[Action]`/`#[Filter]` メソッドが宣言どおりの(フック, priority, acceptedArgs=宣言引数数)で `add_filter` 登録され、フック発火時に Container 経由で1回だけインスタンス化されて呼ばれる。属性宣言のみ(`register()` 非オーバーライド)の `#[AsHook]` クラスは宣言フックの発火までロードされない(`get_declared_classes()` の差分で検証)
- [ ] Kernel が各コンポーネントの Initializer(エンティティ基底の `register()` を含む)を1リクエスト内で正確に1回だけ呼ぶ(Initializerへのスパイで検証。register() 側に再入ガードは存在しない)
- [ ] テーマ(`App`)+プラグイン(`MyShopPlugin`)の2 Kernel併用で、Container・探索結果ファイル・components が相互に独立し、`Kernel::all()` が2件を返す
- [ ] プラグイン有効化で 次リクエスト init 99 での `flush_rewrite_rules()` が1回だけ実行され、フラグが消える。テーマ切替(`after_switch_theme`)でも同様
- [ ] `did_action` 済みフックに対する遅延bootが、WP_DEBUG時に `KernelException`、本番相当でログ+キャッチアップ実行になる
- [ ] テーマ形態の boot で `template_include`(priority 100)へ `ActionRouter::dispatch` が1本登録される。`KernelConfig::theme(actions: false)` では登録されない。プラグイン形態(`KernelConfig::plugin()`)では `actions` が false に正規化され ActionRouter は生成されない
- [ ] コミット済みの fixture のテーマ・プラグインの探索結果ファイルが、走査結果と一致する(統合テストと `composer discover:check`。10-quality §5)

## 品質

- [ ] `composer analyse`: PHPStan level max・baselineなしで通過(szepeviktor/phpstan-wordpress)
- [ ] `composer lint`: PSR-12 + `declare(strict_types=1)` 全ファイル適用
- [ ] Kernel/Discovery/Container/DiscoveryFile/KernelConfig に `__callStatic` / `extract()` / グローバル可変状態(`Kernel::$kernels` と `$cliRegistered` を除く)が存在しない
- [ ] KernelConfig・Container・DiscoveryFile・DiscoveryResult・DiscoveredComponent のユニットテストがWP関数のスタブなし(または Brain Monkey の最小スタブのみ)で動作する

## 検証コマンド

```bash
composer lint     # php-cs-fixer (PSR-12 + strict_types)
composer analyse  # PHPStan level max
composer test     # PHPUnit 11 ユニット層(Brain Monkey)
composer qa       # 上記一括
# 統合層
composer wp:start && composer test:integration   # 正式コマンド名はQA文書に従う
composer discover:check                            # fixture の探索結果ファイルの照合(wp-env)
```
