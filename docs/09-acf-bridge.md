# 09. tmswp/acf-bridge — ACF統合拡張 完全仕様

## 目的

Advanced Custom Fields (ACF / ACF Pro) と TMSWP を統合する唯一の公式拡張パッケージ `tmswp/acf-bridge` の完全仕様を定める。本拡張は以下を提供する。

1. **acf-json を真実源としたコードジェネレータ** — フィールドグループ定義から型付きアクセサクラス(`app/Acf/*Fields.php` 等)を生成し、PHPStan level max で型検査可能な ACF アクセスを実現する(DR-06)。
2. **`AcfMetaStorage`** — コアの`MetaStorageInterface`実装。`#[UseMetaStorage]`によるエンティティ単位の明示オプトインで、`MetaBag`の読み書きの保存先を、ACFのフィールドの保存先(値のメタと参照のメタ)にする。単純な値の型のフィールドだけを扱い、ACFのフィルタは通さない(§8.1、DR-18)。
3. **`register_*_meta`発行スキップ連携** — ACF管理下のメタキーをコアの`MetaSchemaRegistry`に申告する。`#[MetaSchema]`の宣言が無いキーはWordPressに登録しない(`register_*_meta`しない)。`#[UseMetaStorage(AcfMetaStorage::class)]`のエンティティが`#[MetaSchema]`で宣言したキーは、宣言どおり登録する。別のストレージのエンティティが`#[MetaSchema]`で宣言したキーだけを、同一キーの重複として即エラーにする(§7.3)。
4. **ドリフト検知** — `wp tmswp acf check` で acf-json と生成コードの乖離を検出し、CI で強制する。

コア(`tmswp/core`)は ACF を一切知らない。ACF の API・データ形式・挙動に関する知識はすべて本パッケージに閉じる(DR-10)。

## スコープ(対象パッケージ)

- `packages/acf-bridge` (composer: `tmswp/acf-bridge`、名前空間: `TMSWP\AcfBridge\`)

本文書が定義しないもの: コア側 `MetaSchemaRegistry`/`MetaStorageInterface`/`MetaBag` の本体仕様(Meta/Option 定義層文書)、`Kernel`/`KernelConfig`(Kernel 文書)、CI ワークフロー全体(QA 文書)。

## 依存文書

| 文書 | 依存内容 |
|---|---|
| 全体アーキテクチャ/リポジトリ構成 | monorepo レイアウト、拡張パッケージの配布形態(DR-11, DR-15) |
| Kernel・Discovery 設計書 | `Kernel::boot()` / `KernelConfig`(appDir・appNamespace の取得元)、ブートのフック整合規約(DR-04) |
| Meta/Option 定義層設計書 | `MetaStorageInterface`、`MetaSchemaRegistry`、`MetaBag`、`ObjectType`、`#[MetaSchema]`、`#[UseMetaStorage]`。`#[WithMetaRevisions]`(§4.11)は §8.3 の責務分担の参照のみ(コアはプレビュー用の独自フックを発火せず、本拡張もそれに依存しない) |
| QA/CI 設計書 | `composer qa` 系コマンド、GitHub Actions マトリクス(DR-12) |

---

# 1. 前提と全体像

## 1.1 大原則

- **コアは ACF 非依存**: `tmswp/core` のソースに `acf` という文字列は登場しない。ACF を扱うコードは本パッケージと app/ 側コードのみ。
- **acf-json が真実源**: フィールドグループの定義は ACF 標準の Local JSON(`acf-json/` ディレクトリ)で管理する。生成コードは常に acf-json から導出され、手編集しない(`@generated`)。
- **DB は真実源ではない**: ACF 管理画面でフィールドを編集すると ACF が acf-json を自動更新する(ACF 標準挙動)。生成コードと acf-json の同期は `wp tmswp acf check` が保証する。
- **PHP 登録グループは生成対象外**: `acf_add_local_field_group()` で PHP 登録されたグループはジェネレータの対象外(検出時に情報メッセージ I001 を出す)。rules/ では「フィールドグループは必ず acf-json で管理する」を必須規約とする。

## 1.2 対応バージョン

- ACF 6.2+ / ACF Pro 6.2+(WP 7.0以上 / PHP 8.3〜8.5はDR-02に従う)。下限を6.2にするのは、acf-jsonの保存先を制御するフック`acf/json/save_paths`(§2.1の手順4)がACF 6.2で入ったためである。6.2より古いACFでは、プラグインや親テーマのacf-jsonから読み込んだグループの保存先を、読み込んだacf-jsonに向けられない。
- 有効なACFの版(`ACF_VERSION`)が6.2より古いことは設定の誤り(文書00 §5.6)であり、AcfBridgeは、すべてのプラグインを読み込んだ後(`plugins_loaded`。テーマのKernelと本番の遅延bootでは、発火済みの`plugins_loaded`への予約として、bootの最後のKernelのキャッチアップの中)に`AcfBridgeRuntimeException`を投げる。ACFが無効なとき(`ACF_VERSION`が無い)は版を確かめない(§10)。
- ACF 無印では Pro 専用フィールド型(repeater / flexible_content / gallery / clone / options_page)は acf-json に現れないため、生成側の分岐は不要。
- ACF は composer 依存にしない(WP プラグインとして導入される前提)。`composer.json` の `require` は `tmswp/core` と `php: >=8.3` のみ。`suggest` に ACF を記載する。

## 1.3 処理フロー全体図

```
[開発フロー]
  ACF管理画面でフィールド編集 → ACFが acf-json/*.json を自動保存
    → wp tmswp acf sync (= composer acf:sync)
    → app/Acf/ProductFields.php・AcfManifest.php 等を再生成 (@generated)
    → git commit (acf-json と生成コードは常にペアでコミット)

[CI]
  wp tmswp acf check → 再生成して差分があれば exit 1 → ビルド失敗

[実行時]
  Kernel::boot()(KernelConfigのextensionsにnew AcfBridge())
    → AcfBridge::register($kernel): acf/settings/load_jsonにjsonDirsを登録(テーマのstylesheetはACF標準挙動)、
      acf/json/save_pathsで読み込んだacf-jsonへ書き戻す
    → init(prio 6)で生成済みAcfManifest::managedKeys()を読み、登録したKernelのMetaSchemaRegistryへ
      「ACF管理下キー」を申告 → 宣言の無いキーはregister_*_metaしない + 重複キー検出
  テンプレート/app コード
    → ProductFields::of($post)->price() … 型付きアクセサ(get_field 委譲)
    → ProductPost::meta($post) … #[UseMetaStorage(AcfMetaStorage::class)] 時は
      AcfMetaStorage 経由(raw 値)
```

## 1.4 パッケージ構成

```
packages/acf-bridge/
├── composer.json            # tmswp/acf-bridge
├── src/
│   ├── AcfBridge.php            # エントリポイント(ExtensionInterface。Kernelごとに登録)
│   ├── BridgeConfig.php
│   ├── AcfFieldAccess.php       # trait: 生成クラスの読み書き基盤
│   ├── AcfTypes.php             # @phpstan-type 定義専用クラス
│   ├── AcfMetaStorage.php       # MetaStorageInterface 実装
│   ├── PreviewAcfSupport.php    # プレビュー時 ACF 値保存(オプトイン)
│   ├── Json/
│   │   ├── AcfJsonRepository.php
│   │   ├── FieldGroup.php
│   │   └── FieldDefinition.php
│   ├── Mapping/
│   │   ├── LocationResolver.php
│   │   ├── TargetKind.php       # enum
│   │   └── GenerationTarget.php
│   ├── Generate/
│   │   ├── Generator.php
│   │   ├── GeneratorContext.php
│   │   ├── TypeMapper.php
│   │   ├── TypeMapping.php
│   │   ├── NameConverter.php
│   │   ├── ClassPrinter.php
│   │   ├── GeneratedFile.php
│   │   └── Report.php           # エラー/警告レポート
│   ├── Manifest/
│   │   ├── ManagedKey.php       # 生成物AcfManifest.php(§7.1)が返すDTO
│   │   └── ManagedKeyCollector.php  # フィールドグループから管理キーを集める(checkのE101。§7.3)
│   ├── Cli/
│   │   └── AcfCommand.php       # wp tmswp acf sync|check
│   └── Exception/
│       ├── AcfBridgeException.php    # 基底 (extends TMSWP\Core\Exception\RuntimeException)
│       ├── AcfBridgeRuntimeException.php  # 構成・登録・CLIの誤り(AcfBridge・BridgeConfig・AcfCommand)
│       ├── AcfNotActiveException.php
│       └── GenerationException.php
└── tests/
```

`composer.json`(要点):

```json
{
    "name": "tmswp/acf-bridge",
    "require": { "php": ">=8.3", "tmswp/core": "^1.0" },
    "suggest": { "advanced-custom-fields-pro": "ACF Pro 6.2+ をWPプラグインとして導入すること" },
    "autoload": { "psr-4": { "TMSWP\\AcfBridge\\": "src/" } }
}
```

---

# 2. AcfBridge(エントリポイント)と BridgeConfig

## 2.1 `TMSWP\AcfBridge\AcfBridge`

役割: 拡張の登録。`KernelConfig::theme()` / `plugin()`の`extensions`に渡し、Kernelごとに登録する(文書12 §1.6。拡張の共通の入口`ExtensionInterface`はKernel設計書)。テーマとプラグインがそれぞれ登録すれば、それぞれのacf-json・生成先・名前空間で別々に動く。

```php
final class AcfBridge implements \TMSWP\Core\Kernel\ExtensionInterface
{
    /** nullなら登録したKernelの構成から既定値を決める(BridgeConfig::forKernel()) */
    public function __construct(?BridgeConfig $explicitConfig = null);

    /** Kernelのboot中に1回呼ばれる */
    public function register(\TMSWP\Core\Kernel\Kernel $kernel): void;

    /** Kernelに登録したAcfBridge。無ければAcfBridgeRuntimeException */
    public static function forKernel(\TMSWP\Core\Kernel\Kernel $kernel): self;

    /** 構成。未登録ならAcfBridgeRuntimeException */
    public function config(): BridgeConfig;

    /** 登録したKernel。未登録ならAcfBridgeRuntimeException */
    public function kernel(): \TMSWP\Core\Kernel\Kernel;
}
```

```php
// テーマのfunctions.php
Kernel::boot(KernelConfig::theme(__DIR__ . '/app', 'App', extensions: [new AcfBridge()]));

// プラグインの本体ファイル
Kernel::boot(KernelConfig::plugin(__FILE__, __DIR__ . '/app', 'Vendor\Plugin', extensions: [new AcfBridge()]));
```

`register()`の振る舞い(DR-04のフック整合に従い、即時の重い処理はしない):

1. 構成を決める(渡した`BridgeConfig`、無ければ`BridgeConfig::forKernel($kernel->config())`)。同じインスタンスを2つ目のKernelに登録した場合と、構成の`namespace`がKernelの`appNamespace`(またはその配下)でない場合は`AcfBridgeRuntimeException`(設定の誤り。debugに関係なくbootの例外になる)。
2. KernelのContainerに自身を`AcfBridge::class`で登録する(acf-bridgeのクラスが登録したKernelを引けるようにする)。
3. `BridgeConfig::$registerLoadJsonDirs === true`のとき`acf/settings/load_json`フィルタ(priority 10)を登録し、`BridgeConfig::$jsonDirs`のうち存在するディレクトリを、jsonDirsの逆順(jsonDirsの先頭が最後)で並びの末尾に置く。並びに既にあるもの(ACFの既定の`{stylesheet}/acf-json`等)は末尾へ移す。ACFは同じkeyのJSONのうち`load_json`の並びで後のディレクトリのものを使うため、jsonDirsの先のディレクトリ(テーマの既定では子テーマ)のものが使われ、生成(§3.1)と同じファイルになる。ほかのディレクトリとの順は§3.1。
4. `acf/json/save_paths`フィルタ(ACF 6.2+)を登録する。ACFが実際に読み込んだJSONファイル(`acf_get_local_json_files()`。ACFは`load_json`の並びで後のディレクトリのファイルを使う)が、この構成の`jsonDirs`のどれかにあるグループ等は、管理画面で保存したときの書き込み先をそのディレクトリだけにする。プラグインや親テーマのacf-jsonから読み込んだグループを、ACFの既定の保存先(子テーマの`{stylesheet}/acf-json`)へ書かず、読み込んだacf-jsonへ書き戻すためである。保存のフィルタに渡るグループの配列は読み込み元(`local_file`)を持たないため、ACFの読み込みの結果で判定する。ACFが別のKernelのacf-jsonから読み込んだもの、ACFが読み込んでいないもの(管理画面で新しく作ったグループ)は、保存先を変えない。テーマとプラグインのacf-jsonに同じkeyのファイルがある場合も、ACFが読み込んだ側にだけ書き、もう一方のファイルを上書きしない。プラグインで新しく作ったグループは、そのファイルをプラグインのacf-jsonへ移してからsyncする。
5. `$kernel->defer('plugins_loaded', 10, ...)`で、有効なACFの版の確認を予約する。ACFはプラグインの読み込み時に`ACF_VERSION`を定義するため、すべてのプラグインを読み込んだ後に確かめる。`ACF_VERSION`が6.2より古ければ`AcfBridgeRuntimeException`(設定の誤り。§1.2)。ACFが無効(`ACF_VERSION`が無い)なら確かめない。
6. `$kernel->defer('init', 6, ...)`でManifest連携(§7.2)を予約する(ACFの`acf/init`は`init` priority 5で発火するため、その直後)。手順5・6の予約は、発火済みのフックへの予約なら(テーマのKernelの手順5、本番の遅延boot(フックの発火後のboot)の手順5・6)、bootの最後のKernelのキャッチアップの中で(最初に発火した順, priority)の位置で実行される(MetaSchemaのWordPressへの登録(`init` 20)より前。Kernel設計書§3.3)。
7. `defined('WP_CLI') && WP_CLI`のとき`WP_CLI::add_command('tmswp acf', new Cli\AcfCommand())`を実行する(テーマとプラグインの両方が登録しても1つのコマンドで、対象は`--app=`で選ぶ。§5.1)。
8. `BridgeConfig::$previewMeta === true`のとき`PreviewAcfSupport::register()`を呼ぶ(§8.3)。

- ACFが無効でも`register()`は成功する(手順3・4のフィルタはACFが無ければ発火せず、手順5は版を確かめない)。手順6の申告もACFに依存せず行う(§7.2)。
- debugによる分岐とログは、登録したKernelの`$kernel->config()->debug`と`$kernel->logger()`に従う(`AcfBridge::kernel()`から引く)。`WP_DEBUG`を直接参照して分岐しない(文書12 §1.7)。

## 2.2 `TMSWP\AcfBridge\BridgeConfig`

```php
final readonly class BridgeConfig
{
    /**
     * @param list<string>          $jsonDirs       acf-json探索ディレクトリ(絶対パス)。並びの先を優先する
     * @param string                $outputDir      生成先ディレクトリ(絶対パス)
     * @param string                $namespace      生成クラスの名前空間
     * @param array<string, string> $classMap       グループkey → クラス短名の明示指定
     * @param array<string, string> $methodNameMap  "{groupKey}.{fieldName}" → メソッド名の明示指定
     * @param array<string, string> $optionsPagePostIds オプションページのmenu_slug → ACFのpost_id(§5.4)
     * @param bool                  $previewMeta    プレビュー時ACF値保存の有効化
     * @param bool                  $registerLoadJsonDirs acf/settings/load_json への自動登録
     */
    public function __construct(
        public array $jsonDirs,
        public string $outputDir,
        public string $namespace,
        public array $classMap = [],
        public array $methodNameMap = [],
        public array $optionsPagePostIds = [],
        public bool $previewMeta = false,
        public bool $registerLoadJsonDirs = true,
    ) {}

    /**
     * Kernel の起動構成から既定値を解決する。
     * - テーマ:    jsonDirs = [{stylesheet}/acf-json] (+ 子テーマ時 {template}/acf-json)
     * - プラグイン: jsonDirs = [dirname(pluginFile) . '/acf-json']
     * - outputDir = {appDir}/Acf、namespace = {appNamespace}\Acf
     */
    public static function forKernel(
        \TMSWP\Core\Kernel\KernelConfig $config,
        array $classMap = [],
        array $methodNameMap = [],
        array $optionsPagePostIds = [],
        bool $previewMeta = false,
    ): self;
}
```

- コンストラクタは入力を検証し、誤りは`AcfBridgeRuntimeException`(設定の誤り)にする。`jsonDirs`・`outputDir`は絶対パス(`/`または`C:\`等で始まる)、`namespace`は前後に`\`の無いPHPの名前空間、`classMap`・`methodNameMap`・`optionsPagePostIds`は空でない文字列から空でない文字列への対応であること。
- `jsonDirs` のうち存在しないディレクトリは無視する(エラーにしない。acf-json 未使用プロジェクトでも boot 可能にするため)。
- 既定値以外(`classMap`等)を使うときは、`new AcfBridge(new BridgeConfig(...))`で全体を渡す。

---

# 3. acf-json 発見規則(AcfJsonRepository)

## 3.1 探索規則

ACF Local JSON 標準に準拠する。

| コンテキスト | 既定探索パス |
|---|---|
| テーマ | `get_stylesheet_directory() . '/acf-json'`(子テーマ運用時は`get_template_directory() . '/acf-json'`も追加。同じkeyは子テーマ優先) |
| プラグイン | `dirname($pluginFile) . '/acf-json'` |
| 追加 | `BridgeConfig::$jsonDirs` で任意追加 |

- 対象ファイル: 各ディレクトリ直下の`*.json`(再帰しない。ACF標準と同じ)。ディレクトリの中はファイル名の昇順に読む。
- 同じkeyのJSONが複数のディレクトリにある場合、`jsonDirs`の並びで先のディレクトリのものを使う(テーマの既定では子テーマ)。後のディレクトリのものは使わずW010を出す。フィールドグループもオプションページ(§3.2)も同じ。
- 同じディレクトリの中に同じkeyのJSONが2つ以上ある場合はE102。ACFはファイル名の順で後のものを使うため、どの定義が使われるかがファイル名で決まってしまう。どちらかを消すかkeyを変える。

実行時との一致:

- ACF(6.2〜6.8の`ACF_Local_JSON::scan_files()`)は、`acf/settings/load_json`の値に`acf/json/load_paths`フィルタ(ACF 6.2+)を当てた並びの順に各ディレクトリを読み、同じkeyは後に読んだファイルで上書きする。つまり、並びで後のディレクトリのものを使う。
- AcfBridgeは`load_json`の並びの末尾にjsonDirsを逆順で置く(§2.1の手順3)。そのため、ACFもjsonDirsの先のディレクトリのファイルを使い、生成と一致する。
- ほかのディレクトリとの順: どのディレクトリのファイルが使われるかは、すべてのフィルタを当てた後の最終的な`get_load_paths()`の並び(後のディレクトリが勝つ)で決まる。AcfBridgeは自分のフィルタの中で並びを決めるだけで、ほかのフィルタの並べ替えを打ち消さない。
  - AcfBridgeのフィルタより前に並びにあるディレクトリ(ACFの既定のディレクトリ、先に動いたフィルタが加えたもの)は、jsonDirsより前になる。同じkeyではjsonDirsのものが使われる。
  - AcfBridgeのフィルタより後に動く`load_json`のフィルタ(priorityが10より大きいもの、priority 10で後に登録したもの)や`acf/json/load_paths`フィルタが、ディレクトリを並びの末尾に加えた場合は、そのディレクトリがjsonDirsより後になり、同じkeyではそちらが使われる。それらのフィルタが先頭に加えた場合はjsonDirsが勝ち、並びを入れ替えた場合(jsonDirsの順を変えた場合を含む)は入れ替えた後の並びで決まる。どの場合も、jsonDirsの先のディレクトリ以外のものが使われると、生成(jsonDirsだけを読む)と食い違う。jsonDirsの外に同じkeyのJSONを置かず、jsonDirsの並びを変えるフィルタを使わない。
  - テーマとプラグインのKernelがそれぞれAcfBridgeを登録した場合は、後に登録したKernel(通常はテーマ。プラグインはテーマより先に読み込まれる)のjsonDirsが後になり、同じkeyではそちらが使われる。Kernelをまたいで同じkeyのJSONを置かない。
- `registerLoadJsonDirs: false`のときは、`load_json`の並びを利用側が決める。生成と一致させるには、jsonDirsの先のディレクトリを並びの後に置く。

## 3.2 ファイル種別の判定

ACFのLocal JSON(6.2〜6.8の`ACF_Local_JSON::scan_files()`・`get_files()`)と同じ規則で判定する。ACFが読まない形は、黙って読んだり捨てたりせず、E102かW001にする。まず、ファイルの形を次の表で判定する。

| ファイルの形 | 扱い |
|---|---|
| ファイルを読めない、JSONの構文の誤り | E102 |
| トップレベルが配列(list。複数のグループを1ファイルに入れた手動エクスポートの形。空の配列を含む) | E102。ACFは読まないため、生成と実行時が食い違う。1ファイルに1つのオブジェクトに分ける |
| トップレベルが配列でない(文字列・数値・`null`等) | W001で無視(ACFも読まない) |
| オブジェクトで、`key`が無い・空・文字列でない | W001で無視 |

`key`を持つオブジェクトは、同じkeyのJSONのうち使うもの(§3.1)を、次の表で扱う。ACF(6.2〜6.8)の`get_files('acf-field-group')`は、keyが`group_`で始まるものに加え、keyの接頭辞を問わず`fields`を持つ(`isset()`。値が`null`でない)ものをフィールドグループとして読むため、同じ判定にする。1つのJSONが複数の行に当たる場合は、当たるすべての扱いをする。

| 条件 | 扱い |
|---|---|
| `key`が`group_`で始まる、または`fields`を持つ | フィールドグループとして**生成対象**(keyの接頭辞が`post_type_`・`taxonomy_`・`ui_options_page_`でも同じ) |
| `key`が`ui_options_page_`で始まる | ACFオプションページ定義として、location解決の補助情報(menu_slug → `post_id`設定)を読む。クラス生成はフィールドグループのlocation側から行う(§5.4) |
| `key`が`post_type_` / `taxonomy_`で始まる | ACF UI登録のCPT/タクソノミーの定義は**対象外**。W001を出す(`fields`を持てば、フィールドグループとしては上の行のとおり読む)。rules/で「CPT/タクソノミーは`#[AsPostType]`/`#[AsTaxonomy]`でPHP宣言する。ACF UI登録は使用しない」を必須規約とする |
| 上のどれにも当たらない(`group_`・`ui_options_page_`・`post_type_`・`taxonomy_`のどれでも始まらず、`fields`を持たない) | 不明。W001で無視 |

## 3.3 `TMSWP\AcfBridge\Json\AcfJsonRepository`

```php
final class AcfJsonRepository
{
    /** @param list<string> $jsonDirs */
    public function __construct(private readonly array $jsonDirs) {}

    /**
     * 全ディレクトリのJSONを1回ずつ読み、§3.1・§3.2の規則で結果を返す。
     * @throws GenerationException E102(§3.1・§3.2)
     */
    public function load(): AcfJsonSource;
}

final readonly class AcfJsonSource
{
    public function __construct(
        /** @var list<FieldGroup> 使うフィールドグループ。並び順はグループkeyの昇順(決定性のため) */
        public array $fieldGroups,
        /** @var array<string, string> ui_options_pageのmenu_slug → post_id設定。post_id未設定のページは含めない */
        public array $optionsPagePostIds,
        /** @var list<string> 読み込みの警告(W001・W010) */
        public array $warnings,
        /**
         * 正規化済み入力のハッシュ。
         * sha256( JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODEで再エンコードした
         *         $fieldGroupsの元の配列(key昇順、modifiedフィールドは除外。オブジェクトのキーは並べ替え、
         *         flexible_contentのフィールドのlayoutsはJSONの順のまま) )
         */
        public string $sourceHash,
    ) {}
}
```

生成(`wp tmswp acf sync` / `check`)は、`load()`の結果だけを使う(各JSONを1回だけ読む)。

正規化仕様:

- `FieldGroup` は `key` / `title` / `fields`(`FieldDefinition` のリスト、JSON 出現順を維持)/ `location`(生の二重配列)/ `active` を持つ readonly DTO。`active: false`(無効化グループ)は生成対象外とし W011 を出す。
- `FieldDefinition`は`key` / `name` / `label` / `type` / `required`(bool)/ `returnFormat`(?string)/ `multiple`(bool)/ `fieldType`(?string, taxonomy用)/ `subFields`(list<FieldDefinition>)/ `layouts`(flexible用: list<FlexibleLayout>)/ `raw`(元配列, array<string,mixed>)を持つreadonly DTO。
- `FlexibleLayout`は`name`(string)/ `subFields`(list<FieldDefinition>)を持つreadonly DTO。`layouts`はJSONの順を保ち、生成するレイアウトのshapeと正規化の関数もその順に並ぶ。数字だけのname(`'0'`等)も文字列のまま持つ。生成は次のレイアウトをE002にする(全階層のflexible_contentに当てはめる)。
  - 同じフィールドの中の、同じnameのレイアウト(ACFの行の`acf_fc_layout`からレイアウトを決められない)
  - PHPが配列のキーで整数に変えるname(10進の整数の文字列。`0`・`12`等)。生成する、レイアウトの名前をキーにした正規化の関数の配列(traitの`acfLayouts()`の引数。`array<string, Closure>`)が、その名前を文字列のキーで持てないためである
- ハッシュから `modified`(ACF が保存毎に更新するタイムスタンプ)を除外することで、無意味なドリフト検出を防ぐ。**それ以外のキーはすべてハッシュに含める**(label 変更等も再生成対象。生成コードの PHPDoc に label を含めるため)。
- ハッシュは、オブジェクトのキーの順に依存しないようキーを並べ替えるが、`type`が`flexible_content`のフィールドの定義の`layouts`(ACFはレイアウトのkeyをキーにしたオブジェクトで保存する)は並べ替えない。レイアウトの順は生成コードの順になるため、順だけを変えてもハッシュが変わる。それ以外の`layouts`というキー(groupの`metadata`・selectの`choices`の中等)は、ほかのオブジェクトと同じくキーを並べ替える。`modified`は、flexible_contentの`layouts`の直下を含め、全階層で除く。

---

# 4. フィールドグループ → クラスのマッピング規則(LocationResolver)

## 4.1 ターゲットモデル

```php
enum TargetKind: string
{
    case Post = 'post';        // 投稿タイプ単位 → {Studly}Fields
    case Term = 'term';        // タクソノミー単位 → {Studly}TermFields / TermFields
    case User = 'user';        // 全ユーザー共通 → UserFields
    case Options = 'options';  // オプションページ単位 → {Studly}Options
}

final readonly class GenerationTarget
{
    public function __construct(
        public TargetKind $kind,
        /** post_type名 / taxonomy名(all可) / ''(user) / options_page menu_slug */
        public string $subtype,
        public string $className,   // 短名(名前空間なし)
    ) {}
}
```

## 4.2 location パラメータの解決表

ACF の `location` は「ORグループの配列 × 各ORグループ内は AND 条件の配列」。各 AND グループを独立に 1 ターゲットへ解決し、全 OR グループの和集合(重複排除)がそのフィールドグループのターゲット集合となる。**1 グループが複数ターゲットに解決された場合、各ターゲットのクラスすべてにそのグループのアクセサを生成する。**

`operator` が `"=="` の条件のみ解決に使う。`"!="` は表示絞り込みであり保存先を決めないため無視する(AND グループ内に `==` の解決可能条件が他になければ W002 でその AND グループをスキップ)。

| param | `== value` の解決結果 |
|---|---|
| `post_type` | `Post` / subtype = value |
| `page_template`, `page_type`, `page_parent`, `page` | `Post` / subtype = `page` |
| `post_category`, `post_format` | `Post` / subtype = `post` |
| `attachment` | `Post` / subtype = `attachment` |
| `post_status`, `post_taxonomy`, `post` (個別ID) | 単独では解決不能。同一 AND グループ内に上記の解決可能条件があればそれに従う。なければ W003(`post`)/W002 でスキップ |
| `taxonomy` | `Term` / subtype = value(`all` 含む) |
| `user_form`, `user_role` | `User` / subtype = `''`(role 別にクラスは分けない。全ユーザーグループは単一の `UserFields` に集約) |
| `options_page` | `Options` / subtype = value(menu_slug) |
| `current_user`, `current_user_role` | 表示者条件。保存先を決めないため無視(`!=` と同じ扱い) |
| `comment`, `widget`, `nav_menu`, `nav_menu_item`, `block` | 非対応。W006 でその AND グループをスキップ |
| 未知の param | W006 でスキップ |

競合規則:

- 同一 AND グループ内に**異なる種別**の解決可能条件が複数ある場合(例: `post_type==post` AND `taxonomy==genre`)、優先順位 `post_type系 > taxonomy > options_page > user系` で 1 つだけ採用し W004 を出す。
- 同一 AND グループ内に**同一 param** の `==` が複数値である場合(`post_type==a` AND `post_type==b`)は条件不成立として W007 でスキップ。
- フィールドグループの全 AND グループが解決不能 → そのグループ全体を W002 でスキップ(エラーにはしない。`check --strict` では失敗となる §9.3)。

## 4.3 クラス命名規則

`studly()` 変換: `[^A-Za-z0-9]` を区切りとして分割 → 各語の先頭を大文字化して連結(`product-item` → `ProductItem`、`theme_settings` → `ThemeSettings`)。変換結果が数字始まりの場合は `N` を前置(`4koma` → `N4koma`)。変換結果が空(全角文字のみ等)の場合は E003。

| TargetKind | クラス短名 | 例 |
|---|---|---|
| Post | `{studly(post_type)}Fields` | `product` → `ProductFields`、`post` → `PostFields`、`page` → `PageFields` |
| Term | `{studly(taxonomy)}TermFields`、`all` のときは `TermFields` | `genre` → `GenreTermFields` |
| User | `UserFields`(固定) | — |
| Options | `{studly(menu_slug)}Options` | `theme-settings` → `ThemeSettingsOptions` |

- `BridgeConfig::$classMap`(グループ key → クラス短名)が指定されている場合、そのグループ由来のターゲットのうち **単一ターゲットに解決されるグループに限り** クラス名を上書きできる。複数ターゲットのグループに classMap を指定した場合は E003。
- 異なるターゲットが同一クラス名に解決された場合(例: post_type `product` と taxonomy `product-fields` の偶発衝突)は E003(メッセージに両ターゲットと classMap での回避方法を含める)。
- 同一ターゲットに複数グループが解決された場合は **1 クラスにマージ**する(グループ key 昇順でフィールドを連結)。マージ後にフィールド名が重複したら E001。

---

# 5. コードジェネレータ仕様

## 5.1 コマンド

| コマンド | 説明 |
|---|---|
| `wp tmswp acf sync [--app=<namespace>] [--dry-run]` | acf-jsonから`app/Acf/`配下の全クラスを再生成する。`--dry-run`は書き込まず生成予定と差分を表示 |
| `wp tmswp acf check [--app=<namespace>] [--strict]` | メモリ上で再生成しディスクと比較。差分があればexit 1。`--strict`は警告(W*)でもexit 1 |

- WP-CLI経由のためWP・テーマ・プラグインと、それらのKernelのboot(`AcfBridge`の登録を含む)が済んでおり、`BridgeConfig`は登録時の値が使われる。
- 対象は`--app=<namespace>`(Kernelの`appNamespace`)で選んだKernelに登録した`AcfBridge`。`AcfBridge`を登録したKernelが1つなら省略できる。登録したKernelが無い・2つ以上あるのに`--app=`が無い・`--app=`のKernelに登録が無い場合はexit 2。
- ACFプラグイン自体は **sync/checkに必須ではない**(生成はacf-jsonファイルと`BridgeConfig`だけから行い、ACFの実行時には問い合わせない。生成結果を環境に依存させないため。§5.4)。
- 終了コード: `sync` = 0(成功。W* 含む)/ 1(E* 発生。ファイルは一切書き込まない)/ 2(環境・設定エラー)。`check` = 0(差分なし)/ 1(差分あり、または `--strict` 時に W* あり)/ 2(E* または環境エラー)。

composer スクリプト(スターターの `composer.json` に同梱。QA 文書の `composer qa` 系と同列):

```json
{
    "scripts": {
        "acf:sync": "wp tmswp acf sync",
        "acf:check": "wp tmswp acf check"
    }
}
```

wp コマンドが PATH にない環境(wp-env 等)では各プロジェクトでスクリプトを上書きする。wp-env を使うプロジェクトは `@wordpress/env` を package.json で版を固定して `npm ci` で入れ、コマンド名ではなくパスで呼ぶ(例: `"acf:check": "node node_modules/@wordpress/env/bin/wp-env run cli wp tmswp acf check"`)。コマンド名 `wp-env` を PATH や npx で解決すると、第三者のパッケージやグローバルの同名コマンドを実行しうるためである。

## 5.2 出力ファイル仕様

- 出力先: `BridgeConfig::$outputDir`(既定 `{appDir}/Acf/`)。ディレクトリが無ければ作成。書込不可は E102。
- 1 ターゲット = 1 ファイル = 1 final クラス。ファイル名はクラス短名 + `.php`。
- ターゲットクラスに加えて、ACF 管理下キー一覧の生成物 `AcfManifest.php`(§7.1)を常に生成する。`AcfManifest.php` も本節の生成規約(`@generated` ヘッダ・期待ファイル集合・孤児削除・`check` のバイト一致比較・決定的出力)の対象に含まれる。
- `sync` は「期待ファイル集合」を全生成 → 既存の `@generated by tmswp/acf-bridge` マーカー付きファイルのうち期待集合に無いもの(孤児)を**削除**する。マーカーの無い手書きファイルは削除せず I002 を出す。
- 出力は決定的: 同一の acf-json 入力(modified 除く)からは常にバイト同一の出力を生成する(グループ key 昇順、フィールドは JSON 出現順、PSR-12 整形を Printer 内で固定実装。外部フォーマッタに依存しない)。
- 出力は、そのままでリポジトリのphp-cs-fixerの規約(PER-CS 2.0と`global_namespace_import`・`no_superfluous_phpdoc_tags`等)とPHPStan(level max)を通る。使うクラス(`WP_Post`・`DateTimeInterface`・`ObjectType`・`AcfFieldAccess`等)はuse文で取り込み、PHPDocの型がPHPの型と同じgetterには`@return`を書かない。ただし、生成クラスの短名と大文字小文字を区別せずに同じ短名のクラス(classMapで`ObjectType`・`Objecttype`・`DateTimeImmutable`等にした場合)はuseせず、完全修飾名(`\TMSWP\Core\Meta\ObjectType`等)で参照する。PHPのクラス名は大文字小文字を区別しないため、useするとクラスの宣言と衝突して読み込みがfatalになるためである。生成時のエラーにはしない(クラス名の選択を制限しないため)。同じく、生成クラスの短名と大文字小文字を区別せずに同じ型の別名(classMapで`AcfImage`・`Acfimage`等にした場合)は、`@phpstan-import-type AcfImage from \TMSWP\AcfBridge\AcfTypes as AcfImageType`のように、生成クラスの短名・ほかの別名・参照するクラスの短名のどれとも重ならない名前(`Type`を付けた名前)で取り込み、生成コードの型もその名前で書く。PHPStanは、クラスと同じ名前の別名を`typeAlias.duplicate`にするためである。monorepoでは、全31型のfixtureの生成物(packages/acf-bridge/tests/fixtures/expected-app)と、fixtureのテーマ・プラグインの生成物を、この2つの検査の対象にする。

ファイルヘッダ(正準形):

```php
<?php

/**
 * ProductFields — ACF typed accessors for post_type 'product'.
 *
 * @generated by tmswp/acf-bridge
 *
 * このファイルは自動生成。手で編集しないこと(次回syncで上書きされる)。
 * 再生成: wp tmswp acf sync (composer acf:sync)
 *
 * sources:
 *   acf-json/group_66f01a2b3c4d5.json (製品情報)
 */

declare(strict_types=1);

namespace App\Acf;
```

- ヘッダの `sources` は当該クラスの由来 JSON ファイル一覧のみを記す。全入力の集約ハッシュ(§3.3 `sourceHash`)は各生成ファイルへは埋め込まず、`AcfManifest.php`(§7.1)のヘッダと `check` 成功メッセージ(§9.1 手順8)にのみ記録する。これにより acf-json 1 ファイルの編集で差分が出るのは実際に影響を受けた生成ファイル(+ `AcfManifest.php`)のみとなる。`check` の判定は §9.1 の一段の全文バイト比較のみであり、ハッシュによる早期判定は行わない(孤児・欠損も §9.1 手順5が検出する)。

## 5.3 生成クラスの構造(正準形)

post_type `product` に `price`(number)、`main_visual`(image, return_format=array)、`store_info`(repeater)がある場合:

```php
use TMSWP\AcfBridge\AcfFieldAccess;
use TMSWP\Core\Meta\ObjectType;
use WP_Post;

/**
 * @phpstan-import-type AcfImage from \TMSWP\AcfBridge\AcfTypes
 */
final class ProductFields
{
    use AcfFieldAccess;

    /** 製品価格 (number, group_66f01a2b3c4d5) */
    public const PRICE = 'price';
    public const KEY_PRICE = 'field_66f0aaaa1111';

    /** メイン画像 (image, group_66f01a2b3c4d5) */
    public const MAIN_VISUAL = 'main_visual';
    public const KEY_MAIN_VISUAL = 'field_66f0bbbb2222';

    /** 店舗情報 (repeater, group_66f01a2b3c4d5) */
    public const STORE_INFO = 'store_info';
    public const KEY_STORE_INFO = 'field_66f0cccc3333';

    public static function of(WP_Post|int $post): self
    {
        return new self(ObjectType::Post->resolveId($post));
    }

    /** 製品価格 (number, group_66f01a2b3c4d5) */
    public function price(): int|float|null
    {
        return $this->acfNumber(self::KEY_PRICE);
    }

    public function setPrice(int|float|null $value): void
    {
        $this->acfUpdate(self::KEY_PRICE, $value);
    }

    public function hasPrice(): bool
    {
        return $this->acfHas(self::KEY_PRICE);
    }

    public function deletePrice(): bool
    {
        return $this->acfDelete(self::KEY_PRICE);
    }

    /**
     * メイン画像 (image, group_66f01a2b3c4d5)
     *
     * @return AcfImage|null
     */
    public function mainVisual(): ?array
    {
        return $this->acfImage(self::KEY_MAIN_VISUAL);
    }

    public function setMainVisual(?int $value): void
    {
        $this->acfUpdate(self::KEY_MAIN_VISUAL, $value);
    }

    /**
     * 店舗情報 (repeater, group_66f01a2b3c4d5)
     *
     * @return list<array{shop_name: string, tel: string, open_hours: string}>
     */
    public function storeInfo(): array
    {
        return $this->acfRows(self::KEY_STORE_INFO, fn(array $row, string $path): array => [
            'shop_name' => $this->acfStringOf($row['shop_name'] ?? null, $path . '.shop_name'),
            'tel' => $this->acfStringOf($row['tel'] ?? null, $path . '.tel'),
            'open_hours' => $this->acfStringOf($row['open_hours'] ?? null, $path . '.open_hours'),
        ]);
    }

    /** @param list<array<string, mixed>> $value */
    public function setStoreInfo(array $value): void
    {
        $this->acfUpdate(self::KEY_STORE_INFO, $value);
    }

    // hasMainVisual()・deleteMainVisual()・hasStoreInfo()・deleteStoreInfo()も、hasPrice()・deletePrice()と同じ形
}
```

ファクトリの正準シグネチャ(TargetKind 別):

| TargetKind | ファクトリ | 内部 ACF id |
|---|---|---|
| Post | `public static function of(WP_Post\|int $post): self` | `ObjectType::Post->resolveId($post)` |
| Term | `public static function of(WP_Term\|int $term): self` | `'term_' . ObjectType::Term->resolveId($term)` |
| User | `public static function of(WP_User\|int $user): self` | `'user_' . ObjectType::User->resolveId($user)` |
| Options | `public static function instance(): self` | §5.4 の post_id(既定 `'option'`)。キャッシュしない(呼び出し毎に new) |

- `of()`は、IDをコアの`ObjectType::resolveId()`で検査し、1未満のID(IDが0の`WP_Post`等を含む)を`InvalidArgumentException`(`TMSWP\Core\Exception\InvalidArgumentException`。メッセージは`meta object ID must be a positive integer`)にする。ACFはpost_idの0を現在のループの投稿に置き換え、WordPressのメタの関数は負のIDを`absint()`で別のIDにするため、別の対象を黙って読み書きしないようにする。

各フィールドにつき生成するメンバ:

1. `public const {UPPER_SNAKE(name)} = '{name}';` — フィールド名定数
2. `public const KEY_{UPPER_SNAKE(name)} = '{field key}';` — フィールドkeyの定数(生成アクセサがACFを呼ぶのに使う。`acf/load_field`等のフック対象指定にも使える)
3. getter `public function {camel(name)}(): {型}` — §6 の表に従う。PHPDoc にグループ key・label・ACF 型を 1 行コメントで付す
4. setter `public function set{Studly(name)}({保存型} $value): void` — §6 の「setter 引数型」に従う。内部は `acfUpdate()` 委譲
5. date_picker・date_time_pickerだけの追加のgetter `public function {camel(name)}AsDateTime(): ?DateTimeImmutable`(§6.3)
6. `public function has{Studly(name)}(): bool` — 値が保存されているか(§6.1の`has`。内部は`acfHas()`)
7. `public function delete{Studly(name)}(): bool` — 値を消す(`delete_field()`。消したらtrue。内部は`acfDelete()`)

- 生成アクセサ(3〜7)は、ACFをフィールドのkey(KEY_定数)で呼ぶ(§6.1)。名前で呼ぶのは、traitの公開のエスケープハッチ(`value()`・`rawValue()`・`update()`・`delete()`・`has()`。§11)だけである。

値を持たないフィールド型(`message` / `tab` / `accordion`)はメンバを一切生成しない。

## 5.4 ACF オプションページ → Options クラス生成

- location `options_page == {menu_slug}` から `{studly(menu_slug)}Options` を生成する(§4.3)。
- 内部ACF idの決定順: (1) `ui_options_page_*.json`の`post_id`設定 → (2) `BridgeConfig::$optionsPagePostIds[$menu_slug]`(PHPで登録したオプションページ用) → (3) `'option'`。(1)と(2)が同じページに違う`post_id`を指定した場合はE102(exit 2)。生成時にACFの実行時(`acf_get_options_page()`)には問い合わせない(開発環境とCIで生成結果が変わらないようにするため)。
- 生成される Options クラスはコアのオプショングループ enum(`OptionAccess` trait を用いる backed enum)としては **宣言しない**。理由: オプショングループ enum はコアの `OptionRepository`/`WpOptionStorage` を保存主体とする宣言層であり、ACF オプションページの保存主体は ACF 自身であるため、二重管理を避ける。ACF オプションページの値アクセスは生成 Options クラスに一本化し、同じ値を `#[OptionSchema]` で重複宣言することは rules/ で禁止する。
- オプションページの登録自体(`acf_add_options_page`)は acf-json(`ui_options_page_*.json`、ACF Pro 6.2+)に任せる。それ以前の PHP 登録運用の場合も生成は location の menu_slug だけで可能。

## 5.5 メソッド名変換・衝突規則(NameConverter)

```php
final class NameConverter
{
    /** snake_case → camelCase。'main_visual' → 'mainVisual' */
    public static function methodName(string $fieldName): string;

    /** snake_case → StudlyCaps。'main_visual' → 'MainVisual' */
    public static function studly(string $name): string;

    /** 'main_visual' → 'MAIN_VISUAL'。ASCII snake_caseでない名前と、クラス定数に使えない名前(`class`)はnull */
    public static function constName(string $fieldName): ?string;

    /** アクセサを生成できる名前(ASCII snake_case: ^[a-z][a-z0-9_]*$)か */
    public static function isAccessorEligible(string $fieldName): bool;
}
```

1. 変換対象は`^[a-z][a-z0-9_]*$`(ASCII snake_case)に一致するフィールド名のみ。一致しない名前(日本語フィールド名・大文字混在等)はアクセサ・定数を生成せずW009を出し、`BridgeConfig::$methodNameMap`(`"{groupKey}.{fieldName}" => 'asciiName'`)での明示マッピングを案内する。methodNameMapを指定したときは、メソッドをその名前で生成し、定数の名前もその名前から作る(`cssClass` → `CSS_CLASS`・`KEY_CSS_CLASS`。定数の値は元のフィールド名のまま)。旧案件には`get_field('店舗情報リピート')`のような日本語名が実在するため、エスケープハッチとしてtraitの`value()`(§11)も常に利用可能。
2. 予約名は、traitの`AcfFieldAccess`のメソッド(public・protected・privateのすべて)と、生成クラスが宣言するファクトリ(`of`・`instance`。TargetKindにかかわらず両方)である。traitのメソッドは、Generatorが生成時に`AcfFieldAccess`のReflectionで集める(手書きの一覧を持たない。traitを変えたときに漏れないようにするため)。traitのメソッドと同じ名前のメソッドを生成クラスが宣言すると、読み込みはできても、traitの中の呼び出しが生成側のメソッドへ向いて実行時に壊れるため、予約名との衝突は生成時に止める。
3. 1つのフィールドから生成するメソッド(getter・`set{Studly}`・`{camel}AsDateTime`・`has{Studly}`・`delete{Studly}`)を、予約名と、同じクラスでほかのフィールドから生成したメソッドと照合する。PHPのメソッド名は大文字小文字を区別しないため、大文字小文字を区別せずに照合する(`foo_bar`の`fooBar()`と`foobar`の`foobar()`、`price`の`setPrice()`と`set_price`の`setPrice()`は衝突)。定数(`{UPPER}`・`KEY_{UPPER}`)は大文字小文字を区別して照合する(`price`の`KEY_PRICE`と`key_price`の`KEY_PRICE`は衝突)。
4. 衝突は、接尾辞等で自動的に避けず、E002にしてそのフィールドのアクセサを生成しない。メッセージは、フィールド名・グループのkey・衝突した名前と相手と、解決の方法(`BridgeConfig::$methodNameMap['{groupKey}.{fieldName}']`で別のメソッド名にする。定数の名前もその名前から作る)を含む。
5. PHPの予約語(`list`・`function`・`match`等)は、PHPがメソッド名・クラス定数名として受け付けるため、変えずに生成する(`list` → `list()`・`LIST`)。PHPがクラス定数の名前として受け付けない`class`(定数`CLASS`)は、E002にする(methodNameMapで別の名前にすれば生成できる)。
6. group・repeater・flexible_contentのサブフィールドの名前は、生成する配列のキーになる(ASCII snake_caseでなくてよい。§6.1)。次のサブフィールドはE002にする(全階層のgroup・repeater・flexible_contentのレイアウトに当てはめる)。
   - 同じ階層のサブフィールドの名前の重複
   - flexible_contentのレイアウトの`acf_fc_layout`という名前のサブフィールド(ACFが各行のレイアウトの名前に使う)
   - PHPが配列のキーで整数に変える名前(10進の整数の文字列。`0`・`7`・`-12`等。`01`・`1.5`は変えない)。ACFが返すgroup・行の配列はその名前を文字列のキーで持てず、生成するshapeと実際の値が食い違うためである。判定はPHPの配列のキーの変換そのもので行い、メッセージはACFでフィールド名を変えるよう案内する。トップレベルのフィールド名は配列のキーにならず、ASCII snake_caseでない名前(数字で始まる名前を含む)は規則1のW009でアクセサを生成しないため、この制約は無い。

## 5.6 Generator / GeneratorContext / Report

生成パイプラインは WP 非依存の純関数として設計し、ユニットテスト(JSON フィクスチャ)可能にする。

```php
final readonly class GeneratorContext
{
    /**
     * @param array<string, string> $optionsPagePostIds menu_slug → post_id(acf-jsonのui_options_pageと
     *                                                  BridgeConfig::$optionsPagePostIdsから作る。§5.4)
     */
    public function __construct(
        public BridgeConfig $config,
        public array $optionsPagePostIds,
        public string $sourceHash = '',
    ) {}
}

final class Generator
{
    public function __construct(
        private readonly LocationResolver $locations,
        private readonly TypeMapper $types,
    ) {}

    /**
     * @param list<FieldGroup> $groups
     * @return Report  生成ファイル一覧+エラー/警告
     */
    public function generate(array $groups, GeneratorContext $context): Report;
}

final readonly class GeneratedFile
{
    public function __construct(
        public string $relativePath,  // 'ProductFields.php'
        public string $contents,
    ) {}
}

final class Report
{
    /** @return list<GeneratedFile> */
    public function files(): array;
    /** @return list<string> 'E001: ...' 形式 */
    public function errors(): array;
    /** @return list<string> 'W002: ...' 形式 */
    public function warnings(): array;
    /** @return list<string> 'I001: ...' 形式 */
    public function infos(): array;
    public function hasErrors(): bool;
}
```

- `AcfManifest.php`(§7.1)の管理キーは、cloneを解決したグループから`Manifest\ManagedKeyCollector`で集める。`wp tmswp acf check`のE101(§7.3)も同じ収集を使うため、生成物の管理キーとE101で照合するキーは常に一致する。
- オプションページの`post_id`は`GeneratorContext::$optionsPagePostIds`だけで決め、GeneratorはACFの実行時に問い合わせない(§5.4)。

## 5.7 エラー・警告コード表

| コード | 区分 | 内容 |
|---|---|---|
| E001 | エラー | 同一ターゲットにマージされる複数グループ間でフィールド名が重複 |
| E002 | エラー | メソッド名・定数名の衝突(予約名・ほかのフィールドとの衝突。メソッド名は大文字小文字を区別しない)、クラス定数に使えない名前(`class`)、サブフィールドの名前の重複・`acf_fc_layout`・10進の整数の名前(§5.5)、flexible_contentのレイアウトの名前の重複・10進の整数の名前(§3.3) |
| E003 | エラー | クラス名衝突、studly 変換不能、classMap の不正指定 |
| E004 | エラー | clone フィールドの参照先(field key / group key)が acf-json 内に見つからない |
| E005 | エラー | clone の循環参照 |
| E101 | エラー | `#[MetaSchema]` 宣言と ACF 管理下キーの重複(§7.3。check 時に静的検出) |
| E102 | エラー | JSON構文エラー、トップレベルが配列のJSON、同じディレクトリの中の同じkeyのJSON(§3.1・§3.2)、出力先書込不可、設定不備 |
| W001 | 警告 | 非対象JSON(post_type_*/taxonomy_*/不明種別、トップレベルが配列でないJSON、keyの無いJSON。§3.2)を無視した |
| W002 | 警告 | location を解決できずグループ(または AND グループ)をスキップ |
| W003 | 警告 | `post`(個別 ID)条件は静的解決不能 |
| W004 | 警告 | 同一 AND グループ内の複数対象条件に優先順位を適用した |
| W005 | 警告 | 未知のフィールド型(サブフィールドを含む)を`mixed`として生成した |
| W006 | 警告 | 非対応 location パラメータ(comment/widget/block 等)をスキップ |
| W007 | 警告 | 不成立 AND 条件(同一 param の == 重複) |
| W009 | 警告 | ASCII snake_case でないフィールド名のためアクセサ未生成 |
| W010 | 警告 | 複数ディレクトリに同じkeyのJSON(jsonDirsの先のディレクトリのものを使う。§3.1) |
| W011 | 警告 | `active: false` のグループをスキップ |
| I001 | 情報 | acf-json に裏付けのない PHP 登録グループを検出(生成対象外) |
| I002 | 情報 | outputDir に `@generated` マーカーの無いファイルが存在(削除しない) |

---

# 6. ACF フィールド型 → PHP 型マッピング表(TypeMapper)

## 6.1 共通規則

- **getterは「フォーマット済み値」**(`get_field($key, $id, true)`。`$key`はKEY_定数)を返す。return_format・multiple・field_typeの設定で型が決まる。
- **field key**: 生成アクセサ(getter・setter・`*AsDateTime`・`has*`・`delete*`)は、ACFをフィールドのkeyで呼ぶ(`get_field(self::KEY_X, …)`・`update_field(self::KEY_X, …)`・`delete_field(self::KEY_X, …)`)。ACF(6.8.10の`acf_maybe_get_field()`)は、名前で呼ばれると呼び出しの対象の参照のメタ(`_{name}`)でフィールドを探し、参照の無い投稿では、読み取りはダミーのフィールドでフォーマットしないrawを返し、削除は何も消さないためである。keyで呼ぶと、ACFはkeyのフィールドの定義を使い、`update_field()`は値と参照のメタを書く。
  - keyのフィールドがACFに登録されていない(`acf_get_field($key)`が見つけない)ことは、acf-jsonと生成クラスの食い違い等の設定の誤り(文書00 §5.6)として、debugにかかわらず`AcfBridgeRuntimeException`にする。ACFはそのkeyを名前とするダミーのフィールドで、別のメタを黙って読み書きするためである。メッセージは、keyと生成クラスのメソッドを含む。
  - 例外とログの位置(フィールド名)と、`has*()`が保存先を調べるメタの名前には、ACFに登録されたフィールドの定義の名前(`acf_get_field($key)['name']`。`get_field()`が読む名前と同じ)を使う。生成コードは名前の定数をヘルパに渡さない。
  - 名前で呼ぶのは、traitの公開のエスケープハッチ(`value()`・`rawValue()`・`update()`・`delete()`・`has()`。§11)だけである。
- **未設定時の値の正規化**: ACFの`null`/`false`/`''`の揺れを生成コード側で吸収する。文字列系は`''`、配列系は`[]`、boolは`false`、それ以外は`null`に正規化する。一度も保存していないgroupが返す空の配列も未設定として`null`にする。未設定はエラーにしない(文書00 §5.6の「データが無いこと」)。`required`設定はACFでは保存を強制しないため**型のナローイングには使わない**(常に未設定がありうる前提の型とする)。
- **形の不一致**: ACFが未設定でない値を返し、それが本表の型に合わない場合(textに配列が保存されている、numberが数値の文字列でない、画像の配列のキーや型が`AcfImage`と違う、日付のrawが書式に合わない等)は、未設定と区別し、文書00 §5.6の「実行時のデータ取得の失敗」として扱う。生成クラスのKernel(`Kernel::forClass()`。生成クラスの名前空間は、acf-bridgeを登録したKernelのアプリの名前空間の中にある。§2.1)のdebugでは`AcfBridgeRuntimeException`を投げ、本番ではそのKernelのロガーに`error`で記録して未設定の値を返す。記録(ログのcontextの`field`・`acfId`・`expected`・`actual`・`class`)は、フィールドの位置・post_id・想定した型・実際の値の型と生成クラスを含み、値そのものは含まない。位置は、トップレベルはフィールド名、listの要素は`name[添字]`、group・repeater・flexible_contentのサブフィールドは`name.sub`(例: `stores[0].shop_name`)で、どの階層のどのフィールドかを示す(§6.5)。`list<>`の型(gallery・relationship・複数選択等)は要素ごとに検査し、本番では不一致の要素だけを記録して除き、ほかの要素は返す(1つの要素の不正で全体を捨てない)。objectのlist(relationship・post_object・taxonomy・userの複数で、return_formatがobject。getterの型は`list<\WP_Post>`・`list<\WP_Term>`・`list<\WP_User>`)は、ジェネレータが要素のクラスを渡して`acfObjectList(self::KEY_X, WP_Post::class)`の形で読み、要素ごとに`instanceof`で検査する。単一のobjectは`acfObject()`で同じクラスを検査する。
- **`has()`**(生成の`has{Studly}()`と、エスケープハッチの`has($name)`): 値が保存されているかを、`get_field()`が読む保存先(`acf_get_valid_post_id()`で解決したpost_id)のメタの行(optionsのpost_idはACFが値を保存するoption)の有無で判定する。フィールドの`default_value`、`register_*_meta`の既定値、groupが返す空の配列は「保存されている」としない。
- **setterは「保存型(raw)」** を受け取る。`update_field`はraw値(添付ID・投稿ID・`Ymd`文字列等)で保存するため。`null`を渡した場合は値を空に更新する(`update_field($key, null)`委譲。削除は生成の`delete{Studly}()`を使う)。値は`wp_slash()`してから`update_field()`に渡す。ACFは、メタでは`update_metadata()`が、optionsでは自身が`wp_unslash()`するため、slashしないとバックスラッシュが消える。setterは結果を返さない。`update_field()`の戻り値は、`update_metadata()`と同じく、同じ値で書き込まなかったとき・フィルタが中止したとき・データベースのエラーのどれでも`false`になり、区別できないためである(エスケープハッチの`update()`はこの`false`をそのまま返す)。書き込みの失敗を例外で知る必要がある単純な値は、`MetaBag`(`#[UseMetaStorage(AcfMetaStorage::class)]`。§8.1)で読み書きする。
- array shape内のキーはフィールド名(snake_caseのまま。PHPの識別子の形でない名前(日本語のフィールド名等)は引用符で囲む: `'メモ': string`)。サブフィールドの型は本表を再帰適用した「getterの戻り値型」で、実行時もその型に正規化する(§6.5)。値を持たない型(message・tab・accordion)のサブフィールドはshapeから除外する。

`TMSWP\AcfBridge\AcfTypes`(@phpstan-type定義専用。runtimeメンバなし)。形はACFが実際に返す配列に合わせる。`AcfFile`・`AcfImage`の`url`は`wp_get_attachment_url()`が、`icon`は`wp_mime_type_icon()`が`false`を返しうる。`AcfImage`の`width`・`height`は、WordPressが画像の寸法を返せないとき(SVG等)に無く、`sizes`は空の配列になりうる。`AcfUserArray`の`user_avatar`は、`get_avatar()`が(アバターの表示を無効にしたサイト等で)`false`を返しうる。

```php
/**
 * @phpstan-type AcfImage array{ID: int, id: int, title: string, filename: string,
 *   filesize: int, url: string|false, link: string, alt: string, author: string,
 *   description: string, caption: string, name: string, status: string,
 *   uploaded_to: int, date: string, modified: string, menu_order: int,
 *   mime_type: string, type: string, subtype: string, icon: string|false,
 *   width?: int, height?: int, sizes: array<string, int|string>}
 * @phpstan-type AcfFile array{ID: int, id: int, title: string, filename: string,
 *   filesize: int, url: string|false, link: string, alt: string, author: string,
 *   description: string, caption: string, name: string, status: string,
 *   uploaded_to: int, date: string, modified: string, menu_order: int,
 *   mime_type: string, type: string, subtype: string, icon: string|false}
 * @phpstan-type AcfLink array{title: string, url: string, target: string}
 * @phpstan-type AcfChoice array{value: string, label: string}
 * @phpstan-type AcfUserArray array{ID: int, user_firstname: string, user_lastname: string,
 *   nickname: string, user_nicename: string, display_name: string, user_email: string,
 *   user_url: string, user_registered: string, user_description: string, user_avatar: string|false}
 * @phpstan-type AcfColorRgba array{red: int, green: int, blue: int, alpha: float}
 */
final class AcfTypes
{
    private function __construct() {}
}
```

生成クラスはこれらを `@phpstan-import-type X from \TMSWP\AcfBridge\AcfTypes` で取り込む。

## 6.2 マッピング表(全 31 型)

表のACF型の名前は34(Generatorの`KNOWN_FIELD_TYPES`と同じ)で、「全31型」は、そのうち値を持たない`message`・`tab`・`accordion`を除いた、値を持つ型の数である(`clone`を含む。cloneは§6.6のとおり参照先の型に展開する)。各型は設定の条件(rf・multiple・field_type等)の行に分かれる。packages/acf-bridge/tests/fixtures/acf-jsonは、全行と`message`・`tab`・`accordion`・未知の型を含み、その生成物(fixtures/expected-app)をPHPStan(level max)で解析する。

| ACF 型 | 設定条件 | getter 戻り値型(PHPDoc) | 未設定時 | setter 引数型 |
|---|---|---|---|---|
| text | — | `string` | `''` | `string` |
| textarea | — | `string` | `''` | `string` |
| email | — | `string` | `''` | `string` |
| url | — | `string` | `''` | `string` |
| password | — | `string` | `''` | `string` |
| wysiwyg | — | `string`(フィルタ適用済 HTML) | `''` | `string` |
| oembed | — | `string`(埋め込み HTML。raw の URL は `rawValue()` で取得) | `''` | `string`(URL) |
| number | — | `int\|float\|null` | `null` | `int\|float\|null` |
| range | — | `int\|float\|null` | `null` | `int\|float\|null` |
| true_false | — | `bool` | `false` | `bool` |
| select | multiple=0, rf=value | `string\|null` | `null` | `string\|null` |
| select | multiple=0, rf=label | `string\|null` | `null` | `string\|null`(保存は value) |
| select | multiple=0, rf=array | `AcfChoice\|null` | `null` | `string\|null` |
| select | multiple=1, rf=value | `list<string>` | `[]` | `list<string>` |
| select | multiple=1, rf=label | `list<string>` | `[]` | `list<string>` |
| select | multiple=1, rf=array | `list<AcfChoice>` | `[]` | `list<string>` |
| checkbox | rf=value / label | `list<string>` | `[]` | `list<string>` |
| checkbox | rf=array | `list<AcfChoice>` | `[]` | `list<string>` |
| radio | rf=value / label | `string\|null` | `null` | `string\|null` |
| radio | rf=array | `AcfChoice\|null` | `null` | `string\|null` |
| button_group | rf=value / label | `string\|null` | `null` | `string\|null` |
| button_group | rf=array | `AcfChoice\|null` | `null` | `string\|null` |
| image | rf=array | `AcfImage\|null` | `null` | `int\|null`(添付 ID) |
| image | rf=id | `int\|null` | `null` | `int\|null` |
| image | rf=url | `string\|null` | `null` | `int\|null` |
| file | rf=array | `AcfFile\|null` | `null` | `int\|null` |
| file | rf=id | `int\|null` | `null` | `int\|null` |
| file | rf=url | `string\|null` | `null` | `int\|null` |
| gallery | rf=array | `list<AcfImage>` | `[]` | `list<int>` |
| gallery | rf=id | `list<int>` | `[]` | `list<int>` |
| gallery | rf=url | `list<string>` | `[]` | `list<int>` |
| link | rf=array | `AcfLink\|null` | `null` | `AcfLink\|null` |
| link | rf=url | `string\|null` | `null` | `AcfLink\|null` |
| post_object | multiple=0, rf=object | `\WP_Post\|null` | `null` | `int\|null`(投稿 ID) |
| post_object | multiple=0, rf=id | `int\|null` | `null` | `int\|null` |
| post_object | multiple=1, rf=object | `list<\WP_Post>` | `[]` | `list<int>` |
| post_object | multiple=1, rf=id | `list<int>` | `[]` | `list<int>` |
| page_link | multiple=0 | `string\|null`(URL) | `null` | `int\|string\|null`(投稿 ID またはアーカイブ指定) |
| page_link | multiple=1 | `list<string>` | `[]` | `list<int\|string>` |
| relationship | rf=object | `list<\WP_Post>` | `[]` | `list<int>` |
| relationship | rf=id | `list<int>` | `[]` | `list<int>` |
| taxonomy | field_type∈{select,radio}, rf=object | `\WP_Term\|null` | `null` | `int\|null`(term ID) |
| taxonomy | field_type∈{select,radio}, rf=id | `int\|null` | `null` | `int\|null` |
| taxonomy | field_type∈{checkbox,multi_select}, rf=object | `list<\WP_Term>` | `[]` | `list<int>` |
| taxonomy | field_type∈{checkbox,multi_select}, rf=id | `list<int>` | `[]` | `list<int>` |
| user | multiple=0, rf=array | `AcfUserArray\|null` | `null` | `int\|null`(user ID) |
| user | multiple=0, rf=object | `\WP_User\|null` | `null` | `int\|null` |
| user | multiple=0, rf=id | `int\|null` | `null` | `int\|null` |
| user | multiple=1 | 上記の `list<>` | `[]` | `list<int>` |
| date_picker | — | `string`(return_format 適用) | `''` | `\DateTimeInterface\|string`(string は `Ymd`) |
| date_time_picker | — | `string`(return_format 適用) | `''` | `\DateTimeInterface\|string`(string は `Y-m-d H:i:s`) |
| time_picker | — | `string`(return_format 適用) | `''` | `\DateTimeInterface\|string`(string は `H:i:s`) |
| color_picker | rf=string(または rf 無し) | `string` | `''` | `string` |
| color_picker | rf=array(enable_opacity) | `AcfColorRgba\|null` | `null` | `string` |
| message / tab / accordion | — | (生成しない) | — | — |
| group | — | `array{...sub shapes...}\|null` | `null` | `array<string, mixed>\|null`(サブ名 → raw 値) |
| repeater | — | `list<array{...sub shapes...}>` | `[]` | `list<array<string, mixed>>`(行配列、サブ名 → raw 値) |
| flexible_content | — | §6.4 判別 union | `[]` | `list<array{acf_fc_layout: string, ...}>`(raw 値) |
| clone | — | §6.6 | — | — |
| 上記以外(google_map等の同梱型・サードパーティ型) | — | `mixed`(W005。サブフィールドも) | `null` | `mixed` |

- rf = return_format。acf-json に return_format が無い場合は ACF の既定値(image/file=`array`、select 系=`value`、link=`array`、post_object/relationship=`object`、taxonomy=`id`、user=`array`、color_picker=`string`)で解釈する。
- 未知型の拡張点: 生成時(CLI 実行時)に `apply_filters('tmswp/acf_bridge/type_map', array<string, TypeMapping> $map)` を適用し、サードパーティ型のマッピングを app/拡張側から追加できる。

```php
final readonly class TypeMapping
{
    public function __construct(
        public string $getterNativeType,  // 例: '?string', 'array', '?WP_Post'
        public string $getterDocType,     // 例: 'AcfImage|null', 'list<int>'。入れ子はサブフィールドのshapeを再帰で展開する
        public string $coercionHelper,    // getterが呼ぶAcfFieldAccessのヘルパ名(field keyで読む)。例: 'acfImage'
        public string $setterNativeType,  // 例: '?int'
        public string $setterDocType = '',
        public bool $dateTimeAccessor = false, // {camel}AsDateTime()を生成するか(§6.3)
        public string $dateRawFormat = '',     // 日付・時刻の保存形式。例: 'Ymd'
        public ?string $objectClass = null,    // acfObject・acfObjectListに渡す要素のクラスの参照。例: 'WP_Post'
    ) {}
}
```

- `TypeMapper::map()`は生成クラス1つ分の`ClassImports`を受け取り、型の表記の中のクラスは`ClassImports::ref()`の参照(useした短名か完全修飾名。§5.2)で、型の別名は`ClassImports::alias()`の名前で、組み立てるときに書く。getter側の型(getterNativeType・getterDocType・objectClass)とsetter側の型(setterNativeType・setterDocType)の参照は、別々の`ClassImports`に記録する。トップレベルのフィールドは両方に生成クラスの`ClassImports`を渡し、setterを生成しない入れ子のサブフィールドは、setter側に生成クラスのuseに記録しない`ClassImports`を渡す(出力しない型のuseと型の別名を取り込まないため)。shapeのキー(フィールドの名前)と`acf_fc_layout`のリテラル(レイアウトの名前)はそのまま書く。Generatorは型の項目をそのまま出力し、型の文字列を後から置き換えない(置き換えは、型の参照とキー・リテラルを区別できないため)。
- Generatorは、getterの呼び出しを`coercionHelper`と`objectClass`だけから決める(PHPDocの型の文字列から推測しない)。入れ子のサブフィールドは、名前に`Of`を付けた、値を受け取るヘルパ(`acfImage` → `acfImageOf`。§11)を呼ぶ。未知の型(`acfMixed`)のサブフィールドは値をそのまま使う。

## 6.3 日付系の追加アクセサ

date_picker / date_time_picker には return_format 適用済み string getter に加えて以下を生成する。

```php
public function {camel}AsDateTime(): ?\DateTimeImmutable;
```

- raw値(date_picker=`Ymd`、date_time_picker=`Y-m-d H:i:s`)を`wp_timezone()`で`\DateTimeImmutable::createFromFormat()`する。未設定は`null`。書式に合わないrawと、範囲外の値(`20241332`等。`createFromFormat()`は繰り上げて受け付けるため、書式に戻した文字列がrawと一致するかで判定する)は形の不一致(§6.1)として扱う。
- time_picker には生成しない(日付情報を持たないため)。
- setterは`\DateTimeInterface`を受けた場合、`wp_timezone()`の日時に変換してからraw形式(`Ymd` / `Y-m-d H:i:s` / `H:i:s`)へ変換して保存する。getterも`wp_timezone()`で読むため、別のタイムゾーンの`\DateTimeInterface`も同じ時刻として往復する。ただし次の例外を除く。
- ACFの保存形式は時差を持たない現地の時刻のため、夏時間の終わり等で同じ現地の時刻が2回来る間は、1つの現地の時刻が2つの瞬間を表す。この規則は、日付と時刻の両方を保存するdate_time_picker(rawの書式が`Y-m-d H:i:s`)だけに当てはめる。日付だけ(`Ymd`)・時刻だけ(`H:i:s`)の書式は瞬間を保存しないため、当てはめない。
  - 判定: 現地の時刻`W`について、`wp_timezone()`の`getTransitions()`で`W`の前後で使われるUTCからのオフセットを集め、オフセットごとに候補の瞬間を求めて`W`の書式に戻し、`W`と一致する瞬間を数える。2つあれば重複とし、タイムスタンプが小さい方を早い方とする。
  - setter: 入力の瞬間が重複の遅い方なら、黙って早い方を保存せず`InvalidArgumentException`を投げる。メッセージは、現地の時刻が曖昧なことと、入力した瞬間(UTC)を含む。早い方の瞬間と、重複でない時刻は保存する。
  - getter: 保存された`W`が重複なら、早い方の瞬間として返す(管理画面で入力された値も同じ)。そのため、setterが保存した瞬間は、つねに同じ瞬間として往復する。

## 6.4 flexible_content — レイアウト判別 union(確定方式)

判別タグは ACF が各行に必ず格納する **`acf_fc_layout`**(レイアウト name)を使う。レイアウトごとに `@phpstan-type` エイリアスをクラス docblock に生成し、getter はその union の `list<>` を返す。

レイアウト `hero`(sub: title=text, image=image rf=id)と `text_block`(sub: body=wysiwyg)を持つフィールド `sections` の生成例:

```php
/**
 * @phpstan-type Sections_Hero array{acf_fc_layout: 'hero', title: string, image: int|null}
 * @phpstan-type Sections_TextBlock array{acf_fc_layout: 'text_block', body: string}
 */
final class PageFields
{
    /** @return list<Sections_Hero|Sections_TextBlock> */
    public function sections(): array {}
}
```

- エイリアス名は `{Studly(fieldName)}_{Studly(layoutName)}`。クラス内で重複する場合は E002。
- `acf_fc_layout` はリテラル文字列型で生成するため、PHPStan は `match ($row['acf_fc_layout'])` で各レイアウトの shape にナローイングできる。利用例とこのイディオムを rules/ に記載する。
- flexible 内の repeater / group / flexible の入れ子は shape を再帰展開する。展開深度に制限は設けない(acf-json の構造上有限)。
- 実行時は、各行を`acf_fc_layout`のレイアウトの正規化の関数で、サブフィールドの型どおりに正規化する(§6.5)。
- レイアウトが無いflexible_contentは、getterの型を`list<never>`にし、`acfEmptyLayouts()`(入れ子は`acfEmptyLayoutsOf()`)で読む。未設定は`[]`で、行はどれもレイアウトに無い行として形の不一致にする(本番は記録して除き、`[]`を返す)。

## 6.5 group・repeater・flexible_contentの正規化(入れ子を含む)

- 型: groupは`array{sub1: T1, ...}|null`、repeaterは`list<array{sub1: T1, ...}>`、flexible_contentは§6.4のレイアウトのshapeのunionの`list<>`。Tnは各サブフィールドに本表を再帰適用したgetterの型。
- 実行時: ジェネレータが、サブフィールドごとの正規化の呼び出しを生成し、宣言した型どおりに全階層を正規化・検査する。生成コードは、traitの値を受け取るヘルパ(`acfStringOf($value, $path)`等。§11)をサブフィールドの値(`$row['名前'] ?? null`)と位置で呼ぶ関数を、`acfGroup()`・`acfRows()`・`acfLayouts()`(入れ子は`acfGroupOf()`・`acfRowsOf()`・`acfLayoutsOf()`)に渡す。PHPStanは、その関数の戻り値の型をgetterの`@return`と照合する。

```php
/** @return list<array{acf_fc_layout: 'hero', title: string, buttons: list<array{link: AcfLink|null}>}|array{acf_fc_layout: 'text_block', body: string}> */
public function sections(): array
{
    return $this->acfLayouts(self::KEY_SECTIONS, [
        'hero' => fn(array $row, string $path): array => [
            'acf_fc_layout' => 'hero',
            'title' => $this->acfStringOf($row['title'] ?? null, $path . '.title'),
            'buttons' => $this->acfRowsOf($row['buttons'] ?? null, $path . '.buttons', fn(array $row, string $path): array => [
                'link' => $this->acfLinkOf($row['link'] ?? null, $path . '.link'),
            ]),
        ],
        'text_block' => fn(array $row, string $path): array => [
            'acf_fc_layout' => 'text_block',
            'body' => $this->acfStringOf($row['body'] ?? null, $path . '.body'),
        ],
    ]);
}
```

- 未設定と形の不一致は、トップレベルと同じ規則(§6.1)をサブフィールドごとに当てはめる。
  - group: 未設定(`null`・`false`・`''`・空の配列)は`null`。文字列のキーの配列でない値は形の不一致。一度も保存していないgroupは、ACFがサブフィールドの未設定の値の配列を返すため、各サブフィールドの未設定の値の配列になる(保存の有無は`has*()`で判定する)。
  - repeater・flexible_content: 未設定は`[]`。listでない値は形の不一致(本番は`[]`)。行は要素ごとに検査し、文字列のキーの配列でない行と、flexible_contentの`acf_fc_layout`が無い・レイアウトに無い行は、形の不一致として、本番では記録してその行だけを除く。
  - サブフィールド: サブフィールドの型の未設定の値に正規化する。行やgroupに無いキーは未設定とみなし、宣言していないキーは返さない。形の不一致は、本番では記録してそのサブフィールドを未設定の値にし(listの型は不正な要素だけを除く)、ほかのサブフィールドと行は返す。
- 例外とログの位置は、どの階層のどのフィールドかを示す: `group_value.title`、`stores[0].shop_name`、`sections[1].acf_fc_layout`、`sections[0].buttons[2].link`。
- 正規化はACFに触れない値を受け取るヘルパで行うため、ACF Proの型(repeater・flexible_content・gallery)の正規化も、無料版ACFのCIでユニットテストで確かめる(packages/acf-bridge/tests/Unit/AcfFieldAccessTest)。
- setter は raw 値の配列を受ける(shape は `array<string, mixed>` ベースの緩い型。厳密な raw shape 注釈は生成しない。複雑な一括更新はエスケープハッチ `update()` を直接使う想定)。

## 6.6 clone の解決(確定方式)

clone はフィールド/グループへの参照(`clone` 配列に field key / group key)であり、**生成時に静的解決して展開する**。

1. 参照先解決: 参照値が `group_` 始まりならそのグループの全フィールド、`field_` 始まりなら当該フィールド単体。acf-json 全体(対象外グループ含む)から key で検索する。見つからなければ E004。
2. `display: 'seamless'` → 参照先フィールドをホストグループの**トップレベル相当**として展開し、それぞれ個別のアクセサ・定数を生成する。`prefix_name: 1` の場合はフィールド名に `{cloneFieldName}_` プレフィクスを付けた名前で生成する(ACF の保存名と一致させる)。
3. `display: 'group'` → group 型と同じ扱い(`array{...}|null`、shape は参照先フィールドから構成。`prefix_name` は ACF 仕様上 seamless のみ有効のため無視)。
4. 解決グラフに循環(A が B を clone し B が A を clone)があれば E005。
5. clone がサブフィールド(repeater 内等)にある場合も同規則で再帰展開する。

---

# 7. 実行時連携 — Manifest / register_*_meta スキップ / 重複検出

## 7.1 `AcfManifest`(sync 生成物)/ `ManagedKey`

実行時に「どのメタキーが ACF 管理下か」を得るためのマニフェスト。acf-json の純関数である点は他の生成クラスと同じであるため、ライフサイクルも同一に揃え、`wp tmswp acf sync` の生成物としてコミットする(実行時の acf-json ディレクトリ走査・自前 FS キャッシュは持たない)。

```php
// パッケージ側 DTO(TMSWP\AcfBridge\Manifest\ManagedKey)
final readonly class ManagedKey
{
    public function __construct(
        public ?ObjectType $objectType,  // TMSWP\Core\Meta\ObjectType。null = options
        public string $objectSubtype,    // post_type名 / taxonomy名('all'可) / ''(user) / menu_slug(options)
        public string $metaKey,          // トップレベルのフィールド name
        public string $fieldKey,         // field_xxx
        public string $groupKey,         // group_xxx
    ) {}
}
```

```php
// sync 生成物: {outputDir}/AcfManifest.php(@generated。名前空間は BridgeConfig::$namespace)
final class AcfManifest
{
    /** @return list<\TMSWP\AcfBridge\Manifest\ManagedKey> */
    public static function managedKeys(): array;
}
```

- 対象は**トップレベルのフィールド名のみ**(repeater/group のフラット化キー `parent_0_sub` や ACF の参照メタ `_fieldname` は含めない。`register_*_meta`/`#[MetaSchema]` と衝突しうるのはトップレベル名だけであるため)。location 解決は §4 の `LocationResolver` を再利用する(W系はサイレントにスキップ)。
- `AcfManifest.php`は§5.2の生成規約(`@generated`のヘッダ・期待ファイル集合・孤児削除・`check`のバイト一致比較・決定的出力=グループのkeyの昇順)に従う。ヘッダには全入力の集約ハッシュ`source-hash: sha256:...`(§3.3の`sourceHash`)を、`@generated`の行の次の1か所だけに記録する(集約ハッシュを持つ唯一の生成ファイル。§5.2)。`sources`には、管理キーを持つグループの由来のJSONファイルを記す。

```php
/**
 * AcfManifest — ACF managed keys.
 *
 * @generated by tmswp/acf-bridge
 * source-hash: sha256:91a67cee51e382278a58d9ce5bbdfbca3e2d3877f2d37f8d625e7b60743defb7
 *
 * このファイルは自動生成。手で編集しないこと(次回syncで上書きされる)。
 * 再生成: wp tmswp acf sync (composer acf:sync)
 *
 * sources:
 *   group_66f01a2b3c4d5.json (製品情報)
 */
```
- 鮮度保証は「acf-json と生成コード(`AcfManifest.php` 含む)の同一コミット規約(§1.3)+ CI の `check --strict`(§9.3)」に一本化する。実行時の mtime 検証・キャッシュ無効化・書込不可フォールバックは存在せず、composer のクラスローダ + OPcache だけで完結する(読み取り専用デプロイ・マルチサーバ構成でも追加の考慮が不要)。
- 整合性モデル: ACF 管理画面でのフィールド編集直後・sync 前は実行時マークが旧状態となるが、これは生成アクセサクラスと同一の前提(sync 必須)であり、全派生物が同じライフサイクルに揃う。

## 7.2 register_*_meta 発行スキップ連携

コアの`MetaSchemaRegistry`(Meta/Option定義層文書)は`#[MetaSchema]`宣言をKernelのbootの中で集め、`init` priority 20で`register_post_meta`/`register_term_meta`/`register_meta('user', ...)`へ流し込む(Kernel設計書§7)。本拡張は、登録したKernelの **`init` priority 6**(`acf/init` = priority 5の直後)で、そのKernelのレジストリに以下を実行する(`AcfBridge::markManagedKeys()`)。

```
$manifestClass = $config->namespace . '\\AcfManifest';
if (!class_exists($manifestClass)) {
    WP-CLIなら警告を出してreturn、それ以外はAcfBridgeRuntimeException
}
$keys = $manifestClass::managedKeys();  // ManagedKey以外の要素があればAcfBridgeRuntimeException

foreach ($keys as $key) {
    if ($key->objectType === null) continue;  // optionsはメタではないためコアregistryの対象外
    $origin = "ACF field group '{$key->groupKey}'";
    if ($key->objectSubtype === 'all') {      // ACF の location の 'all'(taxonomy==all 等)は全サブタイプ
        $registry->markExternallyManagedInAllSubtypes($key->objectType, $key->metaKey, AcfMetaStorage::class, $origin);
        continue;
    }
    $registry->markExternallyManaged($key->objectType, $key->objectSubtype, $key->metaKey, AcfMetaStorage::class, $origin);
}
```

- `AcfManifest`クラスが無い(sync未実行、または生成物のデプロイ漏れ)ことは設定の誤りであり、debugに関係なく`AcfBridgeRuntimeException`にする(文書00 §5.6)。黙ってマーク無しに縮退すると、重複の検出が働かないまま動くためである。ただしWP-CLIでは、生成物を作る`wp tmswp acf sync`自体がWordPressの読み込み(`init`)を経て動くため、例外にするとsyncを実行できない。WP-CLIに限り`WP_CLI::warning()`で警告して申告しない(黙らない)。
- `managedKeys()`が`ManagedKey`以外の要素を返した場合も、黙って飛ばさず`AcfBridgeRuntimeException`にする(生成物の改変。syncで作り直す)。
- 申告はACFの有効・無効に関係なく行う。申告に使うのはコミット済みの生成物`AcfManifest`だけで、ACFの実行時に依存しないためである(ACFが無効なときの値へのアクセスは§10の`AcfNotActiveException`で防護される)。`function_exists('acf')`で申告を飛ばす経路は持たない。

本文書がコア`MetaSchemaRegistry`に要求するAPI(正準シグネチャはMeta/Option定義層文書4.9):

```php
public function markExternallyManaged(ObjectType $objectType, string $subtype, string $key, string $storageClass, string $origin): void;
public function markExternallyManagedInAllSubtypes(ObjectType $objectType, string $key, string $storageClass, string $origin): void;
public function isExternallyManaged(ObjectType $objectType, string $subtype, string $key): bool;
```

- `$storageClass`はそのキーを読み書きするストレージ(本拡張は`AcfMetaStorage::class`)、`$origin`は重複の例外のメッセージに使う申告元の説明(本拡張は`ACF field group '{groupKey}'`)。
- 申告したキーのうち`#[MetaSchema]`の宣言が無いものについて、registryは`register_*_meta`を**発行しない**(DR-06)。`#[UseMetaStorage(AcfMetaStorage::class)]`のエンティティが宣言したキーは、宣言どおり`register_*_meta`される(型・既定値・sanitize・`show_in_rest`・リビジョンは宣言に従う。§7.3)。
- `ManagedKey::$objectSubtype` が ACF の location の値 `'all'`(taxonomy==all 等)の場合は、本拡張が `markExternallyManagedInAllSubtypes()` で当該 ObjectType の全サブタイプに対するマークとして申告する。コアの `markExternallyManaged()` の `objectSubtype` は文字列どおりの1サブタイプだけを表し、`'all'` を特別扱いしない。
- 申告先は登録したKernelのレジストリだけである。テーマとプラグインがそれぞれ登録すれば、それぞれの`AcfManifest`をそれぞれのレジストリに申告する。

## 7.3 `#[MetaSchema]` との同一キー重複検出(エラー)

ACF管理下キーは、`#[MetaSchema]`と`#[UseMetaStorage(AcfMetaStorage::class)]`で宣言してよい(文書04「メタ・設定の使い分け」)。読み書きの保存先が、ACFのフィールドの保存先(値のメタと参照のメタ)の1つに揃うためである。一方、同一`(ObjectType, objectSubtype, metaKey)`を、読み書きのストレージが`AcfMetaStorage`でないエンティティ(`#[UseMetaStorage]`の無いエンティティ等)が`#[MetaSchema]`で宣言している状態は**構成エラー**であり、フェイルファストする(DR-06)。旧FWの「ACFとコアのメタ層が同じキーを別経路で読み書きして挙動不明」という事故の再発防止が目的。ストレージは`#[UseMetaStorage]`の指定(無ければ既定のストレージ)をContainerで解決したインスタンスのクラスで比べる。

- **実行時**: `MetaSchemaRegistry`は、`#[MetaSchema]`の宣言と外部管理の申告(`markExternallyManaged`・`markExternallyManagedInAllSubtypes`)のうち後から行われた側の時点で、同じキーをストレージの違うエンティティが宣言していないかを検査し、あれば`DuplicateMetaKeyException`(コア例外。Meta/Option文書定義)を投げる。宣言はKernelのbootの中で揃うため、本拡張が`init` 6で申告した時点で検出され、`register_*_meta`(`init` 20)には進まない。この検査は、AcfBridgeを登録したKernelのレジストリの中だけで行う(申告先がそのレジストリだけのため。§7.2)。テーマの`#[MetaSchema]`とプラグインのフィールドグループのような、Kernelをまたぐ重複は実行時には検出しない。
- **静的(CI)**: `wp tmswp acf check`も同じ検査を行い、検出時はE101・exit 2で報告する(実行時例外より早くCIで気づける。`sync`はE101があれば書き込まずにexit 1)。管理キーは、acf-jsonのグループのcloneを解決してから`ManagedKeyCollector`で集める(Generatorが生成する`AcfManifest`の行と同じキー。seamlessのcloneが展開したキーも含む)。判定は、`--app=`で選んだKernelだけでなく起動中の全Kernelの`MetaSchemaRegistry`の複製に実行時と同じ申告(`AcfBridge::markManagedKey()`)を行い、その`DuplicateMetaKeyException`のメッセージを`E101: `に続けて出す。複製に申告するため、起動中のレジストリは変えない。全Kernelと照合するため、実行時には検出しないKernelをまたぐ重複もE101で検出する。
- 例外のメッセージ(E101も同じ)は、衝突したキー・(ObjectType, サブタイプ)・宣言クラスと`#[MetaSchema]`の定数名・ACFのフィールドグループのkey・両方のストレージのクラスと、次の解消方法を含む。(a)エンティティに`#[UseMetaStorage(AcfMetaStorage::class)]`を付けてACFの保存規約に揃える、(b) `#[MetaSchema]`定数を削除して生成アクセサ(`XxxFields`)へ一本化する、(c) acf-jsonから当該フィールドを削除してPHP宣言へ一本化する。例:

```
externally managed meta key 'price' for post:product is already declared by App\Post\ProductPost::PRICE with meta storage TMSWP\Core\Meta\WpMetaStorage; ACF field group 'group_product' manages it with meta storage TMSWP\AcfBridge\AcfMetaStorage. Add #[UseMetaStorage(\TMSWP\AcfBridge\AcfMetaStorage::class)] to App\Post\ProductPost, remove the #[MetaSchema] constant PRICE, or remove the key from ACF field group 'group_product'
```

---

# 8. AcfMetaStorage と #[UseMetaStorage] / プレビュー連携

## 8.1 `TMSWP\AcfBridge\AcfMetaStorage`

コア `MetaStorageInterface` の実装。`MetaBag` の読み書きを ACF へ委譲する。本文書は下記シグネチャを前提とする(正準は Meta/Option 定義層文書。差異があればそちらが優先)。

```php
final class AcfMetaStorage implements \TMSWP\Core\Meta\MetaStorageInterface
{
    /** Containerが解決したインスタンスは、そのContainerのKernelを受け取る(WpMetaStorageと同じ) */
    public function __construct(\TMSWP\Core\Kernel\Kernel $kernel);

    public function has(ObjectType $objectType, int $objectId, string $key): bool;
    public function get(ObjectType $objectType, int $objectId, string $key): mixed;
    public function getAll(ObjectType $objectType, int $objectId, string $key): array;
    public function getMultiple(ObjectType $objectType, int $objectId, array $keys): array;
    public function set(ObjectType $objectType, int $objectId, string $key, mixed $value): bool;
    public function setAll(ObjectType $objectType, int $objectId, string $key, array $values): bool;
    public function delete(ObjectType $objectType, int $objectId, string $key): bool;
}
```

振る舞い仕様:

- IDの検査: 全メソッドは、冒頭で対象のIDをコアの`ObjectType::resolveId()`で検査し、1未満のIDを`InvalidArgumentException`(`TMSWP\Core\Exception\InvalidArgumentException`。メッセージは`meta object ID must be a positive integer`)にする。ACFはpost_idの0を現在のループの投稿に置き換え、WordPressのメタの関数は負のIDを`absint()`で別のIDにするため、呼び出しの対象でないものを読み書きしないようにする。生成クラスの`of()`(§5.3)と同じ検査である。
- ACF id 変換: `Post` → `(int) $objectId`、`Term` → `"term_{$objectId}"`、`User` → `"user_{$objectId}"`。
- 読み書きは`WpMetaStorage`(Meta/Option文書 §4.6)と同じ意味で、メタの行を直接読み書きする(ACFのフィールドのキーの扱いは下の項)。内部で、コンストラクタのKernelを渡した`WpMetaStorage`に委ねる。単独の読み取りのデータベースのエラーの扱い(debugは例外、本番はKernelのロガーに記録して行が無いときと同じ結果)も`WpMetaStorage`と同じである。
  - `has()`はメタの行の有無で判定する。ACFのフィールドの`default_value`と、`#[MetaSchema]`の既定値(§8.2のとおり宣言したキーは`register_*_meta`され、WordPressの生の読み出しも既定値を返す)は「ある」としない。`get_field()`は値が無いとき`default_value`を返すため、`get_field() !== null`では判定しない。
  - `get()`は保存された値(メタの先頭の行。raw)を返し、行が無ければ`null`。理由: `MetaBag`の型付きアクセサ(`int()`/`string()`等)はDB保存型を前提としており、フォーマット済み値(imageのarray等)は`MetaBag`の型コントラクトを壊す。フォーマット済み値が必要な場面では生成アクセサクラス(`XxxFields`)を使う。この役割分担をrules/に明記する。**`get()`と`has()`は分離されており、`null`をセンチネルに使うフォールスルーは存在しない**(DR-18)。
  - `getAll()`は全行のlist(複数選択の配列も1行の値として返し、スカラーを包まない)。`setAll()`は全行を置き換える(ACFのフィールドのキーには使えない。下の`setAll()`の項)。
- 書き込みの戻り値と例外はコアの`MetaStorageInterface`の契約(Meta/Option文書 §4.5: 書き込みは「書き込んだか」をboolで返し、書き込まなかった場合(同じ値・フィルタによる中止・`delete()`で行が無い)はfalse、データベースのエラー等の書き込みの失敗は例外、`null`は保存しない)に従う。受け取った値を元の値として保存する(バックスラッシュを含む文字列が往復する)。packages/core/tests/Supportの契約テスト(`MetaStorageContractTestCase`)を、実物のACFの統合テスト(`AcfMetaStorageContractTest`)で通す。
- ACFの値の書き込みのAPI(`update_field()`・`delete_field()`・`acf_delete_value()`)は呼ばない。そのため、値の書き込みのフィルタとアクション(`acf/update_value`・`acf/pre_update_metadata`・`acf/pre_delete_metadata`・`acf/delete_value`等)とACFのpost_idの解決(`acf/validate_post_id`)は通らず、書き込みとフィールドの選択はつねに呼び出しの対象(ObjectTypeとID)について行う。ACFの値の書き込みの処理の中から`AcfMetaStorage`を呼んでも、`AcfMetaStorage`の中からACFの値の書き込みの処理が呼ばれることはない。ACFを呼ぶのは、フィールドの定義の取得(`acf_get_field()`・`acf_is_field_key()`)と、値のキャッシュの消去(`acf_flush_value_cache()`)、添付ファイルの結び付けのフィルタ(`acf/connect_attachment_to_post`。下の表のimage・file)だけである。
- ACFのフィールドのキーは、ACFが値をフィールドとして読めるように、`update_field()`と同じ形で参照のメタを扱う。フィールドは、呼び出しの対象の参照のメタ(`_{キー}`)を`WpMetaStorage`で読み、それがfield keyなら`acf_get_field(そのkey)`で、参照が無いか解決しなければ`acf_get_field(キー)`(名前で探す。post_idを使わない)で選ぶ(`acf_maybe_get_field()`の参照と名前による探索と同じ順だが、`acf/validate_post_id`による解決を通さない)。どちらも、名前がキーと同じフィールドだけをACFのフィールドとする。`set()`・`delete()`・`setAll()`は同じ選び方を使う。キーはつねにメタの名前として扱い、field keyの形(`field_...`)のキーは、ACFが別の名前のフィールドに解決するため、ACFのフィールドとせず、そのキーのメタとして読み書きする。
  - `set()`: 値を`WpMetaStorage`で書き、続けて参照のメタ(`_{キー}`)にフィールドのkeyを書く。戻り値は値の書き込みの結果。
  - `delete()`: 値の行を`WpMetaStorage`で消し、値の行を消したときだけ参照のメタも消す。行が無ければ何もせず`false`。
  - `setAll()`: ACFのフィールドは値を1つだけ持つため、ACFのフィールドのキーへの`setAll()`は、フィールドの型にかかわらず設定の誤りとして`AcfBridgeRuntimeException`にする。メッセージは、キーと、値を1つだけ持つため`setAll()`を使えないことと、`set()`を使うこと(`#[MetaSchema(single: false)]`で宣言していれば、値を1つの宣言に直すこと)を含む。ACFのフィールドでないキーは、`WpMetaStorage`と同じく行を置き換える。
  - フィールドを選ぶための参照のメタの読み取り(`set()`・`delete()`・`setAll()`の中)は、`WpMetaStorage`の書き込みの中の読み取り(Meta/Option文書 §4.6)と同じく、データベースのエラーをdebugにかかわらず`RuntimeException`にし、値と参照のメタを変える前に止める。失敗した読み込みでWPが書いたメタのキャッシュも同じく消す。公開の読み取り(`has()`等)のように、本番で記録して行が無いときと同じ結果にはしない(参照が無いときの名前による探索へ進み、別のフィールドで書くのを防ぐため)。
  - どの書き込みの後も(例外になった場合も)、ACFの値のキャッシュから、呼び出しの対象のそのキーを消す(`get_field()`が古い値を返さないように)。
- ACFのフィルタを通さないため、`set()`・`delete()`で扱えるACFのフィールドは、保存した形が値そのもので、ACFの`update_value`が値を変えない型(`AcfMetaStorage::SUPPORTED_FIELD_TYPES`)に限る。ほかの型のフィールドのキーの`set()`・`delete()`は、設定の誤り(文書00 §5.6)として`AcfBridgeRuntimeException`にする。メッセージは、キー・フィールドのkeyと型・扱える型の一覧を含む。読み取り(`has()`・`get()`・`getAll()`・`getMultiple()`)では型を検査しない。型ごとの根拠(ACF 6.2.0と6.8.10の各フィールドの型の`update_value`)は次の表のとおり。

| 型 | `update_value` | 扱い |
|---|---|---|
| text・textarea・email・url・password・wysiwyg・oembed・true_false・button_group・date_picker・time_picker・date_time_picker・color_picker | 無い(6.2.0・6.8.10とも) | 扱う |
| number・range(rangeはnumberを継承) | 値が空でなく`,`を含めば`,`を取り除く | 扱う。`,`を含む文字列の値は、ACFと違う形で保存しないよう`InvalidArgumentException`にする |
| select | `multiple`の設定にかかわらず、空でない配列の各要素を`strval`し、6.8.10は`save_options`のとき配列の値を選択肢に足す。スカラーは変えない | `multiple`でないものだけ扱う。配列の値(空の配列を含む)は`InvalidArgumentException`にし、ほかの値は共通のメタの規約(Meta/Option文書 §4.5)に従う |
| radio | `save_other_choice`のとき、選択肢に無い値を`wp_unslash()`・`sanitize_text_field()`して選択肢に足す | `save_other_choice`でないものだけ扱う |
| image・file(imageはfileの`update_value`を呼ぶ) | 値が空でなければ`acf_idval()`で添付ファイルのIDにし、`acf_connect_attachment_to_post()`で、`post_parent`が0の添付ファイルを投稿に結び付ける(post_idが数値のとき、すなわち投稿のときだけ)。削除の処理(`delete_value`)は無い | 単一IDのintだけ扱う。正の整数(int)でない値(数字の文字列・0・負の数・配列等)は`InvalidArgumentException`にする。投稿では、値が変わらないときも、`acf_connect_attachment_to_post()`と同じ処理で添付ファイルを結び付ける。`acf/connect_attachment_to_post`のフィルタが偽を返したら結び付けない(正常な中止で、例外にしない)。添付ファイルで`post_parent`が0のときだけ`wp_update_post(['ID' => …, 'post_parent' => 投稿のID], true)`を呼び、`WP_Error`が返れば書き込みの失敗として`RuntimeException`にする(ACFの関数は`wp_update_post()`の失敗を知らせないため、ACFの関数は呼ばない)。タームとユーザーでは結び付けない |
| checkbox・gallery・link・post_object・page_link・relationship・taxonomy・user・group等 | 値を変える、または参照先に書く | 扱わない(生成アクセサで読み書きする) |

- 全メソッドは、IDの検査の次に`function_exists('get_field')`を検査し、偽なら`AcfNotActiveException`を投げる(§10)。
- 旧 FW の `iwf2b/meta/get` グローバルフィルタ委譲(全エンティティ一括・null センチネル・実際には配線バグで不動作)は採用しない。適用は次項のエンティティ単位オプトインのみ。

## 8.2 `#[UseMetaStorage]` による適用

コアの属性 `TMSWP\Core\Attribute\UseMetaStorage`(storage 実装の class-string を受け取り、コンテナ経由で解決される。正準は Meta/Option 文書)をエンティティクラスに付与する。

```php
use TMSWP\AcfBridge\AcfMetaStorage;

#[AsPostType]
#[UseMetaStorage(AcfMetaStorage::class)]
final class ProductPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')->label('製品')->labelLanguage(LabelLanguage::Ja)->hasArchive();
    }
}
```

- これにより `ProductPost::meta($post)` の `MetaBag` が `AcfMetaStorage` 経由になる。
- `#[UseMetaStorage(AcfMetaStorage::class)]`のエンティティは、ACF管理下のキー(ACFのフィールドグループのフィールド)も`#[MetaSchema]`定数で宣言できる。本拡張の申告(§7.2)とストレージが同じため重複エラーにならず(§7.3)、宣言どおり`register_*_meta`される。`#[UseMetaStorage]`の無いエンティティ(既定の`WpMetaStorage`)が同じキーを宣言すると重複エラーになる。
- 使い分けは文書04「メタ・設定の使い分け」(文書12 §1.9)に従う。単純な値(文字列・数値・真偽・日付・単一画像ID等)は、ACFの画面で編集する場合も`#[MetaSchema]`で宣言し、エンティティに`#[UseMetaStorage(AcfMetaStorage::class)]`を付けて、アプリは`MetaBag`だけで読み書きする(ACFに完全には依存させない)。ACF固有の複合型(repeater・flexible_content・group・gallery・relationship)は生成アクセサ(`XxxFields`)で扱う。同じキーを`MetaBag`と生成アクセサの両方から読み書きしない。
- `#[MetaSchema]`で宣言できるACFのフィールドのキーは、単純な値の型(§8.1の`AcfMetaStorage::SUPPORTED_FIELD_TYPES`)のフィールドだけである。ほかの型(gallery・relationship・group等)のフィールドは、生成アクセサで読み書きする。

## 8.3 `PreviewAcfSupport`(プレビュー時 ACF 値保存・オプトイン)

旧 FW `AbstractHook::save_preview_post` + `acf_save_preview_postmeta` の後継。コアの `#[WithMetaRevisions]`(Meta/Option 文書 §4.11)は WP 6.4 の `register_post_meta(..., ['revisions_enabled' => true])` に全面委譲する有効化レイヤであり、**FW 独自のフックを一切発火しない**。ACF 管理下キーはそもそも `register_post_meta` されない(§7.2)ためこのコア経路には乗らず、Meta/Option 文書も「ACF フィールドのプレビュー対応は acf-bridge 側の責務」と明記している。よって本クラスはコアの tmswp/* アクションに依存せず、WP 標準フックへ直接登録する**acf-bridge 完結**の実装とする。

```php
final class PreviewAcfSupport
{
    public static function register(): void;
}
```

- `register()` は `add_action('wp_insert_post', ...)` でコールバックを登録する(旧 FW と同一の契機)。プレビュー時の WP のオートセーブ/リビジョン保存は `wp_create_post_autosave()` → `_wp_put_post_revision()` → `wp_insert_post()` を経由するため、このフックでリビジョン保存を検知できる。コアの `#[WithMetaRevisions]` の有無とは独立に動作する(両者は対象キーが重複しない補完関係: コア = `#[MetaSchema]` 登録キー、本クラス = ACF 管理下キー)。
- コールバック擬似手順(引数 `int $postId`)。判定順序を変えないこと — リビジョン判定を nonce 検証より先に行うのは、通常の投稿保存経路で ACF 自身の nonce を消費しない(`acf_verify_nonce` は成功時に nonce を無効化するため、ACF 本体の `save_post` 処理と競合させない)ためである。
  1. `$parentId = wp_is_post_revision($postId)` が false なら return(リビジョン保存以外は対象外。下書きプレビューは投稿本体が保存され ACF の通常 `save_post` 処理が値を保存するため、本クラスの関与は不要)。
  2. `$_POST['acf']` が非空配列でなければ return(ブロックエディタの REST オートセーブ等、ACF 入力を含まないリクエストはここで抜ける)。
  3. `function_exists('acf_verify_nonce')` かつ `acf_verify_nonce('post') === true` でなければ return(ACF がメタボックスに出力する `_acf_nonce` の検証。ACF 不在時もここで抜ける)。
  4. `current_user_can('edit_post', $parentId)` でなければ return(防御的な権限再確認)。
  5. `function_exists('acf_update_values')` なら `acf_update_values((array) wp_unslash($_POST['acf']), $postId)` を実行。不在なら何もしない(プレビュー経路で例外を投げない)。
- セキュリティ前提: WP のプレビュー/オートセーブ保存経路(`wp-admin/post.php` の preview アクション、REST オートセーブ)は `wp_insert_post` 到達前に nonce と `edit_post` capability を検証済みである。ただし `wp_insert_post` アクションは任意のコードからも発火しうるため、本コールバックは手順 3(nonce)・手順 4(capability)の検証を**自身でも必ず**行う(多層防御)。検証を省略して `$_POST['acf']` をリビジョンへ書き込む実装は不可。
- 有効化は `BridgeConfig::$previewMeta = true` のみ(既定 false)。

---

# 9. ドリフト検知と CI(wp tmswp acf check)

## 9.1 check のアルゴリズム

```
1. --app=(省略時はAcfBridgeを登録した唯一のKernel)のAcfBridgeの構成を取得(対象が決まらない・引数の誤り → exit 2。§5.1)
2. AcfJsonRepositoryで全グループ読込(構文エラー → E102, exit 2)。オプションページのpost_idは§5.4(食い違い → E102, exit 2)
3. Generator::generate() をメモリ上で実行(E* → exit 2)
4. §7.3のMetaSchema重複検査。起動中の全Kernelの宣言と照合する(E101 → exit 2)
5. 期待ファイル集合 vs outputDir 実ファイルを比較:
   a. 期待にあるが実在しない → 「missing」
   b. 内容がバイト不一致       → 「modified」(統一diffの先頭40行を表示)
   c. 実在し @generated マーカー付きだが期待に無い → 「orphan」
6. a/b/c がひとつでもあれば exit 1。
   出力末尾に「Run: wp tmswp acf sync (composer acf:sync)」を表示。
7. --strict 指定時は W* が 1 件でもあれば exit 1(理由一覧を表示)。
8. 差分・警告なし → 「ACF bridge is in sync. (N files, source-hash sha256:...)」exit 0
```

## 9.2 sync のアルゴリズム

```
1〜4. check と同じ(E* があれば一切書き込まず exit 1、環境エラーは exit 2)
5. --dry-run なら check 同様の差分表示のみで終了(差分有無に関わらず exit 0)
6. outputDir を作成し、期待ファイル(AcfManifest.php 含む)を全書き込み(tmp + rename のアトミック書込)
7. 孤児(@generated マーカー付きで期待集合に無いファイル)を削除
8. 書込/削除したファイル一覧と W*/I* を表示し exit 0
```

## 9.3 CI 組み込み

QA 文書の GitHub Actions に以下のステップを追加する(acf-json を持つプロジェクト/スターターで有効化)。例は wp-env を使うプロジェクトのもので、§5.1 のとおり `@wordpress/env` を package.json で版を固定し、`npm ci` で入れておく。

```yaml
- name: ACF drift check
  run: node node_modules/@wordpress/env/bin/wp-env run cli wp tmswp acf check --strict
```

- monorepo 側(acf-bridge パッケージ自体)の CI は、フィクスチャ acf-json を使った Generator のスナップショットテストで同等の検証を行う(wp-env 不要のユニット層)。
- 運用規約(rules/ に記載): 「acf-json の変更と `app/Acf/` の再生成(`AcfManifest.php` を含む)は同一コミットに含める。`check` が落ちた PR はマージ禁止」。ACF 管理下キーの実行時マーク(§7)の鮮度保証もこの規約と `check --strict` に一本化される。

---

# 10. ACF 未インストール環境での挙動(明確なエラー)

| 状況 | 挙動 |
|---|---|
| `AcfBridge::register()`(Kernelのboot) | 成功する(フック登録のみ)。ACF不在を理由にbootを失敗させない |
| 生成クラスのアクセサ呼び出し(`ProductFields::of($post)->price()` 等) | `AcfNotActiveException` を投げる |
| `AcfMetaStorage` の全メソッド | `AcfNotActiveException` を投げる |
| Manifest連携(§7.2) | ACFが有効なときと同じく申告する(生成物`AcfManifest`だけで決まり、ACFの実行時に依存しないため)。重複は§7.3の例外になる。`AcfManifest`が無いことはACFの有無に関係なく設定の誤り(WP-CLIを除き`AcfBridgeRuntimeException`。§7.2) |
| `wp tmswp acf sync` / `check` | 実行可能(acf-jsonと`BridgeConfig`だけから生成し、ACFの実行時に問い合わせない。§5.4) |
| `PreviewAcfSupport` | 何もしない(フック登録はされるが、コールバックが §8.3 手順 3 の `function_exists('acf_verify_nonce')` で早期 return) |

登録・申告・生成と、実行時の値の読み書きの扱いは、文書00 §5.6のエラー方針に次のとおり当てはめる。

- ACFが無効であることは、それだけでは設定の誤りとしない。ACFの更新や一時的な無効化で、ACFの値を使わないページまで止めないためである。登録(§2.1)・Manifest連携(§7.2)・生成(§5)はACFの実行時に依存しないため、ACFの有無で処理を変えない。`function_exists()`でACFの有無を調べて黙って処理を飛ばす経路は持たない。
- 生成物`AcfManifest`が無いこと、`managedKeys()`が`ManagedKey`以外を返すこと、`#[MetaSchema]`との重複(§7.3)は設定の誤りであり、debugに関係なく例外にする。有効なACFの版が6.2より古いことも設定の誤りとして例外にする(§1.2・§2.1の手順5)。`AcfManifest`が無い場合のWP-CLIの扱いは§7.2のとおり。
- 生成クラスのアクセサが読んだ値の形の不一致(§6.1)は、実行時のデータ取得の失敗である。生成クラスのKernelのdebugでは`AcfBridgeRuntimeException`を投げ、本番ではそのKernelのロガーに`error`で記録して未設定の値を返す。`list<>`の型(objectのlistを含む)は、本番では不一致の要素ごとに記録してその要素だけを除く。未設定(§6.1)はエラーにしない。
- `AcfMetaStorage`の読み取りのデータベースのエラーは`WpMetaStorage`と同じく実行時のデータ取得の失敗として扱い、書き込みのデータベースのエラーはdebugに関係なく例外にする(§8.1)。
- `AcfMetaStorage`で扱えない型(§8.1)のACFのフィールドのキーへの`set()`・`delete()`は、設定の誤りとしてdebugに関係なく`AcfBridgeRuntimeException`にする。

`AcfNotActiveException` の正準仕様:

```php
final class AcfNotActiveException extends AcfBridgeException
{
    public static function forCall(string $callable): self;
    // メッセージ正準形:
    // "{callable} requires Advanced Custom Fields (ACF) 6.2+ to be installed and active.
    //  Install/activate the ACF plugin, or remove tmswp/acf-bridge usages."
}
```

検査は `AcfFieldAccess` の各操作冒頭(および `AcfMetaStorage`)で `function_exists('get_field')` により行う。クラスのロードや `of()` 自体は失敗させず、**値アクセス時点**で投げる(テンプレートの一部だけが ACF 依存のケースで原因箇所が特定しやすい)。サイレントに `null`/`''` を返すフォールバックは行わない(旧 FW の「動かない統合が静かに放置される」事故の再発防止)。

---

# 11. AcfFieldAccess trait(完全仕様)

生成クラスが唯一useする読み書き基盤。名前でACFを呼ぶ公開エスケープハッチと、生成コードが使うprotectedの型強制ヘルパからなる。型強制ヘルパは、フィールドのkey(KEY_定数)でACFを読み書きするものと、値を受け取って正規化するもの(名前が`Of`で終わる。ACFに触れない)の2組である。

```php
trait AcfFieldAccess
{
    private function __construct(private readonly int|string $acfId) {}

    /** ACF post_id 形式の識別子(int / 'term_1' / 'user_1' / 'option' 等) */
    public function acfId(): int|string;

    // ---- 公開エスケープハッチ(フィールド名でACFを呼ぶ) ----

    /** 値が保存されているか。get_field()が読む保存先のメタの行(optionsはoption)の有無で判定し、default_valueは「ある」としない(§6.1) */
    public function has(string $name): bool;

    /** フォーマット済み値(get_field($name, $id, true))。型強制なしのエスケープハッチ */
    public function value(string $name): mixed;

    /** raw 値(get_field($name, $id, false)) */
    public function rawValue(string $name): mixed;

    /** update_field($name, wp_slash($value), $id)。ACFはwp_unslash()するためslashして渡す。falseは、書き込まなかった・失敗したを区別できず、例外にせずそのまま返す(§6.1) */
    public function update(string $name, mixed $value): bool;

    /** delete_field($name, $id) */
    public function delete(string $name): bool;

    // ---- 生成コード用: field keyで読み書きするヘルパ(protected) ----
    // どれも、keyのフィールドがACFに登録されていなければ、debugにかかわらずAcfBridgeRuntimeException(§6.1)。
    // 読み取りはget_field($key, $id, true)の値を、同じ型の`*Of()`で正規化する。位置はフィールドの定義の名前。

    protected function acfString(string $key): string;
    protected function acfNullableString(string $key): ?string;
    protected function acfNumber(string $key): int|float|null;
    protected function acfInt(string $key): ?int;
    protected function acfBool(string $key): bool;
    protected function acfStringList(string $key): array;           // list<string>
    protected function acfIntList(string $key): array;              // list<int>
    protected function acfObject(string $key, string $class): ?object;    // class-string<T> → T|null
    protected function acfObjectList(string $key, string $class): array;  // class-string<T> → list<T>
    protected function acfImage(string $key): ?array;               // AcfImage|null(acfFile・acfLink・acfChoice・acfUserArray・acfColorRgbaも同じ形)
    protected function acfImageList(string $key): array;            // list<AcfImage>(acfChoiceList・acfUserArrayListも同じ形)
    protected function acfGroup(string $key, \Closure $shape): ?array;   // Closure(array<string, mixed>, string): T → T|null
    protected function acfRows(string $key, \Closure $row): array;       // Closure(array<string, mixed>, string): T → list<T>
    protected function acfLayouts(string $key, array $layouts): array;  // array<string, Closure(array<string, mixed>, string): T> → list<T>
    protected function acfEmptyLayouts(string $key): array;         // レイアウトの無いflexible_content → list<never>(§6.4)
    protected function acfMixed(string $key): mixed;                // 未知の型。型強制なし
    protected function acfDateTime(string $key, string $rawFormat): ?\DateTimeImmutable;
                                                                    // get_field($key, $id, false)をcreateFromFormat('!' . $rawFormat, ..., wp_timezone())。未設定はnull、書式に合わない値・範囲外の値は形の不一致(§6.3)
    protected function acfHas(string $key): bool;                   // has()と同じ判定。メタの名前はフィールドの定義の名前
    protected function acfDelete(string $key): bool;                // delete_field($key, $id)
    protected function acfUpdate(string $key, mixed $value): void;  // update_field($key, wp_slash($value), $id)(戻り値破棄)
    protected function acfUpdateDate(string $key, \DateTimeInterface|string|null $value, string $rawFormat): void;
                                                                    // DateTimeInterfaceはwp_timezone()の日時に変換してから$rawFormatで文字列にする(§6.3)

    // ---- 生成コード用: 値を受け取って正規化するヘルパ(protected。ACFに触れない) ----
    // $pathは例外とログに出す位置(`stores[0].shop_name`の形。§6.5)。group・repeater・flexible_contentのサブフィールドに使う。

    protected function acfStringOf(mixed $value, string $path): string;           // 未設定(null/false/'') → ''、文字列・数値以外は形の不一致
    protected function acfNullableStringOf(mixed $value, string $path): ?string;  // 未設定・'' → null
    protected function acfNumberOf(mixed $value, string $path): int|float|null;   // 未設定 → null、整数の文字列はint・小数と指数はfloat、数値でない値は形の不一致
    protected function acfIntOf(mixed $value, string $path): ?int;                // acfNumberOf()の小数は形の不一致
    protected function acfBoolOf(mixed $value, string $path): bool;               // スカラーとnullはtruthy正規化、配列・objectは形の不一致
    // acfStringListOf・acfIntListOf・acfObjectOf・acfObjectListOf・acfImageOf・acfFileOf・acfImageListOf・acfLinkOf・
    // acfChoiceOf・acfChoiceListOf・acfUserArrayOf・acfUserArrayListOf・acfColorRgbaOfも、key版と同じ型を返す
    protected function acfGroupOf(mixed $value, string $path, \Closure $shape): ?array;  // 未設定・[] → null、文字列のキーの配列でない値は形の不一致
    protected function acfRowsOf(mixed $value, string $path, \Closure $row): array;     // 未設定 → []、listでない値は形の不一致、行ごとに検査(本番は不正な行を除く)
    protected function acfLayoutsOf(mixed $value, string $path, array $layouts): array; // acfRowsOf()に加え、acf_fc_layoutが無い・レイアウトに無い行は形の不一致
    protected function acfEmptyLayoutsOf(mixed $value, string $path): array;          // 未設定 → []、行はどれも形の不一致(本番は除いて[])

    /** function_exists('get_field')検査。偽なら、呼び出したクラスとメソッドの名前(static::class . '::' . $method)でAcfNotActiveException */
    private function assertAcfActive(string $method): void;
}
```

- ACFを呼ぶすべての公開・保護メソッドは冒頭で`assertAcfActive(__FUNCTION__)`を呼ぶ(key版のヘルパは、keyのフィールドの定義を得る前に呼ぶ)。traitの中の`__METHOD__`はtraitの名前になるため、例外のメッセージは`assertAcfActive()`が`static::class`(生成クラス)とメソッドの名前で組み立てる。値を受け取るヘルパ(`*Of()`)はACFを呼ばないため、ACFが無効でも呼べる。
- traitのメソッド(privateを含む)の名前は、生成するメソッドの予約名になる(§5.5)。
- マジックメソッドは使用しない(規約どおり `__callStatic` 等は全面禁止。アクセサはすべてジェネレータが実メソッドとして生成する)。
- trait は `final` クラスでの使用を前提とし、状態は `$acfId` のみ(キャッシュを持たない。値キャッシュは ACF 自身の store に任せる)。

---

# 12. 利用例(app 側コード)

## 12.1 ブート(テーマ functions.php)

```php
\TMSWP\Core\Kernel\Kernel::boot(
    \TMSWP\Core\Kernel\KernelConfig::theme(
        appDir: __DIR__ . '/app',
        appNamespace: 'App',
        extensions: [new \TMSWP\AcfBridge\AcfBridge()], // 既定構成(acf-json/ → app/Acf/, App\Acf)
    ),
);
```

## 12.2 テンプレートでの読み取り(WP 標準テーマ手法のまま)

```php
<?php // single-product.php
$fields = \App\Acf\ProductFields::of(get_post()); ?>
<h1><?php the_title(); ?></h1>
<p class="price"><?php echo esc_html(number_format((float) ($fields->price() ?? 0))); ?>円</p>
<?php $img = $fields->mainVisual(); ?>
<?php if ($img !== null) : ?>
    <img src="<?php echo esc_url($img['sizes']['large'] ?? $img['url']); ?>" alt="<?php echo esc_attr($img['alt']); ?>">
<?php endif; ?>

<?php foreach ($fields->storeInfo() as $row) : ?>
    <li><?php echo esc_html($row['shop_name']); ?> (<?php echo esc_html($row['tel']); ?>)</li>
<?php endforeach; ?>
```

## 12.3 flexible_content の判別 union

```php
<?php foreach (\App\Acf\PageFields::of(get_post())->sections() as $section) : ?>
    <?php match ($section['acf_fc_layout']) {
        'hero'       => get_template_part('parts/hero', null, ['title' => $section['title'], 'image' => $section['image']]),
        'text_block' => get_template_part('parts/text-block', null, ['body' => $section['body']]),
    }; ?>
<?php endforeach; ?>
```

## 12.4 オプションページ

```php
$options = \App\Acf\ThemeSettingsOptions::instance();
$tel = $options->contactTel();          // text → string
$logo = $options->siteLogo();           // image rf=id → int|null
```

## 12.5 書き込み(管理機能・インポータ等)

```php
$fields = \App\Acf\ProductFields::of($postId);
$fields->setPrice(12800);
$fields->setMainVisual($attachmentId);
$fields->setStoreInfo([
    ['shop_name' => '本店', 'tel' => '03-0000-0000', 'open_hours' => '10:00-18:00'],
]);
```

## 12.6 MetaBag 経由(オプトイン)と日本語フィールド名のエスケープハッチ

```php
#[AsPostType]
#[UseMetaStorage(\TMSWP\AcfBridge\AcfMetaStorage::class)]
final class ProductPost extends PostType { /* ... */ }

ProductPost::meta($post)->int('stock');           // raw 値を MetaBag の型付きで取得

// 旧案件由来の日本語フィールド名(W009 対象)は value() で読む
$rows = \App\Acf\ShopFields::of($post)->value('店舗情報リピート');
```

---

# 13. 旧 iwf2b からの設計根拠

- **AbstractHook の ACF 直結合の解体**: 旧 FW はコアの `AbstractHook` が `class_exists('ACF')` 分岐で `acf/settings/path`(テーマ同梱 ACF Pro 前提)、`save_post → acf_field_auto_export`(テーマ直下へ `acf-export-9xxx.json` を自動書き出し)、`iwf2b/meta|option/* → get_field/update_field` 委譲フィルタを自動登録しており、10 テーマが暗黙に ACF 結合していた。本拡張はこの「コアが ACF を知る」構造を全廃し、ACF 知識を本パッケージへ隔離した(DR-10)。
- **acf-export 自動エクスポート運用が前身**: T04 等の `acf-export-*.json` 運用は「フィールド定義をファイルで Git 管理する」という正しい狙いを独自形式で実装したものだった。本拡張は同じ狙いを ACF 標準の Local JSON(acf-json/)に乗せ換え、さらに型付きアクセサ生成・ドリフト検知まで引き上げた(DR-06)。テーマ直下への独自 JSON 書き出し・`show_acf` クッキーによる管理画面トグル・テーマ同梱 ACF Pro のパス解決は後継を作らず廃止する。
- **null センチネル委譲の全廃**: 旧 `iwf2b/meta/get` 系フィルタは「null を返したら次へ」方式のため正当な null/false を外部ストレージから返せず、さらに `clear` 系はフィルタ名不一致(`iwf2b/meta/delete` 発火 vs `iwf2b/meta/clear` 登録)とシグネチャ不整合で**実際には機能していなかった**。本拡張は `has()`/`get()` 分離の `MetaStorageInterface` 実装 + `#[UseMetaStorage]` の明示オプトインに置き換え、グローバルフィルタ委譲は提供しない(DR-18)。
- **実利用実態への適合**: 実案件 15 件中、テーマでの `get_field`/ACF API 直接利用は数千箇所規模(T10 5,662 件等)であり「ACF を使わせない」のは非現実的。一方で型検査ゼロ・フィールド名タイポの実行時無反応が恒常的な事故源だった。生成アクセサ + PHPStan level max でこれを静的検査に引き上げる。
- **マニフェストのライフサイクル一本化**: ACF 管理下キーのマニフェストは acf-json の純関数である派生物なので、実行時に acf-json を走査して自前キャッシュする方式は採らず、他の生成クラスと同じ「sync 生成物としてコミットし check で鮮度を強制する」方式に揃える(DR-06)。キャッシュ検証・アトミック書込・書込不可フォールバックの機構は持たない。集約 source-hash は `AcfManifest.php` 1 箇所だけに置き、各生成クラスには埋め込まない。acf-json 1 ファイルの編集で無関係な生成クラスのヘッダが変化する diff ノイズを生まないためである。
- **プレビュー時 ACF 保存の継承**: 旧 `acf_save_preview_postmeta` は有用機能だったため継承する。ただしコアの `#[WithMetaRevisions]` は WP 6.4 の `revisions_enabled` への有効化レイヤであり独自フックを発火しない(Meta/Option 文書 §4.11)ため、旧 FW のような「コアが独自アクションを発火し ACF 実装が購読する」分割は採らず、本拡張の `PreviewAcfSupport` が旧 FW と同じ `wp_insert_post` 契機で acf-bridge 完結に実装する。旧実装に無かった nonce(`acf_verify_nonce`)と `edit_post` capability の検証を追加し、無検証の `$_POST['acf']` リビジョン書き込み経路を塞いだ(§8.3)。

---

# 14. 受け入れ基準

## チェックリスト

- [ ] `tmswp/core` のソースコードに `acf` という文字列(識別子・フック名・コメント)が存在しない(`grep -ri acf packages/core/src` が 0 件)
- [ ] `packages/acf-bridge/composer.json` の require が `php` と `tmswp/core` のみで、ACF が composer 依存に含まれない
- [ ] `KernelConfig`の`extensions`に渡した`AcfBridge`の登録(Kernelのboot)がACF不在環境でも例外なく完了し、ACF不在でも管理キーの申告(§7.2)が行われる
- [ ] テーマ/プラグイン両コンテキストで acf-json が §3.1 の規則どおり発見される(子テーマ優先・`BridgeConfig::$jsonDirs` 追加を含む)
- [ ] `post_type_*.json` / `taxonomy_*.json` / `active:false` グループが生成対象外となり、対応する W001/W011 が出る
- [ ] §4.2 の location 解決表どおりにターゲットが決まる(複数 OR グループ → 複数クラス生成、`!=` 無視、AND 内優先順位、非対応 param スキップをフィクスチャでそれぞれ検証)
- [ ] 同一ターゲットへの複数グループがマージされ、フィールド名重複が E001 になる
- [ ] クラス命名が §4.3 どおり(`product`→`ProductFields`、`genre`→`GenreTermFields`、user→`UserFields`、`theme-settings`→`ThemeSettingsOptions`、数字始まり `N` 前置、衝突 E003)
- [ ] `wp tmswp acf sync` が決定的出力を生成する(同一入力で 2 回実行してバイト同一・git status クリーン)
- [ ] 生成ファイルに `@generated` ヘッダ・再生成コマンド注記・sources が含まれ、集約 `source-hash` は `AcfManifest.php` のヘッダにのみ含まれる(他の生成ファイルには含まれない)
- [ ] sync が孤児ファイル(@generated 付き)を削除し、マーカー無しファイルを削除しない(I002)
- [ ] §6.2 の全フィールド型 × return_format の組につき、生成 getter/setter の型が表どおりである(スナップショットテストで全行カバー)
- [ ] 未設定値の正規化(文字列 `''` / 配列 `[]` / bool `false` / その他 `null`)が統合テストで確認される
- [ ] flexible_content が `acf_fc_layout` リテラル判別 union で生成され、PHPStan が `match` でナローイングできるサンプルが解析を通る
- [ ] clone の seamless(prefix_name 有無)/ group 展開、E004(参照先なし)、E005(循環)がフィクスチャで検証される
- [ ] date_picker / date_time_picker に `*AsDateTime(): ?\DateTimeImmutable` が生成され、`wp_timezone()` 基準でパースされる
- [ ] 日本語等非 ASCII フィールド名が W009 になり、`methodNameMap` 指定で生成可能になる
- [ ] 生成コードを含む app/ が PHPStan level max(szepeviktor/phpstan-wordpress)を baseline なしで通過する
- [ ] `AcfMetaStorage`の読み書きが`WpMetaStorage`と同じ意味でメタの生の行を読み書きし、ACFの書き込みの関数とフィルタを通らない。ACFのフィールドのキーの`set()`・`delete()`は参照のメタも扱い、扱えない型(§8.1)のキーは`AcfBridgeRuntimeException`になる。nullセンチネルのフォールスルーが存在しない
- [ ] `#[UseMetaStorage(AcfMetaStorage::class)]`付与エンティティの`meta()`が、ACFのフィールドの保存先を読み書きし、ACFがその値をフィールドとして読める(wp-env統合テスト)
- [ ] `BridgeConfig::$previewMeta = true` 時、プレビュー保存(リビジョン作成)で `$_POST['acf']` の値がリビジョンへ保存され、§8.3 の判定順序どおり「非リビジョン保存」「nonce 不正」「`edit_post` 権限なし」の各ケースで書き込まれない(統合テスト)。コアの `tmswp/` プレビュー用アクションへの依存が存在しない(`grep -r 'tmswp/preview' packages/acf-bridge/src` が 0 件)
- [ ] `#[MetaSchema]`の宣言が無いACF管理下キーは`register_*_meta`されず(統合テスト: `registered_meta_key_exists()`がfalse)、`#[UseMetaStorage(AcfMetaStorage::class)]`のエンティティが宣言したACF管理下キーは`register_*_meta`される(統合テスト: `registered_meta_key_exists()`がtrue)
- [ ] 別のストレージ(`AcfMetaStorage`でない)のエンティティの`#[MetaSchema]`とACF管理下キーの重複が、実行時例外および`check`のE101(exit 2)の両方で検出される
- [ ] `wp tmswp acf sync`が`AcfManifest.php`を生成し、`managedKeys()`がacf-json由来のACF管理下キー一覧を返す。`AcfManifest`クラスが無いとき、Manifest連携(§7.2)は`AcfBridgeRuntimeException`になり、WP-CLIのときだけ`WP_CLI::warning()`で警告して申告しない
- [ ] 実行時に `WP_CONTENT_DIR` 配下への Manifest キャッシュ書き込み・acf-json ディレクトリ走査が存在しない(grep/コードレビュー検証)
- [ ] ACF 不在環境で生成クラス/AcfMetaStorage へのアクセスが `AcfNotActiveException`(正準メッセージ)になる
- [ ] `wp tmswp acf check` が、差分なし=0 / 差分あり=1 / E*=2 の終了コードを返し、`--strict` で警告も 1 になる
- [ ] composer スクリプト `acf:sync` / `acf:check` がスターターに含まれ、CI で `check --strict` が実行される
- [ ] ユニットテスト(Brain Monkey、WP 非依存: LocationResolver / TypeMapper / NameConverter / Generator スナップショット)と統合テスト(wp-env + 無償版 ACF。Pro 専用型はフィクスチャ JSON によるユニット層でカバー)が分離されている(DR-12)

## 検証コマンド

```bash
# パッケージ単体 QA(monorepo ルートまたは packages/acf-bridge で)
composer lint && composer analyse && composer test

# コアの ACF 非依存確認
grep -ri --include='*.php' acf packages/core/src ; test $? -eq 1

# 生成の決定性
wp tmswp acf sync && wp tmswp acf sync && git status --porcelain app/Acf  # 出力なし

# ドリフト検知(CI と同一)
wp tmswp acf check --strict ; echo "exit=$?"

# 重複キー検出(フィクスチャ: MetaSchema と同名フィールドを持つ acf-json を配置)
wp tmswp acf check ; test $? -eq 2
```
