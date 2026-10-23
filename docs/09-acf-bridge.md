# 09. tmswp/acf-bridge — ACF統合拡張 完全仕様

## 目的

Advanced Custom Fields (ACF / ACF Pro) と TMSWP を統合する唯一の公式拡張パッケージ `tmswp/acf-bridge` の完全仕様を定める。本拡張は以下を提供する。

1. **acf-json を真実源としたコードジェネレータ** — フィールドグループ定義から型付きアクセサクラス(`app/Acf/*Fields.php` 等)を生成し、PHPStan level max で型検査可能な ACF アクセスを実現する(DR-06)。
2. **`AcfMetaStorage`** — コアの `MetaStorageInterface` 実装。`#[UseMetaStorage]` によるエンティティ単位の明示オプトインで、`MetaBag` の読み書きを `get_field`/`update_field` へ委譲する(DR-18)。
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
3. `BridgeConfig::$registerLoadJsonDirs === true`のとき`acf/settings/load_json`フィルタを登録し、`BridgeConfig::$jsonDirs`のうち存在して未登録のディレクトリを加える。
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
| テーマ | `get_stylesheet_directory() . '/acf-json'`(子テーマ運用時は `get_template_directory() . '/acf-json'` も追加。同名ファイルは子テーマ優先) |
| プラグイン | `dirname($pluginFile) . '/acf-json'` |
| 追加 | `BridgeConfig::$jsonDirs` で任意追加 |

- 対象ファイル: 各ディレクトリ直下の `*.json`(再帰しない。ACF 標準と同じ)。
- 同一フィールドグループ key のファイルが複数ディレクトリにある場合、`jsonDirs` の並びで先勝ち(テーマ既定では子テーマ優先)。後続は無視し W010 を出す。

## 3.2 ファイル種別の判定

ACF 6 系の Local JSON はフィールドグループ以外も保存する。トップレベル `key` のプレフィクスで判定する。

| `key` プレフィクス | 種別 | 扱い |
|---|---|---|
| `group_` | フィールドグループ | **生成対象** |
| `ui_options_page_` | ACF オプションページ定義 | location 解決の補助情報(menu_slug → `post_id` 設定)として読む。クラス生成はフィールドグループの location 側から行う(§5.4) |
| `post_type_` / `taxonomy_` | ACF UI 登録の CPT/タクソノミー | **対象外**。W001 を出す。rules/ で「CPT/タクソノミーは `#[AsPostType]`/`#[AsTaxonomy]` で PHP 宣言する。ACF UI 登録は使用しない」を必須規約とする |
| その他 / `key` 不在 / JSON 構文エラー | 不明 | JSON 構文エラーは E102、それ以外は W001 で無視 |

トップレベルが配列(複数グループを 1 ファイルに格納した手動エクスポート形式)の場合は各要素を個別グループとして読む。

## 3.3 `TMSWP\AcfBridge\Json\AcfJsonRepository`

```php
final class AcfJsonRepository
{
    /** @param list<string> $jsonDirs */
    public function __construct(private readonly array $jsonDirs) {}

    /**
     * 全フィールドグループを読み込み、正規化して返す。
     * 並び順はグループ key の昇順(決定性のため)。
     * @return list<FieldGroup>
     */
    public function fieldGroups(): array;

    /**
     * ui_options_page_*.json から menu_slug → post_id 設定のマップを返す。
     * post_id 未設定のページは含めない。
     * @return array<string, string>
     */
    public function optionsPagePostIds(): array;

    /** 入力ファイル一覧(パス昇順)。ドリフト検知のハッシュ計算に使う。 @return list<string> */
    public function sourceFiles(): array;

    /**
     * 正規化済み入力のハッシュ。
     * sha256( JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE で再エンコードした
     *         グループ配列(key昇順、modified フィールドは除外) )
     */
    public function sourceHash(): string;
}
```

正規化仕様:

- `FieldGroup` は `key` / `title` / `fields`(`FieldDefinition` のリスト、JSON 出現順を維持)/ `location`(生の二重配列)/ `active` を持つ readonly DTO。`active: false`(無効化グループ)は生成対象外とし W011 を出す。
- `FieldDefinition` は `key` / `name` / `label` / `type` / `required`(bool)/ `returnFormat`(?string)/ `multiple`(bool)/ `fieldType`(?string, taxonomy用)/ `subFields`(list<FieldDefinition>)/ `layouts`(flexible用: name → list<FieldDefinition>)/ `raw`(元配列, array<string,mixed>)を持つ readonly DTO。
- ハッシュから `modified`(ACF が保存毎に更新するタイムスタンプ)を除外することで、無意味なドリフト検出を防ぐ。**それ以外のキーはすべてハッシュに含める**(label 変更等も再生成対象。生成コードの PHPDoc に label を含めるため)。

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

ファイルヘッダ(正準形):

```php
<?php

/**
 * ProductFields — ACF typed accessors for post_type "product".
 *
 * @generated by tmswp/acf-bridge
 *
 * このファイルは自動生成。手で編集しないこと(次回 sync で上書きされる)。
 * 再生成: wp tmswp acf sync  (composer acf:sync)
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
/**
 * @phpstan-import-type AcfImage from \TMSWP\AcfBridge\AcfTypes
 */
final class ProductFields
{
    use \TMSWP\AcfBridge\AcfFieldAccess;

    /** 製品価格 (number, group_66f01a2b3c4d5) */
    public const PRICE = 'price';
    public const KEY_PRICE = 'field_66f0aaaa1111';

    public const MAIN_VISUAL = 'main_visual';
    public const KEY_MAIN_VISUAL = 'field_66f0bbbb2222';

    public const STORE_INFO = 'store_info';
    public const KEY_STORE_INFO = 'field_66f0cccc3333';

    public static function of(\WP_Post|int $post): self
    {
        return new self($post instanceof \WP_Post ? $post->ID : $post);
    }

    public function price(): int|float|null { /* return $this->acfNumber(self::PRICE); */ }

    public function setPrice(int|float|null $value): void { /* $this->acfUpdate(self::PRICE, $value); */ }

    /** @return AcfImage|null */
    public function mainVisual(): ?array { /* acfNullableArray(...) */ }

    public function setMainVisual(?int $value): void {}

    /** @return list<array{shop_name: string, tel: string, open_hours: string}> */
    public function storeInfo(): array {}

    /** @param list<array{shop_name?: string, tel?: string, open_hours?: string}> $value */
    public function setStoreInfo(array $value): void {}
}
```

ファクトリの正準シグネチャ(TargetKind 別):

| TargetKind | ファクトリ | 内部 ACF id |
|---|---|---|
| Post | `public static function of(\WP_Post\|int $post): self` | `(int) $id` |
| Term | `public static function of(\WP_Term\|int $term): self` | `"term_{$id}"` |
| User | `public static function of(\WP_User\|int $user): self` | `"user_{$id}"` |
| Options | `public static function instance(): self` | §5.4 の post_id(既定 `'option'`)。キャッシュしない(呼び出し毎に new) |

各フィールドにつき生成するメンバ:

1. `public const {UPPER_SNAKE(name)} = '{name}';` — フィールド名定数
2. `public const KEY_{UPPER_SNAKE(name)} = '{field key}';` — フィールド key 定数(`acf/load_field` 等のフック対象指定に使う)
3. getter `public function {camel(name)}(): {型}` — §6 の表に従う。PHPDoc にグループ key・label・ACF 型を 1 行コメントで付す
4. setter `public function set{Studly(name)}({保存型} $value): void` — §6 の「setter 引数型」に従う。内部は `acfUpdate()` 委譲
5. date_picker / date_time_picker のみ追加 getter `public function {camel(name)}AsDateTime(): ?\DateTimeImmutable`(§6.3)

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

    /** 'main_visual' → 'MAIN_VISUAL'。定数名として不正なら null */
    public static function constName(string $fieldName): ?string;
}
```

1. 変換対象は `^[a-z][a-z0-9_]*$`(ASCII snake_case)に一致するフィールド名のみ。一致しない名前(日本語フィールド名・大文字混在等)はアクセサ・定数を生成せず W009 を出し、`BridgeConfig::$methodNameMap`(`"{groupKey}.{fieldName}" => 'asciiName'`)での明示マッピングを案内する。methodNameMap 指定時はその名前で生成する(定数値は元のフィールド名のまま)。旧案件には `get_field('店舗情報リピート')` のような日本語名が実在するため、エスケープハッチとして trait の `value()`(§6.5)も常に利用可能。
2. camel 化後の衝突(`main_visual` と `mainVisual` の併存等)は E002。
3. trait の公開/保護メンバ(`of` は trait 外だが予約)と PHP 予約語に一致する場合はサフィックス `Field` を付ける(`list` → `listField()`)。それでも衝突する場合は E002。予約対象シンボル: `of`, `instance`, `value`, `rawValue`, `update`, `delete`, `has`, `acfId`, PHP の予約語一覧。
4. setter 名・AsDateTime 名・定数名の衝突も同規則で検査し、解決不能なら E002。

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
| E002 | エラー | メソッド名/定数名の衝突(camel 化・予約語回避後も解決不能) |
| E003 | エラー | クラス名衝突、studly 変換不能、classMap の不正指定 |
| E004 | エラー | clone フィールドの参照先(field key / group key)が acf-json 内に見つからない |
| E005 | エラー | clone の循環参照 |
| E101 | エラー | `#[MetaSchema]` 宣言と ACF 管理下キーの重複(§7.3。check 時に静的検出) |
| E102 | エラー | JSON 構文エラー、出力先書込不可、設定不備 |
| W001 | 警告 | 非対象 JSON(post_type_*/taxonomy_*/不明種別)を無視した |
| W002 | 警告 | location を解決できずグループ(または AND グループ)をスキップ |
| W003 | 警告 | `post`(個別 ID)条件は静的解決不能 |
| W004 | 警告 | 同一 AND グループ内の複数対象条件に優先順位を適用した |
| W005 | 警告 | 未知のフィールド型を `mixed` として生成した |
| W006 | 警告 | 非対応 location パラメータ(comment/widget/block 等)をスキップ |
| W007 | 警告 | 不成立 AND 条件(同一 param の == 重複) |
| W009 | 警告 | ASCII snake_case でないフィールド名のためアクセサ未生成 |
| W010 | 警告 | 複数ディレクトリに同一グループ key(先勝ち適用) |
| W011 | 警告 | `active: false` のグループをスキップ |
| I001 | 情報 | acf-json に裏付けのない PHP 登録グループを検出(生成対象外) |
| I002 | 情報 | outputDir に `@generated` マーカーの無いファイルが存在(削除しない) |

---

# 6. ACF フィールド型 → PHP 型マッピング表(TypeMapper)

## 6.1 共通規則

- **getter は「フォーマット済み値」**(`get_field($name, $id, true)` 相当)を返す。return_format / multiple / field_type 設定で型が決まる。
- **未設定時の値の正規化**: ACF の `null`/`false`/`''` の揺れを生成コード側で吸収する。文字列系は `''`、配列系は `[]`、bool は `false`、それ以外は `null` に正規化する。`required` 設定は ACF では保存を強制しないため**型のナローイングには使わない**(常に未設定がありうる前提の型とする)。
- **setter は「保存型(raw)」** を受け取る。`update_field` は raw 値(添付 ID・投稿 ID・`Ymd` 文字列等)で保存するため。`null` を渡した場合は値を空に更新する(`update_field($name, null)` 委譲。削除は trait の `delete()` を使う)。
- array shape 内のキーはフィールド名(snake_case のまま)。サブフィールドの型は本表を再帰適用した「getter 戻り値型」。値を持たない型(message/tab/accordion)のサブフィールドは shape から除外する。

`TMSWP\AcfBridge\AcfTypes`(@phpstan-type 定義専用。runtime メンバなし):

```php
/**
 * @phpstan-type AcfImage array{ID: int, id: int, title: string, filename: string,
 *   filesize: int, url: string, link: string, alt: string, author: string,
 *   description: string, caption: string, name: string, status: string,
 *   uploaded_to: int, date: string, modified: string, menu_order: int,
 *   mime_type: string, type: string, subtype: string, icon: string,
 *   width: int, height: int, sizes: array<string, int|string>}
 * @phpstan-type AcfFile array{ID: int, id: int, title: string, filename: string,
 *   filesize: int, url: string, link: string, alt: string, author: string,
 *   description: string, caption: string, name: string, status: string,
 *   uploaded_to: int, date: string, modified: string, menu_order: int,
 *   mime_type: string, type: string, subtype: string, icon: string}
 * @phpstan-type AcfLink array{title: string, url: string, target: string}
 * @phpstan-type AcfChoice array{value: string, label: string}
 * @phpstan-type AcfUserArray array{ID: int, user_firstname: string, user_lastname: string,
 *   nickname: string, user_nicename: string, display_name: string, user_email: string,
 *   user_url: string, user_registered: string, user_description: string, user_avatar: string}
 * @phpstan-type AcfColorRgba array{red: int, green: int, blue: int, alpha: float}
 */
final class AcfTypes
{
    private function __construct() {}
}
```

生成クラスはこれらを `@phpstan-import-type X from \TMSWP\AcfBridge\AcfTypes` で取り込む。

## 6.2 マッピング表(全 31 型)

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
| 上記以外(google_map 等の同梱型・サードパーティ型) | — | `mixed`(W005) | `null` | `mixed` |

- rf = return_format。acf-json に return_format が無い場合は ACF の既定値(image/file=`array`、select 系=`value`、link=`array`、post_object/relationship=`object`、taxonomy=`id`、user=`array`、color_picker=`string`)で解釈する。
- 未知型の拡張点: 生成時(CLI 実行時)に `apply_filters('tmswp/acf_bridge/type_map', array<string, TypeMapping> $map)` を適用し、サードパーティ型のマッピングを app/拡張側から追加できる。

```php
final readonly class TypeMapping
{
    public function __construct(
        public string $getterNativeType,  // 例: '?string', 'array'
        public string $getterDocType,     // 例: 'AcfImage|null', 'list<int>'
        public string $coercionHelper,    // AcfFieldAccess のヘルパ名。例: 'acfNullableArray'
        public string $setterNativeType,  // 例: '?int'
        public string $setterDocType = '',
    ) {}
}
```

## 6.3 日付系の追加アクセサ

date_picker / date_time_picker には return_format 適用済み string getter に加えて以下を生成する。

```php
public function {camel}AsDateTime(): ?\DateTimeImmutable;
```

- raw 値(date_picker=`Ymd`、date_time_picker=`Y-m-d H:i:s`)を `wp_timezone()` で `\DateTimeImmutable::createFromFormat()` する。未設定・パース不能は `null`。
- time_picker には生成しない(日付情報を持たないため)。
- setter は `\DateTimeInterface` を受けた場合、raw 形式(`Ymd` / `Y-m-d H:i:s` / `H:i:s`)へ変換して保存する。

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

## 6.5 repeater / group の shape 規則

- repeater: `list<array{sub1: T1, sub2: T2, ...}>`。行が 0 件・未設定は `[]`。
- group: `array{sub1: T1, ...}|null`。一度も保存されていない場合 ACF は `false`/空を返すため `null` に正規化する。保存済みの場合、各サブの未設定値はサブ型の「未設定時」値に正規化**しない**(ACF のフォーマット済み値をそのまま shape 型として注釈する。サブ shape では nullable 側に倒す: 文字列系サブも `string` のまま、object 系サブは `|null` 付き。すなわち本表の getter 型列をそのまま使う)。
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
- `AcfManifest.php` は §5.2 の生成規約(`@generated` ヘッダ・期待ファイル集合・孤児削除・`check` のバイト一致比較・決定的出力=グループ key 昇順)に従う。ヘッダには全入力の集約ハッシュ `source-hash: sha256:...`(§3.3 `sourceHash`)を記録する(集約ハッシュを持つ唯一の生成ファイル。§5.2)。
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

ACF管理下キーは、`#[MetaSchema]`と`#[UseMetaStorage(AcfMetaStorage::class)]`で宣言してよい(文書04「メタ・設定の使い分け」)。読み書きの経路がACFの1つに揃うためである。一方、同一`(ObjectType, objectSubtype, metaKey)`を、読み書きのストレージが`AcfMetaStorage`でないエンティティ(`#[UseMetaStorage]`の無いエンティティ等)が`#[MetaSchema]`で宣言している状態は**構成エラー**であり、フェイルファストする(DR-06)。旧FWの「ACFとコアのメタ層が同じキーを別経路で読み書きして挙動不明」という事故の再発防止が目的。ストレージは`#[UseMetaStorage]`の指定(無ければ既定のストレージ)をContainerで解決したインスタンスのクラスで比べる。

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
    public function has(ObjectType $objectType, int $objectId, string $key): bool;
    public function get(ObjectType $objectType, int $objectId, string $key): mixed;
    public function set(ObjectType $objectType, int $objectId, string $key, mixed $value): bool;
    public function delete(ObjectType $objectType, int $objectId, string $key): bool;
}
```

振る舞い仕様:

- ACF id 変換: `Post` → `(int) $objectId`、`Term` → `"term_{$objectId}"`、`User` → `"user_{$objectId}"`。
- `get()` は **raw 値**(`get_field($key, $acfId, false)`)を返す。理由: `MetaBag` の型付きアクセサ(`int()`/`string()` 等)は DB 保存型を前提としており、フォーマット済み値(image の array 等)は `MetaBag` の型コントラクトを壊す。フォーマット済み値が必要な場面では生成アクセサクラス(`XxxFields`)を使う。この役割分担を rules/ に明記する。
- `has()` は `get_field($key, $acfId, false) !== null` で判定する(WP メタは `null` を保存できないため raw `null` = 未保存と同値)。**`get()` と `has()` は分離されており、`null` をセンチネルに使うフォールスルーは存在しない**(DR-18)。
- `set()` は `update_field($key, $value, $acfId)` へ委譲する。`delete()` は `delete_field($key, $acfId)` へ委譲する。戻り値と例外はコアの `MetaStorageInterface` の契約(Meta/Option 文書 §4.5: 書き込みは「書き込んだか」を bool で返し、書き込まなかった場合(同じ値・フィルタによる中止)は false、データベースのエラー等の書き込みの失敗は例外、`null` は保存しない)に従う。
- 全メソッドは冒頭で `function_exists('get_field')` を検査し、偽なら `AcfNotActiveException` を投げる(§10)。
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

登録・申告・生成の扱いは、文書00 §5.6のエラー方針に次のとおり当てはめる。

- ACFが無効であることは、それだけでは設定の誤りとしない。ACFの更新や一時的な無効化で、ACFの値を使わないページまで止めないためである。登録(§2.1)・Manifest連携(§7.2)・生成(§5)はACFの実行時に依存しないため、ACFの有無で処理を変えない。`function_exists()`でACFの有無を調べて黙って処理を飛ばす経路は持たない。
- 生成物`AcfManifest`が無いこと、`managedKeys()`が`ManagedKey`以外を返すこと、`#[MetaSchema]`との重複(§7.3)は設定の誤りであり、debugに関係なく例外にする。有効なACFの版が6.2より古いことも設定の誤りとして例外にする(§1.2・§2.1の手順5)。`AcfManifest`が無い場合のWP-CLIの扱いは§7.2のとおり。

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

生成クラスが唯一 use する読み書き基盤。公開エスケープハッチと、生成コードが使う protected 型強制ヘルパからなる。

```php
trait AcfFieldAccess
{
    private function __construct(private readonly int|string $acfId) {}

    /** ACF post_id 形式の識別子(int / 'term_1' / 'user_1' / 'option' 等) */
    public function acfId(): int|string;

    /** raw 値の存在判定(get_field($name, $id, false) !== null) */
    public function has(string $name): bool;

    /** フォーマット済み値(get_field($name, $id, true))。型強制なしのエスケープハッチ */
    public function value(string $name): mixed;

    /** raw 値(get_field($name, $id, false)) */
    public function rawValue(string $name): mixed;

    /** update_field($name, $value, $id)。失敗(false)時は例外にせず false を返す */
    public function update(string $name, mixed $value): bool;

    /** delete_field($name, $id) */
    public function delete(string $name): bool;

    // ---- 生成コード用 型強制ヘルパ(protected) ----

    protected function acfString(string $name): string;             // null/false → ''
    protected function acfNullableString(string $name): ?string;    // ''/null/false → null
    protected function acfNumber(string $name): int|float|null;     // ''/null/false → null、整数値はint・小数はfloat
    protected function acfBool(string $name): bool;                 // truthy 正規化
    /** @return list<mixed> */
    protected function acfList(string $name): array;                // 非配列/false/null → []
    /** @return array<string, mixed>|null */
    protected function acfNullableArray(string $name): ?array;      // 非配列/false/null → null
    protected function acfObject(string $name, string $class): ?object; // instanceof 検査、不一致は null
    protected function acfDateTime(string $name, string $rawFormat): ?\DateTimeImmutable;
                                                                    // rawValue を createFromFormat($rawFormat, ..., wp_timezone())
    protected function acfUpdate(string $name, mixed $value): void; // update() 委譲(戻り値破棄)
    protected function acfUpdateDate(string $name, \DateTimeInterface|string|null $value, string $rawFormat): void;

    /** function_exists('get_field') 検査。偽なら AcfNotActiveException */
    private function assertAcfActive(string $callable): void;
}
```

- すべての公開・保護メソッドは冒頭で `assertAcfActive(__METHOD__)` を呼ぶ。
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
- [ ] `AcfMetaStorage` が has/get/set/delete を ACF へ委譲し、`get()` が raw 値を返す。null センチネルのフォールスルーが存在しない
- [ ] `#[UseMetaStorage(AcfMetaStorage::class)]` 付与エンティティの `meta()` が ACF 経由になる(wp-env 統合テスト)
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
