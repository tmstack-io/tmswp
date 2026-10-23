# 04. Meta / Option 層設計書

## 目的

WordPressのメタデータ(post/term/user meta)とオプション(wp_options)を、属性によるスキーマ宣言と型付きアクセサで扱う層を定義する。本層が保証すること:

1. **宣言の一元化**: メタキーはエンティティクラスの定数として、オプションキーはオプショングループ enum のケースとして一度だけ宣言し、`#[MetaSchema]` / `#[OptionSchema]` 属性で型・デフォルト値・REST公開等のスキーマを付与する。
2. **WPコアへの自動登録**: 宣言されたメタスキーマは Kernel の boot の中で集めて検証し、`init`(priority 20)で `register_post_meta()` / `register_term_meta()` / `register_meta('user', ...)` へ自動登録する(4.9)。
3. **型安全な読み書き**: `MetaBag` / オプショングループ enum(`OptionAccess` trait)の型付きgetter(`int()`/`string()`/`bool()`/`float()`/`list()`/`json()`)と、FW同梱のPHPStan拡張による戻り値型ナローイングで、メタ値の型をコードとして検証可能にする。
4. **ストレージの差し替え可能性**: `MetaStorageInterface` / `OptionStorageInterface` により、ACF等の外部ストレージへの委譲を「エンティティ単位の明示オプトイン」で行える(DR-18)。グローバルフィルタによる暗黙差し替えと null センチネルは全廃する。既定のストレージは Container の interface の id で解決し、テストでは boot の前に別の実装を登録して差し替えられる(4.16)。
5. **プレビュー時メタの整合**: WP 6.4 の `revisions_enabled` 機構を用いた ACF 非依存のプレビュー時メタ保存を、クラス属性 `#[WithMetaRevisions]` による宣言的オプトインで提供する。

マジックメソッド(`__callStatic`)・`is_subclass_of` による暗黙のオブジェクト種別分岐・null センチネルフィルタは本層に存在しない(設計根拠の章を参照)。

## スコープ(対象パッケージ)

- `packages/core` (tmswp/core) のうち:
  - `TMSWP\Core\Attribute\MetaSchema`, `TMSWP\Core\Attribute\OptionSchema`, `TMSWP\Core\Attribute\AsOptionGroup`, `TMSWP\Core\Attribute\UseMetaStorage`, `TMSWP\Core\Attribute\WithMetaRevisions`
  - `TMSWP\Core\Meta\*` 全クラス
  - `TMSWP\Core\Option\*` 全クラス
  - `TMSWP\Core\Exception\` 配下の本層関連例外
  - FW同梱PHPStan拡張 `TMSWP\Core\PHPStan\*`(`packages/core/phpstan/` 配下、解析時のみロード)
- スコープ外: `AcfMetaStorage` の実装(packages/acf-bridge 設計書が定義。本書はコア側の接続点のみ定義する)、`PostType`/`Taxonomy`/`UserRole` 基底の `meta()` メソッド実装(エンティティ設計書が定義。本書はその呼び先である `MetaSchemaRegistry::bagFor()` を定義する)。

## 依存文書

- Kernel/Discovery 設計書: `Kernel::boot()` のフェーズ、`Discovery` による属性付きクラスの収集、コンポーネントの初期化タイミング。本書の `MetaSchemaRegistry` には、Discovery が収集したエンティティの Initializer が Kernel の boot の中(boot 時の初期化。Kernel設計書 §7)で登録する。
- エンティティ定義設計書(PostType/Taxonomy/UserRole): エンティティ基底の `meta()` ファサードと `definition()` のスラッグ解決。本書が定義する `MetaSchemaRegistry::registerEntity()` をKernelが呼び出す際のスラッグ(subtype)の供給元。
- acf-bridge 拡張設計書: `AcfMetaStorage` の実装、acf-json からのコード生成、`MetaSchemaRegistry::markExternallyManaged()` の呼び出しタイミング。

## 全体像

```
app/Post/ProductPost.php
  #[AsPostType]
  #[UseMetaStorage(AcfMetaStorage::class)]   ← 任意(エンティティ単位オプトイン)
  final class ProductPost extends PostType {
      #[MetaSchema(type: MetaValueType::Integer, default: 0, showInRest: true)]
      public const META_PRICE = 'price';
  }

Kernel::boot() の中(boot 時の初期化。エンティティの一覧は探索結果ファイル(本番)か走査(debug)から):
  PostTypeInitializer::initializeAtBoot()
    → MetaSchemaRegistry::registerEntity(ProductPost::class, ObjectType::Post, 'product')
            → 定数リフレクション → MetaSchemaDefinition 群を構築・検証
  (boot の後は init の前でも ProductPost::meta($post) が使える)
init(priority 6)  → acf-bridge が外部管理のキーを申告(markExternallyManaged())
init(priority 10) → PostTypeInitializer::initialize() → register_post_type('product', ...)
init(priority 20) → MetaSchemaRegistry::registerAll()
            → register_post_meta('product', 'price', [...])
            (これ以降、sanitize を宣言したキーにも書き込める)

実行時(テンプレート/app):
  ProductPost::meta($post)                    ← エンティティ基底のファサード(エンティティ設計書)
    └→ MetaSchemaRegistry::bagFor(ProductPost::class, $post): MetaBag
         └→ MetaBag::int(ProductPost::META_PRICE)
              └→ MetaStorageInterface::has()/get() → ValueCaster で型キャスト
```

オプション側も値解決・キャスト規則は同型だが、宣言の器が異なる: `#[AsOptionGroup]` を付与した backed enum の `#[OptionSchema]` 付きケース(`OptionAccess` trait)→ `OptionRepository` → `OptionStorageInterface`。meta=属性付きクラス定数 / option=属性付き enum ケースという宣言形の非対称は意図的トレードオフである(4.12)。

## メタ・設定の使い分け

投稿・ターム・ユーザーの値とサイトの設定を読み書きする経路は、値の種類ごとに次の 1 つに決める。同じ値を複数の経路で宣言しない。

| 値の種類 | 宣言と読み書きの経路 |
| --- | --- |
| 単純な値(文字列・数値・真偽・日付・単一画像 ID 等) | エンティティの定数に `#[MetaSchema]` で宣言し、アプリは `MetaBag`(`ProductPost::meta($post)`)だけで読み書きする。 |
| 上の単純な値をACFの画面で編集する場合 | 宣言と`MetaBag`はそのままにし、エンティティに`#[UseMetaStorage(AcfMetaStorage::class)]`を付けて保存先だけをACFに切り替える(4.10)。そのキーはACFのフィールドグループで管理してよい(下の「ACFのフィールドグループで管理するキー」)。ACFに完全には依存させないため、`UseMetaStorage`と`AcfMetaStorage`を維持する。 |
| ACF 固有の複合型(repeater・flexible_content・group・gallery・relationship) | acf-bridge の生成アクセサ(`XxxFields`)で扱う(文書09)。その案件は ACF に依存するものと割り切る。 |
| 入力検証が重要なシステム設定(メールの宛先、API キー等) | オプショングループ enum(4.12〜4.16)に宣言し、設定画面は settings-page で作る(文書08)。API キー等の秘密情報もここに保存してよいが、テンプレートへの出力・ログへの記録・フロントへの公開(REST 等)をしない。 |
| フロントに出すコンテンツ設定(画像、繰り返し項目等) | ACF のオプションページで作り、生成アクセサ(`XxxOptions`)で読む(文書09)。同じ値をオプショングループ enum に重複して宣言しない。 |

**ACFのフィールドグループで管理するキー**: acf-bridgeは、ACFのフィールドグループで管理するキーを`markExternallyManaged()`系で外部管理として申告する(ストレージは`AcfMetaStorage`。文書09 §7)。同じキーを`#[MetaSchema]`で宣言できるのは`#[UseMetaStorage(AcfMetaStorage::class)]`のエンティティだけで、申告と衝突せず、宣言どおり`register_*_meta`される。`#[UseMetaStorage]`の無いエンティティ等、ストレージの違うエンティティが同じキーを宣言すると、起動時に`DuplicateMetaKeyException`になる(4.9)。同じキーを`MetaBag`と生成アクセサ(`XxxFields`)の両方から読み書きしない。

---

## 設計本体

### 4.1 `TMSWP\Core\Attribute\MetaSchema`

#### 役割

エンティティクラス(PostType/Taxonomy/UserRole のサブクラス)の `public const` に付与し、その定数値をメタキーとして宣言する属性。スキーマ情報(型・デフォルト・REST公開・サニタイズ)を保持する。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Meta\MetaValueType;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class MetaSchema
{
    /**
     * @param MetaValueType $type register_meta() の type に渡す値(WP境界では backing value を渡す)。
     * @param mixed $default 未保存時のデフォルト値。null はデフォルトなしを意味する。
     * @param bool $single false の場合、同一キーで複数行を保持するメタ(get/setはlist専用パスになる)。
     * @param bool|array<string, mixed> $showInRest true で REST 公開。
     *        配列の場合は register_meta() の show_in_rest にそのまま渡す
     *        (例: ['schema' => ['type' => 'array', 'items' => ['type' => 'string']]])。
     * @param string $description register_meta() の description。
     * @param string|array{class-string, string}|null $sanitize
     *        register_meta() の sanitize_callback。属性は定数式しか書けないため、
     *        関数名文字列('absint')または [ClassName::class, 'staticMethod'] の配列形式のみ許可。
     *        クロージャは指定不可。
     */
    public function __construct(
        public readonly MetaValueType $type = MetaValueType::String,
        public readonly mixed $default = null,
        public readonly bool $single = true,
        public readonly bool|array $showInRest = false,
        public readonly string $description = '',
        public readonly string|array|null $sanitize = null,
    ) {
    }
}
```

#### 振る舞い仕様

- 属性自体は純粋なデータホルダで、検証ロジックを持たない。検証は `MetaSchemaDefinition::fromAttribute()`(4.4)がブート時に行う。
- `type` は `MetaValueType` enum(4.2)で受ける。enumケースは定数式のため属性引数に直接記述でき、不正値はIDE補完・PHPStan・PHPの属性インスタンス化が実行前に拒否するため、ブート時の type 検証は持たない。
- `default` は `type` とキャスト互換でなければならない(例: `type: MetaValueType::Integer` に `default: 'abc'` は `InvalidSchemaException`)。互換判定は `ValueCaster::accepts()`(4.7)で行う。
- `single: false` と `default` 非null の併用は `InvalidSchemaException`(WPの register_meta はsingle=falseのdefaultを単一値として扱い混乱の元のため、FWでは禁止する)。
- `sanitize` にクロージャや存在しない callable を指定した場合: 配列/文字列形式チェックはブート時、callable 実在チェックは `registerAll()` 時に `is_callable()` で行い、不成立なら `InvalidSchemaException`。
- 付与対象の定数は `public const`(型付き定数 `public const string` 推奨)かつ値が空でない `string` であること。それ以外はブート時 `InvalidSchemaException`。`protected`・`private` の定数に付けた場合も、黙って飛ばさずに `InvalidSchemaException` とする(`00-overview.md` §5.6 の「設定の誤り」)。
- `type` が `Array` または `Object` で REST に出す(`showInRest` が `false` でない)場合は、`showInRest` を配列にして要素・プロパティのスキーマを書くこと。`Array` は `showInRest['schema']['items']`、`Object` は `showInRest['schema']` の `properties`・`patternProperties`・`additionalProperties` のいずれかが必要で、無ければブート時 `InvalidSchemaException`。WP の `register_meta()` は `array` 型で `show_in_rest.schema.items` が無いと登録を拒否し、`object` 型でプロパティのスキーマが無いと REST の値が常に空のオブジェクトになるためである。

### 4.2 `TMSWP\Core\Meta\MetaValueType`(新設・公開API enum)

#### 役割

`MetaSchema::$type` / `OptionSchema::$type` が直接受け取る公開APIのbacked enum。enumケースは定数式であるため、利用側は属性引数に `type: MetaValueType::Integer` のように直接記述する。キャスト・PHPStan型マップ・register_meta 引数生成の分岐にも使う。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

enum MetaValueType: string
{
    case String = 'string';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Number = 'number';
    case Array = 'array';
    case Object = 'object';

    /** 型に対応するPHPゼロ値('' / false / 0 / 0.0 / [] / [])を返す */
    public function zeroValue(): mixed;
}
```

#### 振る舞い仕様

- `from()` / `tryFrom()` はenum標準のまま。属性が `MetaValueType` ケースを直接受けるため、`MetaSchemaDefinition::fromAttribute()` での `tryFrom()` 変換は存在しない。
- `zeroValue()` の対応表: String→`''`, Boolean→`false`, Integer→`0`, Number→`0.0`, Array→`[]`, Object→`[]`(PHP表現は連想配列)。

### 4.3 `TMSWP\Core\Meta\ObjectType`

#### 役割

メタの対象オブジェクト種別を**明示**するenum。旧 `DefineMetaTrait` が `is_subclass_of(static::class, AbstractPost|AbstractTax|AbstractUser)` の暗黙分岐で振り分けていた(継承文脈がないと全分岐falseで未定義値が返る欠陥があった)ことへの直接の対策であり、本層のすべてのストレージAPIは `ObjectType` を必須引数に取る。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use WP_Post;
use WP_Term;
use WP_User;
use TMSWP\Core\Exception\InvalidArgumentException;

enum ObjectType: string
{
    case Post = 'post';
    case Term = 'term';
    case User = 'user';

    /**
     * WPオブジェクト/数値IDからオブジェクトIDを解決する。
     *
     * @throws InvalidArgumentException 種別不一致・0以下のID
     */
    public function resolveId(WP_Post|WP_Term|WP_User|int $object): int;

    /** get_metadata 等に渡すメタ種別文字列(= backing value) */
    public function metaType(): string;
}
```

#### 振る舞い仕様

- backing value は WPの `*_metadata($meta_type, ...)` の `$meta_type` と一致させる(`'post'`/`'term'`/`'user'`)。`metaType()` は `$this->value` を返す薄いメソッド。
- `resolveId()` の解決規則:
  - `self::Post` + `WP_Post` → `$object->ID`。`WP_Term`/`WP_User` が渡されたら `InvalidArgumentException`(メッセージに期待種別と実際のクラス名を含める)。
  - `self::Term` + `WP_Term` → `$object->term_id`。他オブジェクトは同様にエラー。
  - `self::User` + `WP_User` → `$object->ID`。他オブジェクトは同様にエラー。
  - `int` → そのまま返す。ただし `$object < 1` は `InvalidArgumentException`。IDの実在チェックは行わない(存在しないIDへのget は has()=false / get()=null として正常系で扱う)。

### 4.4 `TMSWP\Core\Meta\MetaSchemaDefinition` / `TMSWP\Core\Meta\MetaSchemaSet`(新設)

#### 役割

`#[MetaSchema]` 属性を Kernel の boot の中(エンティティの boot 時の初期化)で検証・正規化した不変の値オブジェクトと、エンティティ1クラス分のスキーマ集合。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

final class MetaSchemaDefinition
{
    /**
     * @param class-string $ownerClass 定数を宣言したエンティティクラス
     * @param string $constantName 定数名(例: 'META_PRICE')
     * @param string $key メタキー(定数値)
     * @param bool|array<string, mixed> $showInRest
     * @param string|array{class-string, string}|null $sanitize
     */
    public function __construct(
        public readonly string $ownerClass,
        public readonly string $constantName,
        public readonly string $key,
        public readonly MetaValueType $type,
        public readonly mixed $default,
        public readonly bool $single,
        public readonly bool|array $showInRest,
        public readonly string $description,
        public readonly string|array|null $sanitize,
    ) {
    }

    /**
     * リフレクションで取得した属性インスタンスから生成・検証する。
     *
     * @param class-string $ownerClass
     * @throws \TMSWP\Core\Exception\InvalidSchemaException 4.1の検証規則違反
     */
    public static function fromAttribute(
        string $ownerClass,
        string $constantName,
        string $key,
        \TMSWP\Core\Attribute\MetaSchema $attribute,
    ): self;

    public function hasDefault(): bool;          // default !== null
}

final class MetaSchemaSet
{
    /** @param array<string, MetaSchemaDefinition> $definitions メタキー => 定義 */
    public function __construct(private readonly array $definitions)
    {
    }

    public function has(string $key): bool;
    public function get(string $key): ?MetaSchemaDefinition;

    /** @return array<string, MetaSchemaDefinition> */
    public function all(): array;

    /** @return list<string> 宣言済みメタキーの一覧 */
    public function keys(): array;

    public static function empty(): self;
}
```

#### 振る舞い仕様

- `fromAttribute()` は 4.1 の検証規則(default互換・single×default禁止・sanitize形式・キー文字列非空・REST に出す Array/Object 型のスキーマ)をすべて適用し、違反時は `InvalidSchemaException` にエンティティクラス名・定数名を含めて送出する(type は `MetaValueType` 受けのため検証対象外。4.1)。
- 同一エンティティ内でのキー重複(異なる定数が同じメタキー文字列を持つ)は `MetaSchemaSet` 構築時ではなく `MetaSchemaRegistry::registerEntity()`(4.9)が検出して `DuplicateMetaKeyException` を送出する。

### 4.5 `TMSWP\Core\Meta\MetaStorageInterface`

#### 役割

メタ値の物理ストレージ抽象。**`has()` と `get()` を分離**することで「存在しない」と「null/false/空文字が保存されている」を区別し、旧FWの null センチネル(フィルタがnullを返すとWP標準へフォールスルーするため、外部ストレージから正当なnull相当値を返せない)を全廃する。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

/**
 * 書き込み(set・setAll・delete)は、書き込んだかどうかを bool で返し、データベースのエラーは例外で知らせる。
 * false は失敗ではなく「書き込まなかった」(同じ値、ほかのプラグインのフィルタによる中止、delete では行が無かった)
 * を意味し、保存されている値は変わっていない。
 * null は保存しない。削除は delete() で行う。
 */
interface MetaStorageInterface
{
    /** 指定キーのメタ行が存在するか(値の真偽ではなく行の有無) */
    public function has(ObjectType $type, int $objectId, string $key): bool;

    /**
     * 単一値を取得する。行が存在しない場合は null を返す。
     * 呼び出し側は「存在判定」に本メソッドを使ってはならず、必ず has() を併用する。
     */
    public function get(ObjectType $type, int $objectId, string $key): mixed;

    /**
     * 同一キーの全行を取得する(single=false スキーマ用)。
     *
     * @return list<mixed> 行が存在しない場合は空配列
     */
    public function getAll(ObjectType $type, int $objectId, string $key): array;

    /**
     * 複数キーをまとめて取得する。
     *
     * @param list<string> $keys
     * @return array<string, mixed> 存在するキーのみを含む(存在しないキーは結果に含めない)
     */
    public function getMultiple(ObjectType $type, int $objectId, array $keys): array;

    /**
     * 単一値を保存する(既存行は置換)。
     *
     * @return bool 書き込んだら true、書き込まなかったら false(保存値は変わっていない)
     * @throws \TMSWP\Core\Exception\InvalidArgumentException 保存できない値(null 等)
     * @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー
     */
    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool;

    /**
     * 同一キーの全行を置換する(single=false スキーマ用)。
     *
     * @param list<mixed> $values
     * @return bool 保存された行を変えたら true、置換前と同じ行なら false
     * @throws \TMSWP\Core\Exception\InvalidArgumentException 保存できない値(null 等)
     * @throws \TMSWP\Core\Exception\RuntimeException 削除・追加でのデータベースのエラー
     */
    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool;

    /**
     * 指定キーの全行を削除する。行が元々無い場合は何もせず false を返す(冪等)。
     *
     * @return bool 行を削除したら true、元々行が無かった・削除しなかったら false
     * @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー
     */
    public function delete(ObjectType $type, int $objectId, string $key): bool;
}
```

#### 実装者への規約

- 実装は **null を「特別な意味」に使ってはならない**。`get()` の null は「行なし」または「null相当値」のどちらでもあり得る曖昧な値であり、意味判定は常に `has()` が担う。
- `getMultiple()` は「キーが結果配列に存在するか」で has 相当の情報を持ち帰る。値がnullでもキーは含める。
- 書き込みの戻り値は「書き込んだか」であり、成否ではない。false は「書き込まなかった(保存値は変わっていない)」を意味する。データベースのエラー等で書き込みに失敗したら、`false` を返さずに例外(FW の基底 `RuntimeException`。4.17)を投げる。「書き込まなかった」と「失敗した」を戻り値で区別できないストレージ API(WP の `update_metadata()` 等)を使う実装は、実装の中で両者を判別する(4.6)。
- `null` は保存しない(`MetaBag::set()` が拒否する。4.8)。削除は `delete()` に一本化する。
- テストで既定のストレージと差し替える実装(4.16)も、この規約(書き込みの戻り値と `null` の拒否)を守る。データベースに依存しない契約は、契約テストの trait(`MetaStorageContract`)で確かめられる。

### 4.6 `TMSWP\Core\Meta\WpMetaStorage`

#### 役割

`MetaStorageInterface` のWP標準実装。WPメタAPI(`metadata_exists` / `get_metadata_raw` / `get_metadata` / `update_metadata` / `add_metadata` / `delete_metadata`)への薄い委譲であり、本クラスがコアのデフォルトストレージとなる(Kernel が Container の `MetaStorageInterface` の id に登録する。4.16)。WP関数呼び出しを本クラスに閉じ込めることで、`MetaBag` 以上の層をWP非依存でユニットテスト可能にする(DR-12の2層テスト戦略。テストでは既定のストレージを差し替える。4.16)。

コンストラクタで Kernel を受け取り、単独の読み取りのデータベースのエラーをその Kernel の debug とロガーで扱う(下の「データベースのエラーの判定」)。Container が解決したインスタンスは、その Container を持つ Kernel を受け取る。エンティティの `meta()` は `Kernel::forClass(エンティティクラス)` の Container から `MetaBag` を作るため、エンティティの Kernel の debug とロガーに従う。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use TMSWP\Core\Kernel\Kernel;

final class WpMetaStorage implements MetaStorageInterface
{
    /** @param Kernel $kernel 単独の読み取りのデータベースのエラーを扱う debug とロガーの Kernel */
    public function __construct(Kernel $kernel);

    public function has(ObjectType $type, int $objectId, string $key): bool;
    public function get(ObjectType $type, int $objectId, string $key): mixed;
    public function getAll(ObjectType $type, int $objectId, string $key): array;
    public function getMultiple(ObjectType $type, int $objectId, array $keys): array;
    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool;
    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool;
    public function delete(ObjectType $type, int $objectId, string $key): bool;

    /** @internal エンティティの create() が wp_insert_post()・wp_insert_user() の meta_input に渡す配列を作る */
    public static function slashMetaInput(array $metaInput): array;
}
```

#### 振る舞い仕様(WP API対応表)

| メソッド | 実装 |
| --- | --- |
| `has()` | `get_metadata_raw($type->metaType(), $objectId, $key, false)` が行を返せば true。null・false・空の配列とスカラーの空文字 `''` は行なし、空でない配列は各行(`''`・`'0'` の行も行として数える)、それ以外の値(フィルタが返した空でないスカラー。WP のサムネイルのプレビューのフィルタ `_wp_preview_post_thumbnail_filter` は single が false でもサムネイルの ID を文字列で返す)は1行として扱う。スカラーの `''` を行なしとするのは、アイキャッチを外すプレビューで `_wp_preview_post_thumbnail_filter` が `''` を返し、WP 自身も `metadata_exists()`・`has_post_thumbnail()` を false とするためである。`metadata_exists()` は使わない(`get_{$meta_type}_metadata` フィルタの結果を `(bool)` にするため、プレビューのフィルタ(4.11)が `'0'`・`''` を返すと、行があっても false になる) |
| `get()` | `has()` と同じ `get_metadata_raw($type->metaType(), $objectId, $key, false)` の読み込みで、先頭の行(`maybe_unserialize` 済み)を返す。行が無ければ null(WPメタは null を物理保存できないため、raw の null ⇔ 行なし が成立する。行の有無と行の扱いは `has()` と同じなので、`has()` が false なら必ず null) |
| `getAll()` | `array_values(get_metadata_raw($type->metaType(), $objectId, $key, false) ?? [])`(single=false かつ行なし時の null を `?? []` で吸収) |
| `getMultiple()` | 要求キーごとに `get_metadata_raw($type->metaType(), $objectId, $key, false)` を呼び、行を返したキーだけを、先頭行(`maybe_unserialize` 済み)で返す(行の有無と行の扱いは `has()` と同じ)。single=false のキーがここで要求された場合も先頭行のみ返す(バルクは単一値スキーマ用途)。キーごとに読むのは、`get_{$meta_type}_metadata` フィルタ(プレビューの `_wp_preview_meta_filter` 等)をキーごとに効かせるためである(キーを `''` にして全メタを読む `get_metadata($type, $id)` は、キーを指定しないためプレビューのフィルタに当たらない)。最初の読み込みが `update_meta_cache()` で対象のメタをすべてメタのキャッシュに載せ、残りのキーはキャッシュから読むため、データベースへの問い合わせは全メタを1回で読む場合と同じく1回である。キーの数だけフィルタの適用とキャッシュの参照が増えるが、メタのキー数(数十程度)ではデータベースの問い合わせに比べて無視できる。データベースのエラーの判定もキーごとに行い(次のクエリの `wpdb::query()` が `last_error` を空にするため)、本番でエラーになったキーは結果に含めない |
| `set()` | `update_metadata($type->metaType(), $objectId, wp_slash($key), wp_slash($value))`。WPの戻り値 int(新規meta_id)/true は true。false の場合は表の下の「データベースのエラーの判定」に従う |
| `setAll()` | 置換の前に行を読み(`getAll()`)、行があれば `delete_metadata(...)`、その後 `$values` を順に `add_metadata(...)`(キーと値は `wp_slash()` して渡す)。どれもデータベースのエラーなら `RuntimeException`(ロールバックはしない。wpdbにトランザクション保証がないため、途中で失敗した場合は一部の行だけが置き換わった状態で残る)。フィルタが削除を止めた場合は、行を足すと置換にならないため何も追加せず false。置換の前後で保存された行(DB に書かれる文字列の形の list)が変わったら true、変わらなければ false。置換の前後の読み取りのデータベースのエラーも `RuntimeException`(読み取りの失敗を「行が無い」として扱わない) |
| `delete()` | 行が無ければ false(`has()` を先行確認。この読み取りのデータベースのエラーは `RuntimeException`)。あれば `delete_metadata($type->metaType(), $objectId, wp_slash($key))` を呼び、削除できたら true、WP が false を返したら下の判定に従う(データベースのエラーなら `RuntimeException`、そうでなければ false) |

- `get_metadata_raw()`(WP 5.5+)はコアの default 注入層(`get_metadata_default()` / `filter_default_metadata`)を経由しないため、`register_meta` の default 介在が原理的に起きない。default 介在の回避は実装内の順序契約(`has()` の先行呼び出し)ではなく、コアAPI 1呼び出しの保証による。同関数は `_` 接頭辞なし・`@access private` 注記なしの正規の公開関数(`@since 5.5.0`)で、コア自身が default 機構の土台として安定使用している。なおこの最適化はWP実装(`WpMetaStorage`)固有であり、`AcfMetaStorage` 等の外部ストレージ実装には適用されない。
- `update_metadata` を経由するため、`register_meta` で登録した `sanitize_callback` は `sanitize_meta()` フィルタとしてWPコアが自動適用する。FW側で二重適用しない(書き込みの false の判定のためにも再実行しない)。
- **データベースのエラーの判定**(書き込み `set()`・`setAll()`・`delete()` と読み取り `has()`・`get()`・`getAll()`・`getMultiple()`。4.14 の `WpOptionStorage` も同じ。実装は内部クラス `WpDatabaseGuard`): WP の書き込み関数は「同じ値」「ほかのプラグインのフィルタ(短絡フィルタ `update_{$meta_type}_metadata` 等)による中止」「データベースのエラー」のどれでも false を返し、読み取り関数はクエリの失敗を「行が無い」と同じ結果で返す。値を比べて区別するのではなく、データベースのエラーで判定する。
  - 判定: 関数を呼ぶ前に `$wpdb->num_queries` を控え、呼び出しの間に `num_queries` が増えており、かつ `$wpdb->last_error !== ''` ならデータベースのエラーとする。キャッシュから読んでクエリを発行しなかった読み取りと、呼び出しの前の無関係なクエリのエラーは、エラーにしない。
  - 書き込み: 戻り値が false で、データベースのエラーなら `RuntimeException`(メッセージに `last_error` を含める)。それ以外の false は「書き込まなかった」として false を返す(保存値は変わっていない)。
  - 書き込み操作の中で行う読み取り(`setAll()` の置換の前後の行の取得、`delete()` の前の存在の確認、`WpOptionStorage` の `set()`・`delete()` の前の存在の確認): データベースのエラーなら、debug に関係なく `RuntimeException` にする。読み取りの失敗を「行が無い」として扱うと、置換や削除を誤るため。
  - 公開の読み取り(`has()`・`get()`・`getAll()`・`getMultiple()`)を単独で呼んだとき: エラー方針(00-overview §5.6。実行時のデータ取得失敗)に従う。コンストラクタで受け取った Kernel の debug では `RuntimeException`(メッセージは `failed to read {metaType} meta '{key}' for object {id}: {last_error}`)。本番ではその Kernel のロガー(`$kernel->logger()`)の `error()` に `tmswp.storage: {failure}: {error}` で記録し、行が無いときと同じ結果(`has()` は false、`get()` は null、`getAll()`・`getMultiple()` は空配列。`getMultiple()` はエラーになったキーを除く)を返す。値が無いこととデータベースのエラーを区別できないまま、エラーを黙って捨てないためである(D-47)。
  - 失敗した読み込みのキャッシュ: WP の読み取り関数は、読み込みの SELECT が失敗しても、結果を「無い」としてオブジェクトキャッシュに書く。そのままでは、後の同じ対象の読み取りがクエリを発行せずに黙って「無い」を返す(永続的なオブジェクトキャッシュでは後のリクエストも同じ)。そのため、読み取り(単独の読み取りと、書き込み操作の中の読み取りの両方)でデータベースのエラーを検出したら、例外を投げる前・ログに記録する前に、その読み込みで WP が書いたキャッシュだけを消す。メタでは、WP 7.0 の `update_meta_cache()`(wp-includes/meta.php)が、`update_{$meta_type}_metadata_cache` フィルタが null を返したら、渡された ID のうち `wp_cache_get_multiple()` がキャッシュに無い(false)とした ID を1回の SELECT で読み、SELECT が失敗してもそれらの ID を空の配列として `wp_cache_add_multiple()` で書く(グループ `{$meta_type}_meta`、キーは ID)。読み込む ID は読み取りの対象だけとは限らない(`get_metadata_raw()` のフィルタで動く `WP_Metadata_Lazyloader` は、キューにあるほかの対象もまとめて読む)。そのため、読み取りの間だけ `update_{$meta_type}_metadata_cache` フィルタを最後の priority で付け、`$check` が null の呼び出しで、その時点でキャッシュに無い ID を記録する(フィルタは `$check` を変えずに返し、読み取りの後に外す)。後始末は、記録した ID のキャッシュだけを `wp_cache_delete()` で消す。消した後の読み取りは、データベースから読み直す。オプションは 4.14。
  - 根拠(WP 7.0): `wpdb::query()` は実行の前に `flush()` で `last_error` を空にし、実行後に `mysqli_error()` を入れる。クエリを実際に発行すると `wpdb::_do_query()` が `num_queries` を増やす(wp-includes/class-wpdb.php)。`update_metadata()`・`add_metadata()`・`delete_metadata()`(wp-includes/meta.php)は、書き込みのクエリが失敗するとほかのクエリを発行せずにすぐ false を返すため、失敗したときの最後のクエリは書き込みのクエリになる。呼び出しの前の無関係なクエリのエラーを拾わないために、呼び出しの間に `num_queries` が増えた場合だけ `last_error` を見る。
  - 受け入れる限界(2 は書き込みだけ。1・3・4 は書き込み・書き込み操作の中の読み取り・単独の読み取りのすべてに当てはまる):
    1. 呼び出しの間にほかのフックが発行したクエリが失敗して最後のクエリとして残った場合は、書き込み関数・読み取り関数そのものが成功していてもデータベースのエラーとして扱う(fail-fast。単独の読み取りでは、debug は `RuntimeException`、本番は記録して行が無いときと同じ結果)。
    2. `delete_option()` は削除のクエリが失敗した後もキャッシュを更新し、autoload のオプションで `alloptions` のキャッシュが無いときは読み直しのクエリを発行する。その場合は削除のエラーが `last_error` から消え、false(書き込まなかった)になる(4.14)。
    3. `wpdb::query()` が `num_queries` を増やさずに `last_error` だけを入れる経路(不正なデータを含むクエリの拒否)では、`num_queries` が増えないため、書き込みは false(書き込まなかった)、読み取りは成功として扱う。
    4. 接続が切れて再接続にも失敗した場合、`wpdb::query()` は `last_error` を入れる前に false を返すため、書き込みは false(書き込まなかった)、読み取りは成功として扱う。データベースのサーバーの障害の経路であり、そのときはほかの処理も目に見えて失敗する。`wpdb` の内部(接続ハンドル等)に依存して検出すると WP の版の変更で壊れやすいため、検出しない。
  - サニタイズやフィルタは再実行しない。WP が1回だけ適用する。
- slash 処理: `update_metadata()`・`add_metadata()`・`delete_metadata()` は、キーと値が slash 済み(magic quotes を付けた `$_POST` 等と同じ形)である前提で内部で `wp_unslash()` する。本クラスは受け取った値を元の値として保存するため、キーと値を `wp_slash()` してから渡す。呼び出し側(`MetaBag` の利用者・Form 層)は unslash 済みの元の値を渡し、自分で `wp_slash()` しない(二重に slash すると backslash が残る)。これによりバックスラッシュを含む文字列(`json_encode()` の出力、Windows のパス、正規表現)がそのまま往復する。
- 保存できない値: `null`(WP のメタは null を保存できず空文字になる)と、object を含む値(`wp_slash()` は object を扱わず、`update_metadata()` の `wp_unslash()` が object のプロパティの文字列をその場で書き換えるため、元の値として往復しない)は `InvalidArgumentException` で拒否する。
- 真偽値の保存: WP のメタは値を文字列の列に保存するため、`true` は `'1'`、`false` は `''` として保存され、`get()` は `'1'`・`''` を返す(`false` は返らない)。`false` を保存した行も行としてあり、`has()` は true である。Boolean のスキーマのキーは、`MetaBag::bool()` で読めば `ValueCaster::toBool()` が `''` を false に戻す(4.8)。
- `slashMetaInput()`: エンティティの `create()`(03文書 5.3節・12.2節)が `wp_insert_post()`・`wp_insert_user()` の `meta_input` に渡す配列を作る。WP は `meta_input` のキーと値を `update_post_meta()`・`add_user_meta()` で保存し、そこで `wp_unslash()` するため、`set()` と同じ規則で値を検査し(`null` と object を含む値は `InvalidArgumentException`)、キーと値を `wp_slash()` して返す。

### 4.7 `TMSWP\Core\Meta\ValueCaster`(新設・内部クラス)

#### 役割

スキーマ型(`MetaValueType`)に基づく決定的な型キャスト規則の単一実装。`MetaBag`(4.8)と `OptionRepository`(4.15)が共用する。キャスト規則を1箇所に固定することで、メタとオプションで挙動が食い違うことを防ぐ。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

final class ValueCaster
{
    public static function toInt(mixed $value): int;
    public static function toFloat(mixed $value): float;
    public static function toBool(mixed $value): bool;
    public static function toString(mixed $value): string;

    /** @return list<mixed> */
    public static function toList(mixed $value): array;

    /** @return array<array-key, mixed> */
    public static function toJsonArray(mixed $value): array;

    /** スキーマの default 値が type とキャスト互換かをブート時検証するための判定 */
    public static function accepts(MetaValueType $type, mixed $value): bool;
}
```

#### キャスト規則(確定仕様)

| メソッド | 入力 → 出力 |
| --- | --- |
| `toInt()` | int→そのまま / float→`(int)` / bool→0・1 / `is_numeric()` な string→`(int)(float)` / それ以外(非数値文字列・array・object・null)→`0` |
| `toFloat()` | float→そのまま / int→`(float)` / bool→0.0・1.0 / `is_numeric()` な string→`(float)` / それ以外→`0.0` |
| `toBool()` | bool→そのまま / `''`・`'0'`・`'false'`(小文字比較)・`0`・`0.0`・null・`[]`→false / それ以外→true |
| `toString()` | string→そのまま / int・float→`(string)` / bool→`'1'`・`''` / `Stringable`→`(string)` / array・object・null→`''` |
| `toList()` | array→`array_values()` / null→`[]` / それ以外のスカラー→`[$value]`(1要素list) |
| `toJsonArray()` | array→そのまま(WPの `maybe_unserialize` 済み配列をそのまま返す) / string→`json_decode($value, true)`。decode失敗(`json_last_error() !== JSON_ERROR_NONE`)または結果が非array→`[]` / それ以外→`[]` |

- `accepts(type, value)` の判定: String→`is_scalar()`(`Stringable` のオブジェクトは受け付けない。オプションは object をシリアライズして保存でき、メタ(4.6)は保存できないため、同じスキーマの規則でメタとオプションの結果が食い違わないよう、型の検査で揃えて拒否する。文字列にしたい場合は呼び出し側が `(string)` にして渡す)、Boolean→`is_bool()`、Integer→`is_int()`、Number→`is_int() || is_float()`、Array→`is_array()`、Object→`is_array()`(連想配列で表現)。スキーマの `default` はキャストに頼らず宣言時点で正しい型を書かせる(緩い受け入れはしない)。同じ判定を、宣言済みキーへの書き込み(`MetaBag::set()`・`OptionRepository::set()`)の型検査にも使う。

### 4.8 `TMSWP\Core\Meta\MetaBag`

#### 役割

1オブジェクト(投稿1件・ターム1件・ユーザー1件)に対するメタ読み書きの型付きファサード。エンティティ基底の `ProductPost::meta($post)` が返すオブジェクトであり、テンプレート・app層が直接触るのは原則このクラスだけである。値のキャッシュは持たない(WPメタキャッシュ/各ストレージのキャッシュに委ねる)軽量オブジェクトで、呼び出しごとに生成してよい。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

/**
 * @template TEntity of object
 */
final class MetaBag
{
    /**
     * 直接 new しない。MetaSchemaRegistry::bagFor() 経由で生成する。
     *
     * @param class-string<TEntity> $entityClass
     * @param \Closure(): bool $registeredWithWordPress スキーマを WordPress に登録済み(registerAll() の後)なら true
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly ObjectType $objectType,
        private readonly int $objectId,
        private readonly MetaStorageInterface $storage,
        private readonly MetaSchemaSet $schemas,
        private readonly \Closure $registeredWithWordPress,
    ) {
    }

    /** @return class-string<TEntity> */
    public function entityClass(): string;
    public function objectType(): ObjectType;
    public function objectId(): int;

    // ---- 読み取り ----

    /** 生値取得。解決順: ストレージ → 引数default → スキーマdefault → null */
    public function get(string $key, mixed $default = null): mixed;

    public function int(string $key, ?int $default = null): int;
    public function float(string $key, ?float $default = null): float;
    public function bool(string $key, ?bool $default = null): bool;
    public function string(string $key, ?string $default = null): string;

    /** @return list<mixed> single=false スキーマでは全行、それ以外は配列値を list 化 */
    public function list(string $key, ?array $default = null): array;

    /** @return array<array-key, mixed> JSON文字列/配列値を連想配列で */
    public function json(string $key, ?array $default = null): array;

    public function has(string $key): bool;

    /** @return array<string, mixed> 宣言済み全キーの値マップ(バルク取得)。各キーの値は get() と同じ */
    public function all(): array;

    // ---- 書き込み ----

    /**
     * @return bool 書き込んだら true、書き込まなかったら false(保存値は変わっていない)
     * @throws \TMSWP\Core\Exception\LogicException sanitize を宣言したキーを、スキーマの WordPress への登録
     *         (init 20 の MetaSchemaRegistry::registerAll())の前に書こうとした
     * @throws \TMSWP\Core\Exception\InvalidArgumentException null・スキーマの型に合わない値・single=false への非list
     * @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー
     */
    public function set(string $key, mixed $value): bool;

    /** @return bool 行を削除したら true、元々行が無かった・削除しなかったら false */
    public function delete(string $key): bool;
}
```

#### 振る舞い仕様

**値の解決順序(全getter共通)**:

1. `storage->has()` が true → ストレージの値を採用(型付きgetterは `ValueCaster` でキャスト)。
2. 引数 `$default` が非null → そのまま返す(キャストしない。型宣言により型は保証済み)。
3. スキーマに `default` がある → `ValueCaster` でキャストして返す。
4. いずれも無い → 型付きgetterは `MetaValueType` ではなく**メソッド自身の型のゼロ値**(`int()`→0、`string()`→''、`bool()`→false、`float()`→0.0、`list()`/`json()`→[])。`get()` は null。

**各メソッドの個別仕様**:

- `get($key, $default)`: キャストを行わない生値アクセス。single=false スキーマのキーに対しては `storage->getAll()` の list を返す。ストレージが保存した形のまま返すため、`WpMetaStorage` では `false` を保存したキーは `''` を返す(4.6)。Boolean のスキーマのキーを真偽値で読むには `bool()` を使う(`''` を false に戻す)。
- `int()`/`float()`/`bool()`/`string()`: 上記解決順 + `ValueCaster::to*()`。
- `list($key)`: スキーマが `single: false` → `storage->getAll()`(空配列なら解決順2以降へ)。`single: true` または未宣言キー → `storage->get()` の値を `ValueCaster::toList()`。
- `json($key)`: `storage->get()` の値を `ValueCaster::toJsonArray()`。
- `has($key)`: `storage->has()` への委譲。スキーマdefaultの有無は影響しない(「行が存在するか」のみ)。
- `all()`: **宣言済みキーのみ**が対象で、各キーの値は `get($key)` と同じになる(プレビュー(4.11)の値を含む)。single=true のキーは `storage->getMultiple($type, $id, single=true のキー)` を1回呼び、欠けたキーはスキーマdefault(無ければ null)で補完する(`WpMetaStorage::getMultiple()` はキーごとに読み、データベースへの問い合わせは1回。4.6)。single=false のキーは `get()` と同じく全行の list(行が無ければ null)を返す(`getMultiple()` は単一値用で先頭行しか返さないため使わない)。
- `set($key, $value)`: `sanitize` を宣言したキーは、スキーマを WordPress に登録する前(init priority 20 の `MetaSchemaRegistry::registerAll()` の前)に書こうとすると、debug に関係なく `LogicException`(キーとエンティティのクラスを含む)にする。WordPress が sanitize を適用するのは `register_*_meta` の後なので、sanitize を通らない保存をさせないためである(呼び出しの順序の誤りとして扱う)。`sanitize` を持たないキーの書き込み、読み取り、`delete()` は登録の前でも使える。`$value` が `null` なら `InvalidArgumentException`(宣言の有無に関係なく。削除は `delete()` を使う)。スキーマが `single: false` の場合、`$value` が list でなければ `InvalidArgumentException`、listなら各要素を検査して `storage->setAll()`。それ以外は値を検査して `storage->set()`。宣言済みキーの値(single=false では各要素)は `ValueCaster::accepts()`(4.7)でスキーマの型に合うことを確かめ、合わなければ `InvalidArgumentException`。未宣言キーは型を検査しない。キャストやサニタイズは行わない(WpMetaStorage 経由なら `register_meta` の sanitize_callback がWPコアで適用される。4.6参照)。戻り値はストレージの戻り値(書き込んだか)で、データベースのエラーはストレージが例外にする(4.5)。
- `delete($key)`: `storage->delete()` への委譲。

**未宣言キーの扱い(確定)**: `#[MetaSchema]` で宣言されていないキー文字列を渡してもエラーにしない。`_thumbnail_id` 等のWP組込みメタや他プラグインのキーを読む正当なユースケースがあるためである。ただし未宣言キーにはスキーマdefault・single=false分岐・REST登録・PHPStanナローイングのいずれも適用されない。タイポ検出は実行時例外ではなく、PHPStan拡張の定数所属検査(4.18)が担う。

**エンティティとの接続規約(rules/ に転記する事項)**:

- メタキーは必ず `ProductPost::META_PRICE` のように**所有エンティティの定数**で渡す。文字列リテラル直書きは未宣言キーアクセスとみなす。
- 頻出フィールドはエンティティに明示アクセサを書く(DR-05):

```php
public static function price(WP_Post|int $post): int
{
    return self::meta($post)->int(self::META_PRICE);
}
```

### 4.9 `TMSWP\Core\Meta\MetaSchemaRegistry`

#### 役割

ブート時にエンティティクラスのメタスキーマを収集・検証し、`init` フックで `register_*_meta` へ一括登録するレジストリ。エンティティクラスごとのストレージ解決(`#[UseMetaStorage]`)・リビジョン保存宣言の読み取り(`#[WithMetaRevisions]`。4.11)と `MetaBag` の生成も担う。コンテナ(PSR-11)にシングルトン登録され、Kernelと エンティティ基底の `meta()` から参照される。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use Psr\Container\ContainerInterface;
use WP_Post;
use WP_Term;
use WP_User;

final class MetaSchemaRegistry
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * エンティティ1クラスを登録する。エンティティの Initializer(PostTypeInitializer 等)が Kernel::boot() の中
     * (boot 時の初期化。BootInitializerInterface::initializeAtBoot())で呼ぶ。
     *
     * @param class-string $entityClass #[AsPostType|AsTaxonomy|AsUserRole] が付与されたクラス
     * @param string $subtype Post→投稿タイプスラッグ / Term→タクソノミースラッグ / User→''(固定)
     * @throws \TMSWP\Core\Exception\InvalidSchemaException 4.1/4.4の検証違反、
     *         #[WithMetaRevisions] の付与違反(4.11)
     * @throws \TMSWP\Core\Exception\DuplicateMetaKeyException 同一エンティティ内キー重複、
     *         または同一 (ObjectType, subtype, key) の組を別エンティティが二重宣言した場合
     * @throws \TMSWP\Core\Exception\LogicException registerAll() の後に呼んだ場合(WordPress に登録されない
     *         スキーマになるため)
     */
    public function registerEntity(string $entityClass, ObjectType $objectType, string $subtype): void;

    /** 登録済みエンティティのスキーマ集合(未登録クラスは empty セット) */
    public function schemasFor(string $entityClass): MetaSchemaSet;

    /** エンティティの ObjectType(未登録クラスは EntityNotRegisteredException) */
    public function objectTypeOf(string $entityClass): ObjectType;

    /**
     * MetaBag を生成する。エンティティ基底 meta() の実体。
     *
     * @template T of object
     * @param class-string<T> $entityClass
     * @return MetaBag<T>
     * @throws \TMSWP\Core\Exception\EntityNotRegisteredException
     * @throws \TMSWP\Core\Exception\InvalidArgumentException ObjectType::resolveId() 違反
     */
    public function bagFor(string $entityClass, WP_Post|WP_Term|WP_User|int $object): MetaBag;

    /**
     * 外部管理キーの申告(acf-bridgeがinit 6で呼ぶ)。$storageClassはそのキーを読み書きするストレージのクラス、
     * $originは申告元の説明(acf-bridgeは"ACF field group '{groupKey}'")で、重複の例外のメッセージに使う。
     * 申告したキーを#[MetaSchema]で宣言できるのは、読み書きのストレージ(#[UseMetaStorage]か既定のストレージを
     * Containerで解決したインスタンスのクラス)が$storageClassと同じエンティティだけで、そのキーは宣言どおり
     * register_*_metaされる。宣言の無い申告済みのキーはregister_*_metaされない。
     * #[MetaSchema]はbootの中で登録済みのため、重複はこの申告の時点で検出される。ストレージの違うエンティティの
     * #[MetaSchema]が同一(objectType, subtype, key)を宣言していればDuplicateMetaKeyException(DR-06)。
     * メッセージは、キー・(objectType, subtype)・宣言クラスと定数名・$origin・両方のストレージと解消方法を含む。
     * subtype は文字列どおりの1サブタイプだけを表す('all' という名前の投稿タイプも1つのサブタイプとして扱う)。
     *
     * @param class-string<MetaStorageInterface> $storageClass
     */
    public function markExternallyManaged(ObjectType $objectType, string $subtype, string $key, string $storageClass, string $origin): void;

    /**
     * ObjectTypeのすべてのサブタイプについての外部管理キーの申告(ACFのlocationがtaxonomy==all等の場合)。
     * いずれかのサブタイプの、ストレージが$storageClassでないエンティティの#[MetaSchema]が同じkeyを宣言していれば
     * DuplicateMetaKeyException。
     *
     * @param class-string<MetaStorageInterface> $storageClass
     */
    public function markExternallyManagedInAllSubtypes(ObjectType $objectType, string $key, string $storageClass, string $origin): void;

    /** markExternallyManaged() の (objectType, subtype, key) か、markExternallyManagedInAllSubtypes() の (objectType, key) に当たれば true */
    public function isExternallyManaged(ObjectType $objectType, string $subtype, string $key): bool;

    /**
     * init フック(priority 20)から1回だけ呼ばれ、全スキーマを register_*_meta へ登録する。
     * 2回目以降の呼び出しは LogicException。
     *
     * @throws \TMSWP\Core\Exception\InvalidSchemaException WP が登録を拒否した(register_*_meta が false を返した)
     */
    public function registerAll(): void;
}
```

#### 振る舞い仕様

**registerEntity() の処理手順(擬似コード)**:

```
0. registerAll() の後なら LogicException(登録しても WordPress に流れないため)
1. ReflectionClass($entityClass) の getReflectionConstants() で全定数を列挙し、#[MetaSchema] 属性を持つ定数のみ対象にする
   (定数名プレフィックス規約は課さない。属性の有無だけが宣言の根拠。META_ 接頭辞は推奨規約として rules/ に記載)。
   属性を持つ定数が public でなければ InvalidSchemaException(黙って飛ばさない)
2. 各定数 → MetaSchemaDefinition::fromAttribute() で検証付き生成
3. エンティティ内でメタキー文字列が重複 → DuplicateMetaKeyException
4. レジストリ全体で (objectType, subtype, key) が既登録 → DuplicateMetaKeyException
   (同一keyでも subtype が異なれば共存可。例: 'price' を product と shop の両CPTで宣言)
5. #[UseMetaStorage] 属性を読み取り、storageClass を記録(4.10)。属性が無ければ MetaStorageInterface::class
   (Kernel が既定のストレージを登録した Container の id。4.16)
6. #[WithMetaRevisions] 属性を読み取り(4.11)、付与されている場合は
   (a) objectType === ObjectType::Post であること
   (b) エンティティの definition() の supports が 'revisions' を含むこと
   を検証(違反は InvalidSchemaException)し、revisions フラグを記録
7. entityClass => {objectType, subtype, MetaSchemaSet, storageClass, revisions} を内部マップへ格納
```

- 親クラスから継承した定数も `getReflectionConstants()` に含まれるが、**宣言クラス(`ReflectionClassConstant::getDeclaringClass()`)が `$entityClass` 自身であるものだけ**を対象とする。エンティティ継承によるスキーマの暗黙共有は行わない(明示性優先)。
- `registerEntity()` は Kernel の boot の中で、探索結果ファイル(本番)か走査(debug)が収集したエンティティのコンポーネントごとに1回呼ばれる。そのため boot の後なら、init の前でも、app の `#[Action('init')]` の中でも `meta()`(`bagFor()`)が使え、宣言の誤り(4.1・4.4・4.10・4.11)は debug に関係なく boot の例外になる。
- メタスキーマ自体は探索結果ファイル(Kernel設計書 §6)に載せない。探索結果ファイルが持つのはコンポーネントの属性と引数だけで、メタスキーマは `registerEntity()` が boot のたびに定数と属性のリフレクションで構築する。そのため `#[UseMetaStorage]`・`#[WithMetaRevisions]` も、探索結果ファイルの経路で失われない(経路はこの1つだけである)。

**bagFor() の処理手順**:

```
1. 内部マップから entityClass のエントリを取得。なければ EntityNotRegisteredException
   (メッセージ例: "ProductPost is not registered. Did you forget #[AsPostType]?")
2. objectType->resolveId($object) でIDを解決
3. storageClass(属性が無ければ MetaStorageInterface::class。既定は WpMetaStorage)を container->get() で解決
4. new MetaBag(entityClass, objectType, id, storage, schemas, fn(): bool => registerAll() が完了したか) を返す
   (第6引数の Closure は、呼ぶたびにこのレジストリの登録の状態を返す。MetaBag は sanitize を宣言したキーの
   書き込みのたびにこれを呼ぶため、登録の前に作った MetaBag も登録の後は書ける。4.8)
```

**registerAll() の処理手順**:

```
foreach 登録済みエンティティ:
  foreach MetaSchemaSet:
    args = [
      'type'              => definition->type->value,
      'description'       => definition->description,
      'single'            => definition->single,
      'show_in_rest'      => definition->showInRest,
    ]
    if definition->hasDefault():        args['default'] = definition->default
    if definition->sanitize !== null:
        is_callable チェック → 不成立なら InvalidSchemaException
        args['sanitize_callback'] = definition->sanitize
    if objectType === Post かつ エンティティに #[WithMetaRevisions] 付与済み:
        args['revisions_enabled'] = true                          # WP 6.4+(4.11)
    registered = match objectType:
      Post: register_post_meta(subtype, key, args)
      Term: register_term_meta(subtype, key, args)
      User: register_meta('user', key, args)                      # userにsubtypeは無い
    if registered !== true: InvalidSchemaException                # WP が登録を拒否した(黙殺しない)
```

- 外部管理のキー(ACF管理下。DR-06)は、`#[MetaSchema]`で宣言していなければ登録済みのスキーマに含まれないため、`register_*_meta`されない。申告と同じストレージのエンティティが宣言したキーは、他のキーと同じく宣言どおり`register_*_meta`される(ストレージの違うエンティティの宣言は`DuplicateMetaKeyException`になる)。
- registerAll() が最後まで成功すると、以降は sanitize を宣言したキーにも `MetaBag::set()` で書き込める(4.8)。
- フック登録はKernelが行う: `add_action('init', $registry->registerAll(...), 20)`(第一級callable構文。本番の遅延bootではキャッチアップの規則に従う。Kernel設計書§3.3)。priority 20にするのは次の2点のためである。(1) acf-bridgeが外部管理のキーを申告するinit 6の後に流す(ストレージの違う`#[MetaSchema]`と重なる申告は、その時点で例外になり、`register_*_meta`に進まない。DR-06)。(2) WordPressの`register_meta()`は`revisions_enabled`を`post_type_supports($subtype, 'revisions')`で確かめて満たさなければ登録を拒否するため、エンティティの`register_post_type`/`register_taxonomy`(init 9・10、エンティティ設計書)を確実に先行させる。リビジョン保存宣言は属性(4.11)でありregisterEntity()時点で確定しているため、initより前に呼ぶべき有効化処理は存在しない。
- エンティティの `register()` が例外を投げても(本番を含む。設定の誤りのため隔離しない。Kernel設計書 §7)、スキーマは boot で登録済みであり、`register()` の失敗によってスキーマの登録が黙って飛ばされることはない。例外は init から伝わり、そのリクエストでは registerAll() に進まない。
- 旧FWの `insert_default_meta`(新規作成時に空文字メタ行を自動挿入し meta_query EXISTS を成立させる機能)は**踏襲しない**。デフォルト値は `register_meta` の `default` と `MetaBag` の解決順で供給される。EXISTS/ORDER BY meta_value が必要なケースの対処(保存フックでの明示挿入)は rules/ のレシピとして提供する。

### 4.10 `TMSWP\Core\Attribute\UseMetaStorage`

#### 役割

エンティティクラス単位でメタストレージ実装を差し替える明示オプトイン属性。旧FWの `iwf2b/meta/get|set|delete` グローバルフィルタ(全エンティティへ無差別適用・nullセンチネル・フィルタ名不一致で実際は機能していなかった)の置き換えであり、「どのエンティティがACF管理か」がクラス宣言から一目で分かることを設計目標とする(DR-18)。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Meta\MetaStorageInterface;

#[Attribute(Attribute::TARGET_CLASS)]
final class UseMetaStorage
{
    /**
     * @param class-string<MetaStorageInterface> $storageClass
     */
    public function __construct(
        public readonly string $storageClass,
    ) {
    }
}
```

#### 振る舞い仕様

- `MetaSchemaRegistry::registerEntity()` が読み取り、`bagFor()` でのストレージ解決に使う。属性なし時は `MetaStorageInterface::class`(Kernel が既定のストレージを登録した Container の id。何も登録しなければ `WpMetaStorage`。4.16)。属性で明示したクラスは、そのクラスの id で解決するため、既定のストレージの差し替え(4.16)の影響を受けない。
- `storageClass` がコンテナで解決できない、または `MetaStorageInterface` 非実装の場合、`registerEntity()` 時に `InvalidSchemaException`(遅延させず起動時に失敗させる)。
- 1クラスに複数付与した場合(`IS_REPEATABLE` ではないためPHPがコンパイルエラーにする)は考慮不要。
- 利用例(acf-bridge 利用時):

```php
#[AsPostType]
#[UseMetaStorage(\TMSWP\AcfBridge\AcfMetaStorage::class)]
final class ProductPost extends PostType { /* ... */ }
```

- ストレージ差し替えは**読み書き経路のみ**に影響する。`register_*_meta`の発行可否は`#[MetaSchema]`の宣言の有無が決める(外部管理の申告だけのキーは発行しない)。acf-bridgeは「ACF管理キーをmarkExternallyManaged系で申告」+「そのキーを宣言するエンティティに`#[UseMetaStorage(AcfMetaStorage::class)]`を付けさせる」の両輪で統合する(申告とストレージの違うエンティティの宣言は重複エラー。acf-bridge設計書§7.3)。

### 4.11 `TMSWP\Core\Attribute\WithMetaRevisions`

#### 役割

「プレビュー時にメタの変更が反映されない」というWPの古典的問題への、ACF非依存のオプトイン対応。旧FWでは `AbstractHook::save_preview_post`(`wp_insert_post` フックでリビジョン保存を検知し独自アクション `save_preview_postmeta` を発火、ACF実装が `$_POST['acf']` をリビジョンへ書き込む)が担っていたが、本FWの下限であるWP 7.0はメタのリビジョン保存をコア機能として持つ(`register_post_meta` の `revisions_enabled` 引数)。よって独自のフック連鎖も命令的な有効化サービスも実装せず、`register_post_meta` の引数と1:1対応する**クラス属性による宣言**として設計する。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;

/**
 * 付与されたエンティティの全 #[MetaSchema] キーをリビジョン保存対象にする(WP 6.4+ revisions_enabled)。
 * ObjectType::Post のエンティティ(かつ supports に 'revisions' を含む投稿タイプ)のみ付与可。
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class WithMetaRevisions
{
}
```

#### 振る舞い仕様

- `MetaSchemaRegistry::registerEntity()`(4.9)が読み取り、(1) エンティティの `ObjectType` が `ObjectType::Post` であること、(2) エンティティの `PostTypeDefinition` が `supports` に `'revisions'` を含むことをブート時に検証する。違反は `InvalidSchemaException`。`supports` は `#[AsPostType]` 属性ではなく runtime メソッド `definition()` が保持する(エンティティ設計書)ため、検証は `registerEntity()` が `definition()` を参照して行う。
- 付与されたエンティティのメタは `register_post_meta(..., ['revisions_enabled' => true])` で登録され(4.9)、以降の保存・プレビュー・リビジョン復元はすべてWPコアが処理する: 投稿保存/オートセーブ時にメタがリビジョンへ複製され、プレビュー表示時はWPコアの `_wp_preview_meta_filter`(`get_post_metadata` フィルタ)がオートセーブリビジョンの値へ読み替える。FW独自のフックは一切追加しない。
- プレビューの値は `MetaBag` のすべての読み取り(`has()`・`get()`・型付きgetter・`all()`)で読める。そのため `WpMetaStorage`(4.6)は、行の有無を `get_metadata_raw(..., false)` が行を返したか(フィルタが返した空でないスカラーも1行。アイキャッチを差し替えるプレビューで `_wp_preview_post_thumbnail_filter` が返す ID を含む。アイキャッチを外すプレビューでこのフィルタが返すスカラーの `''` は、WP と同じく行なし)で判定し(`metadata_exists()` はフィルタの結果を `(bool)` にするため、オートセーブの値が `'0'`・`''` だと行が無いと判定され、スキーマdefaultが返る)、`getMultiple()` をキーごとに読む(全メタを読む `get_metadata($type, $id)` はキーを指定しないため、プレビューのフィルタに当たらない)。例えば Integer のキー(default 100)をオートセーブで 0 にしてプレビューすると、`int()` は 0、`all()` はそのキーに `'0'` を返す。
- 宣言は属性であるため「いつ有効化するか」という時間軸の概念が存在しない。宣言は `registerAll()`(init 20)実行前に構造上確定しているため、呼び出し順序の契約・手遅れ時の例外・レジストリの可変メソッドを持たない(設計根拠6)。
- `#[MetaSchema]`で宣言していないACF管理下キー(`isExternallyManaged()` = true)はそもそも`register_post_meta`されないため本機構の対象外。ACFフィールドのプレビュー対応はacf-bridge側の責務とする(本書はスコープ外と明記するに留める)。`#[UseMetaStorage(AcfMetaStorage::class)]`のエンティティが宣言したACF管理下キーは、宣言どおり登録されるため本機構の対象になる。
- サイト構成に応じて実行時に要否を切り替える用途には属性は使えない。リビジョン保存の要否はエンティティ単位で静的に決まるものとして扱う。

### 4.12 `TMSWP\Core\Attribute\AsOptionGroup` / `TMSWP\Core\Attribute\OptionSchema`

#### 役割

オプショングループの宣言属性。`AsOptionGroup` はオプショングループ enum(string backed enum、`OptionAccess` trait を use。4.16)に付与してプレフィックスとストレージを宣言し、`OptionSchema` は各 enum ケースに型・デフォルト・autoloadを宣言する。旧 `AbstractOption` が「ON_定数 + `__callStatic`」のみでプレフィックス管理・デフォルト値・型付きアクセスを欠いた結果、実案件が独自Option/Configクラスを自作した(T16 の `sb_` プレフィックス+`DEFAULTS` 配列、T18 の `Config::API_MODEL()` マジックアクセサ)教訓を直接仕様化したものである。

メタ側(`#[MetaSchema]` 付きクラス定数)を enum 化しないのは、`MetaBag<static>` のジェネリクス伝播(エンティティ基底 `meta()` の `@return MetaBag<static>` 注釈。4.18)が「定数所有クラス=エンティティクラス」という構造に依存するためである。スキーマ宣言の器が meta=クラス定数+属性 / option=enum ケース+属性 と異なるのは、意図的に受け入れたトレードオフである。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionStorageInterface;

// enum もクラスの一種であるため TARGET_CLASS で enum に付与できる
#[Attribute(Attribute::TARGET_CLASS)]
final class AsOptionGroup
{
    /**
     * @param string $prefix 全キーに付くオプション名プレフィックス(例: 'myapp_')。
     *                       実オプション名 = prefix . ケースの backing value。空文字可だがスターターでは必須を推奨。
     * @param class-string<OptionStorageInterface> $storage グループ単位のストレージ実装の Container の id。
     *                       既定は Kernel が既定のストレージを登録した OptionStorageInterface(4.16)
     */
    public function __construct(
        public readonly string $prefix = '',
        public readonly string $storage = OptionStorageInterface::class,
    ) {
    }
}

/**
 * オプショングループ enum の各ケースに付与する。
 * PHP には enum case 専用の属性ターゲットフラグ(TARGET_ENUM_CASE 相当)が無く、enum case は
 * 属性のターゲットとしてクラス定数に当たるため、ターゲットは TARGET_CLASS_CONSTANT とする。
 * クラス・メソッド・プロパティ・引数への付与は PHP(属性のインスタンス化時)と PHPStan が拒否し、
 * グループ enum のケースでない定数・enum 自身・メソッド・引数への付与は OptionRepositoryFactory::for()
 * がブート時に InvalidSchemaException とする(4.15)。meta 用 #[MetaSchema] とは反射経路を別管理とする点に注意。
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class OptionSchema
{
    /**
     * @param MetaValueType $type スキーマ値型(4.2)
     * @param mixed $default 未保存時デフォルト。null はデフォルトなし
     * @param bool $autoload update_option/add_option 時の autoload 指定
     */
    public function __construct(
        public readonly MetaValueType $type = MetaValueType::String,
        public readonly mixed $default = null,
        public readonly bool $autoload = true,
    ) {
    }
}
```

#### 振る舞い仕様

- `OptionSchema` の検証規則は `MetaSchema` に準じる(default互換。type は `MetaValueType` 受けのため実行前に拒否され検証不要。4.1)。検証は `OptionSchemaDefinition::fromAttribute()`(4.13)がブート/初回アクセス時に行う。
- 反射経路: enum ケースの属性はクラス定数用の `ReflectionClass::getReflectionConstants()` ではなく、`ReflectionEnum::getCases()` + `ReflectionEnumUnitCase::getAttributes()` で取得する。Discovery・`OptionSchemaDefinition::fromAttribute()`・`OptionRepositoryFactory::for()`(4.15 手順3)はすべてこの経路を使う(meta 側のクラス定数反射経路とは別管理)。
- `AsOptionGroup` の `storage` がコンテナで解決不能・インターフェース非実装の場合は `InvalidSchemaException`。
- `#[AsOptionGroup]` は Discovery の走査対象属性である(Kernel設計書)。走査対象には class だけでなく enum を含む(enum もクラスの一種として Discovery が収集する)。Discoveryは検出したグループ enum を `OptionRepositoryFactory`(4.15)へ事前登録し、スキーマ検証をブート時に前倒しする。Discovery未検出の enum(テスト等で直接利用)でも `OptionRepositoryFactory::for()` が遅延構築するため動作はする。
- `#[OptionSchema]` のターゲットは `TARGET_CLASS_CONSTANT` であり、enum ケースでない定数への付与は属性のターゲットとしては許されてしまうが、収集経路が `getCases()` のみであるため宣言としては無効である。`OptionRepositoryFactory::for()`(4.15 手順3)は、グループ enum のケースでない定数・enum 自身・メソッド・メソッドの引数に付いた `#[OptionSchema]` を検出して `InvalidSchemaException` とする(黙殺しない)。Discovery の走査(02文書 §4 の手順3-c)も、enum のケースでない定数・クラスや enum 自身への付与と、`#[AsOptionGroup]` の無い enum のケースへの付与を探索失敗(`DiscoveryException`)にする。

### 4.13 `TMSWP\Core\Option\OptionSchemaDefinition` / `TMSWP\Core\Option\OptionSchemaSet`(新設)

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use TMSWP\Core\Meta\MetaValueType;

final class OptionSchemaDefinition
{
    /**
     * @param class-string $ownerClass 定義元のオプショングループ enum
     * @param string $caseName enum ケース名(例: 'ContactEmail')
     * @param string $key プレフィックス適用前のキー(ケースの backing value)
     */
    public function __construct(
        public readonly string $ownerClass,
        public readonly string $caseName,
        public readonly string $key,
        public readonly MetaValueType $type,
        public readonly mixed $default,
        public readonly bool $autoload,
    ) {
    }

    /**
     * ReflectionEnumUnitCase::getAttributes() 経由で取得した属性インスタンスから生成・検証する(4.12)。
     *
     * @param class-string $ownerClass
     * @throws \TMSWP\Core\Exception\InvalidSchemaException
     */
    public static function fromAttribute(
        string $ownerClass,
        string $caseName,
        string $key,
        \TMSWP\Core\Attribute\OptionSchema $attribute,
    ): self;

    public function hasDefault(): bool;
}

final class OptionSchemaSet
{
    /** @param array<string, OptionSchemaDefinition> $definitions キー(プレフィックスなし) => 定義 */
    public function __construct(private readonly array $definitions)
    {
    }

    public function has(string $key): bool;
    public function get(string $key): ?OptionSchemaDefinition;

    /** @return array<string, OptionSchemaDefinition> */
    public function all(): array;

    /** @return list<string> */
    public function keys(): array;

    public static function empty(): self;
}
```

- 型表現enumはメタ側と共通の `MetaValueType` を再利用する(意味は「スキーマ値型」であり、メタ専用ではない。名前は正準コントラクトのMeta配下に置く)。

### 4.14 `TMSWP\Core\Option\OptionStorageInterface` / `TMSWP\Core\Option\WpOptionStorage`

#### 役割

オプションの物理ストレージ抽象とそのWP標準実装。メタ側と同じく `has()`/`get()` 分離で null センチネルを排除する。`get_option()` は「未保存」と「falseが保存されている」を区別できないため、`WpOptionStorage` は内部センチネルオブジェクト技法で has を実装する。

`WpOptionStorage` は、Kernel が Container の `OptionStorageInterface` の id に登録する既定のストレージである(テストでは差し替えられる。4.16)。`WpMetaStorage`(4.6)と同じく、コンストラクタで Kernel を受け取り、単独の読み取りのデータベースのエラーをその Kernel の debug とロガーで扱う。どの Kernel を使うかは、ストレージを解決した Container で決まる。

- オプショングループ(`OptionAccess`・`OptionRepository`): `Kernel::forClass(グループの enum)` の Container の `OptionRepositoryFactory` が保存先を解決するため、グループの enum の Kernel。
- 素のオプション名(他プラグインのオプション等。4.15): エンティティやグループのクラスが無いため、アプリが自分の Kernel の Container から `OptionStorageInterface` を取り出す(Container が作るクラスはコンストラクタの引数に `OptionStorageInterface` を書けば注入される)。取り出した Container の Kernel が使われる。`new WpOptionStorage($kernel)` は Kernel を明示する経路として動くが、テストの差し替えが効かないため、アプリのコードでは使わない(rules/options.md)。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

/**
 * 書き込み(set・delete)は、書き込んだかどうかを bool で返し、データベースのエラーは例外で知らせる。
 * false は失敗ではなく「書き込まなかった」(同じ値、ほかのプラグインのフィルタによる中止、delete ではオプションが
 * 無かった)を意味し、保存されている値は変わっていない。
 * null は保存しない。削除は delete() で行う。
 */
interface OptionStorageInterface
{
    /** $name は プレフィックス適用済みの実オプション名 */
    public function has(string $name): bool;

    /** 未保存時は null。存在判定には has() を使うこと */
    public function get(string $name): mixed;

    /**
     * @param bool|null $autoload null はWPに委ねる(既存値を変更しない)
     * @return bool 書き込んだら true、書き込まなかったら false(保存値は変わっていない)
     * @throws \TMSWP\Core\Exception\InvalidArgumentException null
     * @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー
     */
    public function set(string $name, mixed $value, ?bool $autoload = null): bool;

    /**
     * 冪等。元々存在しない場合は何もせず false。
     *
     * @return bool 削除したら true、元々オプションが無かった・削除しなかったら false
     * @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー
     */
    public function delete(string $name): bool;
}

final class WpOptionStorage implements OptionStorageInterface
{
    /** @param \TMSWP\Core\Kernel\Kernel $kernel 単独の読み取りのデータベースのエラーを扱う debug とロガーの Kernel */
    public function __construct(\TMSWP\Core\Kernel\Kernel $kernel);

    public function has(string $name): bool;
    public function get(string $name): mixed;
    public function set(string $name, mixed $value, ?bool $autoload = null): bool;
    public function delete(string $name): bool;
}
```

#### WpOptionStorage 振る舞い仕様

```
exists($name):                              # 内部の読み取り
  $sentinel = new \stdClass();              # 毎回生成するローカルセンチネル(外部に漏れない)
  return get_option($name, $sentinel) !== $sentinel;

value($name):                               # 内部の読み取り
  $sentinel = new \stdClass();
  $value = get_option($name, $sentinel);
  return $value === $sentinel ? null : $value;

# 4.6 の「データベースのエラーの判定」に従う:
#   readOrDefault(...): 単独の読み取り。データベースのエラーなら、debug は RuntimeException、
#     本番は Kernel のロガーに記録して既定値(オプションが無いときと同じ結果)
#   read(...): 書き込み操作の中の読み取り。データベースのエラーなら debug に関係なく RuntimeException
#   write(...): 書き込み関数が false を返し、データベースのエラーなら RuntimeException、
#     そうでなければ false(書き込まなかった)。書き込んだと返したら true
#   readOrDefault・read は、データベースのエラーのとき、例外・記録の前に forgetFailedRead($name) で
#     失敗した読み込みのキャッシュ(下の箇条)を消す
has($name):
  return readOrDefault(exists($name), default: false)   # 失敗のメッセージ: failed to read option '{name}'

get($name):
  return readOrDefault(value($name), default: null)

set($name, $value, $autoload):
  if ($value === null) throw InvalidArgumentException   # null は保存しない。削除は delete()
  if (!read(exists($name))) return write(add_option($name, $value, '', $autoload))
  return write(update_option($name, $value, $autoload))

delete($name):
  if (!read(exists($name))) return false;   # 冪等(何もしない)
  return write(delete_option($name))
```

- センチネルオブジェクトは `has()`/`get()` の実装詳細としてのみ存在し、インターフェース契約・戻り値・wp_options の保存値には一切現れない。「APIとしてのnullセンチネル全廃」と矛盾しない。オプションの値として `null` を保存する手段は持たない(null を表すセンチネルを wp_options に書くと、他の読み手には `__PHP_Incomplete_Class` になるため)。
- 「同じ値」の判断は WP の `update_option()` に任せる。サニタイズ(`sanitize_option_{$name}`)や `pre_update_option*` フィルタが値を保存済みの値と同じにした場合、オプション名の前後の空白を WP が取り除いて同じオプションになった場合は、WP が書き込まずに false を返すので false になる(例外にしない)。本クラスはサニタイズもフィルタも再実行せず、呼び出し元が渡した値も変えない(`update_option()` は object を clone してからサニタイズする)。
- 根拠(WP 7.0 の wp-includes/option.php): `update_option()`・`add_option()`・`delete_option()` は、書き込みのクエリ(`$wpdb->update()`・`INSERT ... ON DUPLICATE KEY UPDATE`・`$wpdb->delete()`)が失敗すると false を返す。`update_option()` と `add_option()` はその直後に false を返す。`delete_option()` は失敗の後もキャッシュを更新するため、autoload のオプションで `alloptions` のキャッシュが無いときは読み直しのクエリが走り、削除のエラーが `last_error` から消えて false(書き込まなかった)になりうる(4.6 の受け入れる限界2)。ほかの受け入れる限界(4.6 の1・3・4)も同じく当てはまる。
- `set()`・`delete()` の前の存在の確認の読み取りも、4.6 と同じ方法で判定し、データベースのエラーなら debug に関係なく `RuntimeException` にする。
- `has()`・`get()` を単独で呼んだときのデータベースのエラーは、4.6 と同じくエラー方針に従う(debug では `RuntimeException`、本番では Kernel のロガーに記録して false・null)。`get_option()` は、autoload のオプションとキャッシュ済みのオプションをクエリを発行せずに返すため、そのときはエラーにならない。
- 失敗した読み込みのキャッシュ(4.6 と同じく、単独の読み取りと `set()`・`delete()` の前の存在の確認の両方): データベースのエラーを検出したら、例外を投げる前・ログに記録する前に、WP 7.0 の `get_option()`(wp-includes/option.php)が失敗した読み込みで書くキャッシュだけを消す。`get_option()` は名前を `trim()` してから読み、その名前でキャッシュを書くため、後始末も `trim()` した名前を使う。
  - `notoptions`(グループ `options`): 1件の SELECT(`SELECT option_value ... WHERE option_name = ...`)が失敗すると、WP はそのオプションを無いものとして `notoptions` の配列に `$name => true` を書く。その名前の要素だけを取り除き、ほかの名前は残す。
  - `alloptions`(グループ `options`): `alloptions` のキャッシュが無いときの `wp_load_alloptions()` は、autoload の SELECT と、その結果が空のときの全オプションの SELECT の両方が失敗すると、空の配列を `wp_cache_add()` で書く。キャッシュの `alloptions` が空の配列のときだけ消す(WP は空の `alloptions` を毎回読み直すが、`wp_cache_add()` は既存のキーを上書きしないため、消さないと空の配列が残り続ける)。
  - その名前のキャッシュ(グループ `options`、キーはオプション名)は、SELECT が成功したときだけ書かれるため消さない。
  - 消した後の読み取りは、データベースから読み直す。
  - 限界: `get_option()` は、非推奨の名前(`blacklist_keys`・`comment_whitelist`)を新しい名前(`disallowed_keys`・`comment_previously_approved`)に読み替えて `notoptions` を書く。後始末はこの読み替えを再現しないため、非推奨の名前で読み込みに失敗した場合は、新しい名前の `notoptions` が残る。素のオプション名は、新しい名前で読むこと。
- オプションが無い場合は `update_option()` を通さず `add_option()` を呼ぶ。

### 4.15 `TMSWP\Core\Option\OptionRepository` / `TMSWP\Core\Option\OptionRepositoryFactory`(Factoryは新設)

#### 役割

`OptionRepository` は1グループ分のオプション読み書き実体(プレフィックス適用・型キャスト・デフォルト解決)。`OptionRepositoryFactory` はグループ enum のリフレクション(属性・ケース読み取り)とリポジトリのキャッシュを担い、コンテナにシングルトン登録される。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use Psr\Container\ContainerInterface;

final class OptionRepository
{
    /**
     * 直接 new しない。OptionRepositoryFactory::for() 経由で生成する。
     *
     * @param class-string $groupClass
     */
    public function __construct(
        private readonly string $groupClass,
        private readonly string $prefix,
        private readonly OptionSchemaSet $schemas,
        private readonly OptionStorageInterface $storage,
    ) {
    }

    /** @return class-string */
    public function groupClass(): string;
    public function prefix(): string;

    /** プレフィックス適用済みの実オプション名を返す(デバッグ・アンインストール処理用) */
    public function name(string $key): string;

    // ---- 読み取り(解決順・キャスト規則は MetaBag と同一。4.8参照) ----
    public function get(string $key, mixed $default = null): mixed;
    public function int(string $key, ?int $default = null): int;
    public function float(string $key, ?float $default = null): float;
    public function bool(string $key, ?bool $default = null): bool;
    public function string(string $key, ?string $default = null): string;

    /** @return list<mixed> */
    public function list(string $key, ?array $default = null): array;

    /** @return array<array-key, mixed> */
    public function json(string $key, ?array $default = null): array;

    public function has(string $key): bool;

    /** @return array<string, mixed> 宣言済み全キー(プレフィックスなしキーで返す) */
    public function all(): array;

    // ---- 書き込み ----

    /**
     * @return bool 書き込んだら true、書き込まなかったら false(保存値は変わっていない)
     * @throws \TMSWP\Core\Exception\InvalidArgumentException null・スキーマの型に合わない値
     * @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー
     */
    public function set(string $key, mixed $value): bool;

    /** @return bool 削除したら true、元々オプションが無かった・削除しなかったら false */
    public function delete(string $key): bool;

    /**
     * 宣言済み全キーを削除する(アンインストール用)。
     *
     * @return list<string> 実際に削除した実オプション名(元々無かったものは含まない)
     */
    public function deleteAll(): array;
}

final class OptionRepositoryFactory
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * グループ enum のリポジトリを返す(enum 単位でメモ化)。
     *
     * @param class-string $groupClass #[AsOptionGroup] 付きの string backed enum(OptionAccess を use)
     * @throws \TMSWP\Core\Exception\InvalidSchemaException
     *         #[AsOptionGroup] 欠落・string backed enum でない・スキーマ検証違反
     */
    public function for(string $groupClass): OptionRepository;

    /** Discovery からの事前登録(ブート時検証の前倒し)。内部的に for() を呼ぶだけ */
    public function preload(string $groupClass): void;
}
```

#### 振る舞い仕様

- `OptionRepository` の値解決順は MetaBag と完全に同一: storage(has→get) → 引数default → スキーマdefault → 型ゼロ値(get()はnull)。キャストは `ValueCaster` を共用。同一なのは**値解決・キャスト規則**であり、スキーマの宣言形は meta(クラス定数+属性)と option(enum ケース+属性)で異なる(4.12)。
- メタと違い `single=false` 相当の概念はない。`list()`/`json()` は保存値(配列/JSON文字列)のキャストのみ。
- `set()` はスキーマの `autoload` を `storage->set()` の第3引数へ渡す。未宣言キーは `null`(WP既定)を渡す。
- `set()` は `null` を `InvalidArgumentException` で拒否し、永続化しない(宣言の有無に関係なく。削除は `delete()` に一本化する)。宣言済みキーの値は `ValueCaster::accepts()`(4.7)でスキーマの型に合うことを確かめ、合わなければ `InvalidArgumentException`(キャストはしない)。未宣言キーは型を検査しない。戻り値はストレージの戻り値(書き込んだか)で、データベースのエラーはストレージが例外にする(4.14)。
- `deleteAll()` は宣言済みの全キーについて `storage->delete()` を呼び、true を返した(実際に削除した)実オプション名だけを返す。
- **未宣言キーの扱い**: enum ファサード(4.16)はケースに存在しないキーを表現できないため、「グループ経由の未宣言キー読み取り」はファサードの対象外である。グループ名前空間内の未宣言キー(プレフィックス付きで他オプションを読む用途)は、本クラス `OptionRepository::get()` 等の**直接利用が正式経路**である(`SiteOption::repository()->get('legacy_key')` の形。4.16)。未宣言キーは「許可するがスキーマ恩恵なし」で、プレフィックスは**適用される**(グループ経由のアクセスは常にグループの名前空間内)。素のオプション名(他プラグインのオプション等)は、アプリの Kernel の Container から取り出した `OptionStorageInterface`(既定は `WpOptionStorage`)の直接利用を正とし、`get_option()` 直書きは非推奨とする(この経路規約は rules/ に明記)。データベースのエラーは取り出した Container の Kernel の debug とロガーで扱われ(4.14)、テストで差し替えた実装にもなる(4.16)。
- `OptionRepositoryFactory::for()` の構築手順:

```
1. メモ化済みなら返す
2. enum_exists($groupClass) かつ string backed(ReflectionEnum::getBackingType() が string)
   でなければ InvalidSchemaException
3. ReflectionEnum: #[AsOptionGroup] を読む。なければ InvalidSchemaException。
   enum 自身・ケースでない定数・メソッド・メソッドの引数に #[OptionSchema] があれば InvalidSchemaException(4.12)。
   ReflectionEnum::getCases() で全ケースを列挙し、#[OptionSchema] 付きケースを
   ReflectionEnumUnitCase::getAttributes() 経由で OptionSchemaDefinition::fromAttribute()
   により検証付き収集(属性なしケースは宣言キーとして扱わずスキーマ恩恵なし。
   enum に継承は無いため 4.9 の宣言クラス規則は不要)
4. storage を container->get(attribute->storage) で解決(既定の OptionStorageInterface::class は、Kernel が
   既定のストレージを登録した id。何も登録しなければ WpOptionStorage。4.16)
5. new OptionRepository(...) をメモ化して返す
```

- グループ内キー文字列の重複検出は行わない: ケースの backing value 重複は PHP がコンパイル時に Fatal とするため、実行時検出は不要である。

- register_setting 等のWP Settings APIへの登録は本層では行わない(設定画面UIは tmswp/settings-page 拡張の責務)。

### 4.16 `TMSWP\Core\Option\OptionAccess`(trait)

#### 役割

app側のオプショングループ enum(string backed・`#[AsOptionGroup]` 付与)が `use` する型付きアクセサtrait。テンプレート・app層から `SiteOption::ContactEmail->string()` の形でアクセスできるようにする(DR-09の二層構成は維持: 外向きは enum ケースのメソッド=静的ファサード層、内部はコンテナ解決の `OptionRepository`)。`__callStatic` は使わず、全メソッドを実シグネチャで定義する。

オプショングループを基底クラス継承+string定数+キー引数付き static ファサードではなく、本 trait + backed enum で表現する。グループに必要な次の保証は、自前の機構を持たずにPHPの言語機能で得られる:

- インスタンス化禁止の `final private __construct` ボイラープレート → enum は言語仕様上 `new` 不能。
- グループ内キー重複の検出 → backing value の重複はコンパイル時 Fatal。
- キー所属の検査 → キー=ケース自身であり、他グループのキー混入は型上表現不能。

また全getterがキー引数を持たず、`SiteOptions::bool(SiteOptions::MAINTENANCE)` のようなクラス名2回書きをせずに `SiteOption::Maintenance->bool()` と書ける。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use TMSWP\Core\Kernel\Kernel;

/**
 * #[AsOptionGroup] 付きの string backed enum に use する。
 *
 * @phpstan-require-implements \BackedEnum
 */
trait OptionAccess
{
    /** 自グループのリポジトリを取得する。テスト時は repository() を差し替えず、
        boot の前に既定のストレージを差し替える(下の「テストでの既定のストレージの差し替え」) */
    final public static function repository(): OptionRepository;

    // ---- ケース単位の読み取り(インスタンスメソッド。キーは $this->value) ----

    final public function get(mixed $default = null): mixed;
    final public function int(?int $default = null): int;
    final public function float(?float $default = null): float;
    final public function bool(?bool $default = null): bool;
    final public function string(?string $default = null): string;

    /** @return list<mixed> */
    final public function list(?array $default = null): array;

    /** @return array<array-key, mixed> */
    final public function json(?array $default = null): array;

    final public function has(): bool;

    /** プレフィックス適用済みの実オプション名(デバッグ・アンインストール処理用) */
    final public function name(): string;

    // ---- ケース単位の書き込み(戻り値・例外は OptionRepository::set()/delete() と同じ。4.15) ----

    final public function set(mixed $value): bool;
    final public function delete(): bool;

    // ---- グループ単位操作(staticメソッド) ----

    /** @return array<string, mixed> 宣言済み全キーの値マップ(プレフィックスなしキーで返す) */
    final public static function all(): array;

    /** 宣言済み全キーを削除する(アンインストール用)。実際に削除した実オプション名の list を返す */
    final public static function deleteAll(): array;
}
```

#### 振る舞い仕様

- すべてのメソッドは `static::repository()` 経由で `OptionRepository` の同名メソッドへ1:1委譲する薄いラッパ。ケース単位メソッドはキーとして `$this->value` を渡す。ロジックの二重実装を禁止する。
- `repository()` の実装: `Kernel::container()->get(OptionRepositoryFactory::class)->for(static::class)`。Kernel未ブート時は `KernelNotBootedException`(Kernel設計書の例外。コンテナアクセサの正準名はKernel設計書に従う)。
- trait を string backed でない enum に use した場合は、宣言の誤りとして `InvalidSchemaException` とする(`repository()` の `OptionRepositoryFactory::for()` が先に拒否し、キーの解決 `$this->value` も同じ例外で拒否する)。
- キー一覧の列挙は trait のメソッドではなく enum 標準の `cases()` を使う(旧 `keys()` 相当。宣言済みキー文字列の一覧が必要な場合は `OptionSchemaSet::keys()` をリポジトリ経由で参照する)。
- 未宣言キーは enum ケースとして表現できないため、本ファサードからの未宣言キー読み取りはできない。グループ名前空間内の未宣言キーは `static::repository()->get('legacy_key')` の直接利用が正式経路(4.15)。
- 旧 `AbstractOption` の `__callStatic` 内 `self::set`(静的遅延束縛の誤り。サブクラスでのオーバーライドが効かない)と「setterが常にnullを返す」バグは、マジックメソッド全廃+実シグネチャ定義により構造的に再発不能となる。
- 頻出オプションはメタ同様、明示アクセサを enum のメソッドとして書ける(規約は rules/):

```php
public static function contactEmail(): string
{
    return self::ContactEmail->string();
}
```

#### テストでの既定のストレージの差し替え

WordPress なしのユニットテストでアプリのコードを確かめるため、メタとオプションの既定のストレージを、boot の前に別の実装へ差し替えられる(D-46)。`repository()` や `MetaBag` は差し替えない。

- 既定のストレージは interface を登録先とする。Kernel は Container を作るときに `MetaStorageInterface::class` に `WpMetaStorage::class`、`OptionStorageInterface::class` に `WpOptionStorage::class` を登録する(02-kernel §3 手順3)。`#[UseMetaStorage]` の無いエンティティのメタ(4.9・4.10)と、`#[AsOptionGroup]` で `storage` を指定しないグループ(4.12・4.15)は、この id から解決する。Container から取り出す素のオプション名の保存先(4.15)も同じ。
- 登録の方法は1つだけ: `KernelConfig` の `extensions` に渡した拡張の `register()`(02-kernel §3.10)の中で、`$kernel->container()->set(MetaStorageInterface::class, ...)`・`set(OptionStorageInterface::class, ...)` で別の実装を登録する。新しい公開 API は持たない。
- Kernel は、拡張の登録の後、boot 時の初期化(エンティティのメタスキーマの登録)とキャッチアップより前に、2つの id を解決して固定する(02-kernel §3 手順7a)。
- interface を実装しないものの登録は fail-fast にする。インスタンスとクラス名は登録の時点で、factory(Closure)は boot の中の解決の時点で、Container が `ContainerException` にし、boot は失敗する(02-kernel §8)。
- boot の後の差し替えは受け付けない。解決済みの id への `set()`・`alias()` は、Container が `ContainerException` にする。
- 差し替えに使うメモリの実装は FW に含めない。実装の例は rules/meta.md・options.md の「テスト」に置き、アプリの tests/ に書く。src/ は本番で読み込まれるコードだけを置き、テストのための公開 API を増やさないためである。FW 自身のテストの同じ実装(tests/Support)は、`MetaStorageInterface` の契約テストのうちデータベースに依存しない部分(`MetaStorageContract`)を満たす。

### 4.17 例外クラス

本層が送出する例外。すべて `TMSWP\Core\Exception\` 配下(コーディング規約)。FWの基底例外(`LogicException`/`RuntimeException`/`InvalidArgumentException`)とマーカーinterface `TmswpExceptionInterface` はKernel設計書の例外章(`02-kernel.md` §9)が定義し、ここでは本層固有のクラスのみ定義する。

```php
namespace TMSWP\Core\Exception;

/** スキーマ宣言の不備(type不正・default型不一致・属性欠落・storage解決不能 等)。\LogicException 系 */
final class InvalidSchemaException extends LogicException {}

/** 同一スコープ内のメタキー二重宣言、ACF管理キーとのPHP宣言衝突(DR-06)。\LogicException 系 */
final class DuplicateMetaKeyException extends LogicException {}

/** MetaSchemaRegistry 未登録エンティティへの bagFor()/objectTypeOf()。\LogicException 系 */
final class EntityNotRegisteredException extends LogicException {}
```

- オプショングループ内キーの二重宣言を表す例外は持たない: グループは backed enum であり(4.16)、backing value の重複は PHP がコンパイル時に Fatal とするため、実行時例外は不要。
- ここでの `LogicException` は `TMSWP\Core\Exception\` 配下のFWの基底例外を指す。FWの基底は `\LogicException` を継承して `TmswpExceptionInterface` を実装するため、上記の例外は `catch (\LogicException $e)` でも `catch (TmswpExceptionInterface $e)` でも捕まえられる。
- 引数不正(ObjectType とWPオブジェクトの不一致、ID<1、single=false への非list set、`null` の set、宣言済みキーへのスキーマの型に合わない値の set、メタへの object の set 等)は、FWの基底 `InvalidArgumentException`(`07-support.md` §6。`\InvalidArgumentException` 系)を送出する。
- 書き込み(`set`・`setAll`・`delete`)でのデータベースのエラーは、FWの基底 `RuntimeException`(`\RuntimeException` 系)を送出する。書き込みの戻り値は「書き込んだか」であり、データベースのエラーを `false` で返すことはしない。ほかのプラグインのフィルタが書き込みを止めた場合は、保存値が変わっていないので false である(4.5・4.6・4.14)。
- 読み取り(`has`・`get`・`getAll`・`getMultiple`)を単独で呼んだときの `WpMetaStorage`・`WpOptionStorage` のデータベースのエラーは、debug では同じ `RuntimeException` を送出し、本番では Kernel のロガーに記録して行・オプションが無いときと同じ結果を返す(4.6・4.14)。
- `InvalidSchemaException` には、4.1 の宣言の誤り(public でない定数、REST に出す Array/Object 型のスキーマ欠落を含む)、WP が `register_*_meta` を拒否したこと(4.9)、`#[OptionSchema]` の enum ケース以外への付与(4.12)も含む。
- 方針: スキーマ・宣言系の誤りは2つの時点で検出する。宣言の誤りは Kernel の boot の中の `registerEntity()`(4.9)で、WordPress が `register_*_meta` を拒否した場合と sanitize の callable が実在しない場合は `init` 20 の `registerAll()`(4.9)で検出する。どちらの時点でも、検出した設定の誤りは debug に関係なく LogicException 系の例外にして黙って捨てず、本番実行時の黙殺・フォールスルー(旧FWのフィルタ名不一致が3年以上発覚しなかった原因)を構造的に防ぐ。

### 4.18 FW同梱PHPStan拡張

#### 役割

`MetaSchema`/`OptionSchema` の宣言から `MetaBag::get()` とオプショングループ enum(`OptionAccess::get()`)の戻り値型を絞り込み、さらにメタ側について「定数の所属クラスと呼び出し元エンティティの一致」を検査する静的解析拡張(DR-05)。オプション側の所属検査ルールは持たない: キーが enum ケース自身となったことで他グループのキー混入が型上表現不能となり、自前ルールは言語機能へ吸収された。`packages/core/phpstan/` に配置し、phpstan/extension-installer 経由で利用側プロジェクトに自動適用される。実行時(runtime)には一切ロードされない。

#### パッケージ構成

```
packages/core/
├── phpstan/
│   ├── extension.neon                 # サービス定義(下記)
│   └── src/
│       ├── MetaSchemaReflector.php
│       ├── MetaBagGetterReturnTypeExtension.php
│       ├── OptionGroupGetterReturnTypeExtension.php
│       └── MetaKeyOwnershipRule.php
└── composer.json
    # autoload: "TMSWP\\Core\\PHPStan\\": "phpstan/src/"  (本番オートロードに含めてよい。
    #   phpstan/* への依存は require-dev であり、クラスは解析プロセス内でのみ参照される)
    # extra.phpstan: { includes: ["phpstan/extension.neon"] }
```

#### extension.neon

```neon
services:
    -
        class: TMSWP\Core\PHPStan\MetaSchemaReflector
    -
        class: TMSWP\Core\PHPStan\MetaBagGetterReturnTypeExtension
        tags: [phpstan.broker.dynamicMethodReturnTypeExtension]
    -
        class: TMSWP\Core\PHPStan\OptionGroupGetterReturnTypeExtension
        tags: [phpstan.broker.dynamicMethodReturnTypeExtension]
rules:
    - TMSWP\Core\PHPStan\MetaKeyOwnershipRule
```

#### クラス仕様

**`MetaSchemaReflector`**: 共通基盤サービス。PHPStanの `ReflectionProvider` だけを通じて、与えられたクラス定数の `#[MetaSchema]`/enum ケースの `#[OptionSchema]` 属性引数(`type`/`default`/`single`)を取得する。

```php
namespace TMSWP\Core\PHPStan;

use PHPStan\Reflection\ReflectionProvider;

final class MetaSchemaReflector
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * @return array{type: string, hasDefault: bool, single: bool}|null 属性なし・引数が定数に定まらない場合は null
     */
    public function metaSchemaOf(string $className, string $constantName): ?array;

    /** クラス自身が宣言した #[MetaSchema] 付き定数のうち、値が $key のものの定数名(MetaKeyOwnershipRule 用) */
    public function metaConstantNameForKey(string $className, string $key): ?string;

    /**
     * オプショングループ enum のケースの #[OptionSchema] を参照する。
     *
     * @return array{type: string, hasDefault: bool}|null
     */
    public function optionSchemaOf(string $enumClass, string $caseName): ?array;

    /** #[MetaSchema] 付き定数を実際に宣言したクラス名。属性の無い定数は null(MetaKeyOwnershipRule 用) */
    public function declaringClassOfMetaConstant(string $className, string $constantName): ?string;
}
```

- **解析中に利用者のコードを実行しない**。クラスの読み込み(`class_exists`・`enum_exists` による autoload)、ネイティブの Reflection(`new ReflectionClass` 等)、属性のインスタンス化(`newInstance()`)は使わない。クラス・定数・enum ケースは `ReflectionProvider::getClass()` の `ClassReflection`(`getConstant()`・`getEnumCase()`)で引き、属性は `ClassConstantReflection::getAttributes()`・`EnumCaseReflection::getAttributes()` が返す `AttributeReflection` で読む。PHPStan は利用者のクラスを静的に解析するため、読み込むと副作用のあるファイル(テストの基底クラスが解析プロセスに無いテストクラス等)でも解析が止まらない。
- 属性引数は `AttributeReflection::getArgumentTypes()` の型(引数名がキー)として受け取る。ソースで省略された引数は、属性クラスのコンストラクタの既定値の型(`ParameterReflection::getDefaultValue()`)で補う。
- `type` はソース上 `MetaValueType::Integer` のような enum ケースとして現れる(4.1/4.12)。Reflector はその型の enum ケースの backing value 文字列へ落として返すため、戻り値の `type` は文字列であり、下記の型マップ表はそのまま使える。
- `hasDefault` は `default` 引数の型が null でないと確定する場合だけ true。`single` は真偽の定数型に定まる場合だけ値を返し、定まらなければ絞り込みをしない(null を返す)。

**`MetaBagGetterReturnTypeExtension`**(`PHPStan\Type\DynamicMethodReturnTypeExtension` 実装):

- 対象: `TMSWP\Core\Meta\MetaBag::get()`。型付きgetter(`int()` 等)はネイティブ戻り値型で十分なため対象外。
- 絞り込み手順:

```
1. 第1引数が ClassConstFetch(例: ProductPost::META_PRICE)でなければ何もしない(宣言型 mixed のまま)
2. MetaSchemaReflector で定数の #[MetaSchema] を取得。なければ何もしない
3. type → PHPStan型 へマップ(下表)
4. single=false → list<T> に持ち上げる
5. null許容の決定:
   - 第2引数($default)が与えられている → T|（$defaultの型）
   - スキーマに default あり → T
   - どちらもなし → T|null
```

- 型マップ(`MetaValueType` → PHPStan型):

| schema type | PHPStan型 |
| --- | --- |
| `string` | `string` |
| `integer` | `int` |
| `number` | `float` |
| `boolean` | `bool` |
| `array` | `array<int|string, mixed>` |
| `object` | `array<string, mixed>` |

**`OptionGroupGetterReturnTypeExtension`**(`PHPStan\Type\DynamicMethodReturnTypeExtension` 実装): 対象はオプショングループ enum 上の `OptionAccess::get()`(enum ケースをレシーバとするインスタンス呼び出し)。PHPStan は trait を拡張の対象クラスとして引かないため、拡張の対象クラス(`getClass()`)は `BackedEnum` とし、`isMethodSupported()` で、呼ばれる `get()` の実体が `OptionAccess` で宣言されたものである場合に限る(`ClassReflection::getNativeReflection()` が返す静的な解析結果のメソッドの宣言元で確かめる。trait から取り込んだメソッドでは宣言元が trait 自身になる)。enum 自身が `get()` を定義した場合は、PHP では trait の同名メソッドより enum 自身のメソッドが優先されるため、絞り込まずにその宣言型のままとする。レシーバの式の型が 1 つの enum ケースの型に確定する場合(`SiteOption::ContactEmail->get()` の直接の形に限らず、ケースを入れた変数や、`@param SiteOption::ContactEmail` で受けた引数も含む)、そのケースの `#[OptionSchema]` を `MetaSchemaReflector::optionSchemaOf()` で取得し、メタ側と同じ型マップで絞り込む(single概念なし。null許容の決定は第1引数 `$default` とスキーマ default により上記手順5と同一)。レシーバが enum 型一般(ケース不確定)の場合は宣言型 `mixed` のまま。`OptionRepository::get()` の直接利用(未宣言キーの正式経路。4.15)は絞り込み対象外で `mixed` のままとする。

**`MetaKeyOwnershipRule`**(`PHPStan\Rules\Rule<PhpParser\Node\Expr\MethodCall>` 実装):

- 対象: `MetaBag` の `get`/`int`/`float`/`bool`/`string`/`list`/`json`/`has`/`set`/`delete` 呼び出し。
- 検査手順:

```
1. レシーバの型が MetaBag<TEntity> であることを確認(ジェネリクスは
   エンティティ基底 meta() の @return MetaBag<static> 注釈で伝播する。エンティティ設計書)。
   TEntity が解決できない(素の MetaBag 型)場合は検査スキップ
2. 第1引数が ClassConstFetch の場合:
   定数が #[MetaSchema] 付きでなければ検査しない(メタキーの宣言ではないため)。
   定数の宣言クラス D を解決し、D === TEntity または TEntity のサブクラス/親で
   定数を実宣言したクラスが一致しなければエラー:
   "Meta key OtherPost::META_X does not belong to entity ProductPost."
   (identifier: tmswp.metaKeyOwnership)
3. 第1引数が文字列リテラルの場合:
   TEntity の #[MetaSchema] 宣言キーと同じ文字列なら
   "Use ProductPost::META_PRICE instead of string literal 'price'." を報告
   (identifier: tmswp.metaKeyLiteral)。宣言キーに該当しないリテラルは許容
   (未宣言キーアクセスは合法。4.8)
```

**オプション側のルールについて**: オプション側にはキー所属検査のPHPStanルールを持たない。所属検査(他グループ定数の混入)はキー=enumケース自身という型表現により表現不能であり、文字列リテラル誤用検査もファサードにキー引数が無いため対象が存在しない。未宣言キー経路(`OptionRepository::get()` 直接利用。4.15)の lint 方針は次のとおり: 同経路の文字列リテラルキーは合法であり自動検査は行わない。宣言済みキーは enum ケース経由でアクセスする規約を rules/ のレビュー観点として明記する。

- 誤検知方針: 動的なキー(変数・関数戻り値)は検査しない。baseline は使わない(DR-12)ため、ルールは「確実に誤りと言える場合のみ報告」を厳守する。

---

## 利用例(app側コード)

### メタ宣言と読み書き(テーマ/プラグイン共通)

```php
<?php
// app/Post/ProductPost.php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use WP_Post;

#[AsPostType]
final class ProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 0, showInRest: true)]
    public const META_PRICE = 'price';

    #[MetaSchema(type: MetaValueType::String, description: '製品コード', sanitize: 'sanitize_text_field')]
    public const META_CODE = 'product_code';

    #[MetaSchema(type: MetaValueType::Integer, single: false)]   // single=false の type は1行ごとの型
    public const META_RELATED_IDS = 'related_id';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')->label('製品')->labelLanguage(LabelLanguage::Ja)->hasArchive();
    }

    /** 頻出フィールドの明示アクセサ(規約) */
    public static function price(WP_Post|int $post): int
    {
        return self::meta($post)->int(self::META_PRICE);
    }
}
```

```php
// テンプレート(single-product.php)。WP標準テーマ手法のまま使える
$price = \App\Post\ProductPost::price($post);                              // int
$code  = \App\Post\ProductPost::meta($post)->string(\App\Post\ProductPost::META_CODE, '-');

// 保存(フォーム処理・保存フック内)
\App\Post\ProductPost::meta($post_id)->set(\App\Post\ProductPost::META_PRICE, 1280);
\App\Post\ProductPost::meta($post_id)->set(\App\Post\ProductPost::META_RELATED_IDS, [12, 34]); // single=false は list を渡す
\App\Post\ProductPost::meta($post_id)->delete(\App\Post\ProductPost::META_CODE);              // 値を消すときは null を set せず delete()
```

### ACFストレージへのオプトイン(acf-bridge 導入時)

```php
#[AsPostType]
#[UseMetaStorage(\TMSWP\AcfBridge\AcfMetaStorage::class)]
final class CampaignPost extends PostType
{
    // ACFのフィールドグループで管理するキーも宣言できる。ストレージがacf-bridgeの申告と同じAcfMetaStorageのため
    // 衝突しない(#[UseMetaStorage]の無いエンティティで宣言するとDuplicateMetaKeyException。acf-bridge設計書§7.3)
    #[MetaSchema(type: MetaValueType::Integer)]
    public const META_PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('campaign')->label('キャンペーン')->labelLanguage(LabelLanguage::Ja);
    }
}
```

### オプショングループ

```php
<?php
// app/Option/SiteOption.php

declare(strict_types=1);

namespace App\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;

#[AsOptionGroup(prefix: 'myapp_')]
enum SiteOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: 'info@example.com')]
    case ContactEmail = 'contact_email';   // 実オプション名: myapp_contact_email

    #[OptionSchema(type: MetaValueType::Boolean, default: false, autoload: false)]
    case Maintenance = 'maintenance_mode';

    /** 頻出オプションの明示アクセサ(規約。4.16) */
    public static function contactEmail(): string
    {
        return self::ContactEmail->string();
    }
}

// 利用側
$to = SiteOption::contactEmail();
if (SiteOption::Maintenance->bool()) { /* ... */ }
SiteOption::Maintenance->set(true);

// グループ名前空間内の未宣言キー(レガシーオプション等)はリポジトリ直接利用が正式経路(4.15)
$legacy = SiteOption::repository()->get('legacy_key');   // 実オプション名: myapp_legacy_key
```

### プレビュー時メタ保存のオプトイン

```php
<?php
// app/Post/ProductPost.php(抜粋)— クラス属性による宣言だけで完結する

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\WithMetaRevisions;
use TMSWP\Core\Post\PostType;

#[AsPostType]
#[WithMetaRevisions]   // 全 #[MetaSchema] キーがリビジョン保存対象になる(WP 6.4+ revisions_enabled)
final class ProductPost extends PostType
{
    /* ... メタ宣言・definition() は前掲のとおり(supports に 'revisions' が必要。4.11) ... */
}
```

命令的な有効化呼び出しは存在しない。宣言は boot の中の `registerEntity()` が属性を読み取って保持し、`registerAll()`(init 20)がその値を `register_post_meta` の引数 `revisions_enabled => true` に反映する(4.9/4.11)。

---

## 旧iwf2bからの設計根拠

1. **`__callStatic` + 文字列定数解決の全廃**: 旧 `DefineMetaTrait::__callStatic`(`SomePost::MK_PRICE($post)`)と `AbstractOption::__callStatic` は `defined('static::' . $name)` による文字列ベースの遅延定数解決で、IDE補完・静的解析・AIの型推論が完全に不能だった(解析資料が「本FW最大のAIアンフレンドリー箇所」と認定)。本層は実シグネチャの型付きgetter+定数引数+PHPStan拡張で同じ「定数一元宣言」のDXを型安全に再現する。
2. **フィルタ名不一致バグの教訓 → フィルタ拡張点そのものの廃止**: 旧FWは `DefineMetaTrait::clear_meta` が `iwf2b/meta/delete` を発火する一方、`AbstractHook` は `iwf2b/meta/clear` を購読し(option側も `iwf2b/option/delete` vs `iwf2b/option/clear` で同型の不一致)、さらに `clear_meta_with_acf` のシグネチャ不整合・`delete_field($object_id)` のACF API誤用が重なり、**ACF clear連携は一度も機能していなかった**。文字列フィルタ名は型検査が効かず、この種の欠陥が長期間沈黙する。本層は文字列フィルタによるストレージ差し替えを廃し、`MetaStorageInterface` + `#[UseMetaStorage]`(クラス参照=タイポ即死)に置き換えた。
3. **nullセンチネルの全廃(DR-18)**: 旧フィルタは「nullを返したらWP標準へフォールスルー」という規約のため、外部ストレージが正当な null/false/空値を返せなかった。`has()`/`get()` 分離(メタ・オプション両方)で存在判定と値取得を契約レベルで分け、`get_option` の限界は `WpOptionStorage` 内部のセンチネルオブジェクトに閉じ込めた。
4. **`is_subclass_of` 暗黙分岐 → `ObjectType` enum**: 旧 `DefineMetaTrait` は継承文脈(`AbstractPost`/`Tax`/`User` のどれを継承しているか)でメタ種別を暗黙判定し、トレイト単体利用ではswitch全不成立で未定義値(null)が黙って返った。本層は `ObjectType` を全ストレージAPIの必須引数とし、種別はDiscoveryの属性(`#[AsPostType]` 等)から**明示的に**決定する。
5. **`AbstractOption` の実質デッドコード化の教訓**: 実案件15プロジェクト中、利用は2クラス(複製テーマのため実質1実装)。一方 T16 は `sb_` プレフィックス+`DEFAULTS` 配列の独自Optionを、T18 は独自Configマジックアクセサを自作した。「プレフィックス管理・デフォルト値・型付きアクセス・autoload制御」という現場の実要求を `AsOptionGroup`/`OptionSchema`/`OptionRepository` として標準装備したのが本設計である。また旧 `__callStatic` setter が `self::set`(遅延静的束縛の誤り)を呼び戻り値nullを返すバグも、マジックメソッド全廃で構造的に解消した。
6. **プレビュー時メタ保存のACF非依存化**: 旧 `AbstractHook::save_preview_post` は `wp_insert_post` フックから独自アクションを発火し、ACF実装が `$_POST['acf']` を直接リビジョンへ書く密結合構造だった。WP 6.4+ がコア機能(`revisions_enabled`)で同問題を解決済みのため、独自フック連鎖を持たないどころか有効化サービスすら持たず、`register_post_meta` の引数と1:1対応するクラス属性 `#[WithMetaRevisions]` の宣言へ縮退させ、ACF依存を完全に断った。属性宣言は `registerAll()`(init 20)実行前に構造上確定するため、時間軸の順序契約・手遅れ時の例外・レジストリの可変状態そのものを持たない。
7. **`register_*_meta` 自動登録の新設**: 旧FWはメタキーを宣言してもWPコアへ一切登録せず、REST公開・スキーマ検証・default供給のコア機構を使えなかった。新FWは宣言=登録とし、`insert_default_meta`(空文字の自動挿入)のような回避策を不要にした。

---

## 受け入れ基準

### チェックリスト

- [ ] `#[MetaSchema]` の6パラメータ(type/default/single/showInRest/description/sanitize)がすべて `register_*_meta` 引数へ正しくマップされる(統合テストで `get_registered_meta_keys()` を検証)。
- [ ] default型不一致・single=false+default併用・sanitize形式不正・空キー・public でない定数への付与・REST に出す Array/Object 型のスキーマ欠落が、いずれもブート時に `InvalidSchemaException` となる(ユニットテスト)。WP が `register_*_meta` を拒否した場合(false を返した場合)も `registerAll()` が `InvalidSchemaException` となる(ユニットテスト)。type の不正値は `MetaValueType` enum 受けにより実行前(IDE/PHPStan/PHPの属性インスタンス化)に拒否されるため、ブート時検証・例外テストは存在しない。
- [ ] 同一エンティティ内・同一 (ObjectType, subtype) スコープのキー重複が `DuplicateMetaKeyException` となる。subtypeが異なる同名キーは共存できる。
- [ ] `markExternallyManaged()`・`markExternallyManagedInAllSubtypes()`済みで宣言の無いキーは`register_*_meta`が発行されず、同キーを申告と違うストレージのエンティティが`#[MetaSchema]`で宣言している場合は`DuplicateMetaKeyException`となり、メッセージがキー・(objectType, subtype)・宣言クラスと定数名・申告元を含む。申告と同じストレージのエンティティの宣言は衝突せず、宣言どおり登録される(acf-bridge連携点のコア側単体で検証)。サブタイプの文字列`'all'`は特別な意味を持たない。
- [ ] `MetaBag` の全getterについて「ストレージ値 → 引数default → スキーマdefault → ゼロ値/null」の解決順がユニットテスト(モックストレージ、Brain Monkey不要)で網羅されている。
- [ ] `ValueCaster` のキャスト表(4.7)の全セルがユニットテストで固定されている。
- [ ] `MetaStorageInterface` 契約テスト(has/get分離、getMultipleのキー有無セマンティクス、deleteの冪等性)が抽象テストケースとして存在し、`WpMetaStorage` が統合テスト(wp-env)でそれを満たす。データベースに依存しない契約は trait(`MetaStorageContract`)に分けて置き、テストでの差し替えに使うメモリの実装がユニットテストでそれを満たす。
- [ ] `register_meta` の `default` が設定されたキーでも、`WpMetaStorage::get()`/`getAll()` は default を介在させず「行なし」を null / `[]` として返す(統合テスト。`get_metadata_raw()` 経路の実証)。
- [ ] false/空文字を保存した場合に `has()` が true になる(nullセンチネル不在の実証。メタ・オプション両方)。`get()` は、空文字ならその値を返し、false ならオプションは false、メタは WP のメタの仕様どおり `''` を返す(4.6。Boolean のスキーマのキーは `MetaBag::bool()` で false に戻る)。`null` の `set` は `InvalidArgumentException` となり、何も保存しない(メタ・オプション両方。統合テスト)。
- [ ] バックスラッシュを含む文字列(`json_encode()` の出力・Windows のパス・正規表現)と、それを含む配列・single=false の行が、`MetaBag` の書き込みと読み出しで往復する(統合テスト)。
- [ ] 書き込みが「書き込んだ」で true、「書き込まなかった」で false を返す。false には、同じ値(保存済みの `'2500'` と int 2500、サニタイズや `pre_update_option*` の後に一致する値、前後に空白のあるオプション名を含む)と、ほかのプラグインのフィルタ(短絡を含む)による中止が当たり、保存値は変わらない。呼び出しの間のデータベースのエラーは、`last_error` をメッセージに含む `RuntimeException` となる(同じ対象への入れ子の書き込みがあっても同じ)。呼び出しの前の無関係なデータベースのエラーは拾わない。書き込み操作の中の読み取り(`setAll()` の置換の前後、`delete()`・オプションの `set()` の前の存在の確認)のデータベースのエラーも `RuntimeException` となる(メタ・オプション両方。統合テスト)。サニタイズは再実行せず、呼び出し元の値を変えない。`setAll()` は削除・追加のデータベースのエラーも例外にする。
- [ ] 公開の読み取り(メタの `has()`・`get()`・`getAll()`・`getMultiple()`、オプションの `has()`・`get()`)を単独で呼んだときのデータベースのエラーが、debug の Kernel では読み取りの対象と `last_error` をメッセージに含む `RuntimeException` になり、本番の Kernel ではその Kernel のロガーに error で記録されて、行・オプションが無いときと同じ結果(false・null・空配列)が返る。キャッシュから読んでクエリを発行しない読み取りと、呼び出しの前の無関係なデータベースのエラーは、例外にも記録にもならない(メタ・オプション両方。統合テスト)。
- [ ] 読み取り(単独の読み取りと書き込み操作の中の読み取り)のデータベースのエラーの後、失敗した読み込みで WP が書いたキャッシュ(メタの `{$meta_type}_meta`、オプションの `notoptions` の名前と空の `alloptions`)が残らず、エラーを外して同じ対象を読み直すとデータベースの値が返る。`WP_Metadata_Lazyloader` が同時に読み込んだほかの対象のメタと、前後に空白のあるオプション名でも同じ(メタ・オプション両方。統合テスト)。
- [ ] 宣言済みキーへのスキーマの型に合わない値の `set` が `InvalidArgumentException` となる(メタ・オプション両方。ユニットテスト)。String のスキーマへの `Stringable` のオブジェクトも、メタ・オプションの両方で同じ `InvalidArgumentException` となる(統合テスト)。
- [ ] `MetaBag::all()` の single=false のキーの値が `get()` と同じ全行の list である(ユニットテスト・統合テスト)。
- [ ] `#[UseMetaStorage]` 付きエンティティの `meta()` が指定ストレージ実装で読み書きし、属性なしエンティティは Container の `MetaStorageInterface` の id のストレージ(既定は `WpMetaStorage`)を使う。不正なstorageClassはブート時例外。
- [ ] 拡張の `register()` で Container の `MetaStorageInterface`・`OptionStorageInterface` の id に登録したメモリの実装を、WordPress なしで boot した Kernel の `#[UseMetaStorage]` の無いエンティティの `meta()` とオプショングループの読み書きが使い、WordPress の関数を呼ばない。`#[UseMetaStorage]` で明示したストレージは差し替えの影響を受けない。interface を実装しない登録は登録の時点か boot の時点で `ContainerException` になり、boot の後の差し替えも `ContainerException` になる(ユニットテスト。4.16)。
- [ ] `ObjectType::resolveId()` が種別不一致のWPオブジェクト・ID<1 で `InvalidArgumentException` を送出する。
- [ ] `#[WithMetaRevisions]` 付きエンティティのメタが `revisions_enabled => true` で登録され(統合テスト)、Post以外のエンティティ・revisions非サポート投稿タイプへの付与がブート時(`registerEntity()`)に `InvalidSchemaException` となる。
- [ ] `#[WithMetaRevisions]` 付きエンティティのプレビューで、オートセーブの偽の値(Integer の default 100 のキーを 0 にした値)を `has()`・`int()`・`get()`・`all()` が返す(統合テスト)。
- [ ] メタスキーマが Kernel の boot の中で登録され、本番相当(探索結果ファイルの経路)の boot の後、init の前でも app の `#[Action('init')]` の中でも `meta()` が使え、`#[UseMetaStorage]` と `#[WithMetaRevisions]` が保持される。`register_*_meta` は init 20 で行われ、`registerAll()` の後の `registerEntity()` は `LogicException` になる(統合テスト・ユニットテスト)。
- [ ] `OptionAccess` を use したオプショングループ enum でプレフィックス適用(`name()`)・autoload指定・`deleteAll()` が仕様どおり動作し、`deleteAll()` が実際に削除した実オプション名だけを返す(統合テスト)。
- [ ] オプショングループ enum のスキーマ収集が `ReflectionEnum::getCases()` + `ReflectionEnumUnitCase::getAttributes()` 経由で行われ、string backed でない enum・`#[AsOptionGroup]` 欠落・enum ケース以外(ケースでない定数・enum 自身・メソッド・引数)への `#[OptionSchema]` がブート時 `InvalidSchemaException` となる(ユニットテスト)。
- [ ] PHPStan拡張: `MetaBag::get(ProductPost::META_PRICE)` が `int`(default宣言あり時)/`int|null`(なし時)へ絞り込まれること、他エンティティ定数の混入(`tmswp.metaKeyOwnership`)と宣言済みキーの文字列リテラル直書き(`tmswp.metaKeyLiteral`)が報告されることを、PHPStanのルールテスト(`RuleTestCase`/`TypeInferenceTestCase`)で検証。オプション側は `SiteOption::ContactEmail->get()`(enum ケースレシーバ)の絞り込みを `TypeInferenceTestCase` で検証(キー所属の誤りは enum の型システム上表現不能のためルールテストは持たない)。
- [ ] 本層の全クラスが `declare(strict_types=1)`・final(基底・interface・enumを除く)・完全型宣言で、`composer analyse`(PHPStan level max, baselineなし)を通過する。
- [ ] マジックメソッド(`__callStatic`/`__get`/`__set`)・`extract()`・グローバルフィルタ(`apply_filters()`)によるストレージ差し替えがコード上に存在しない(ユニットテスト `LayerConventionTest` が、本層のソースのトークンを検査して検出)。

### 検証コマンド

```bash
composer lint      # php-cs-fixer (PSR-12 + strict_types)
composer analyse   # PHPStan level max(自前PHPStan拡張も解析対象に含む)
composer test      # ユニット(WP非依存: ValueCaster/MetaBag/MetaSchemaDefinition/OptionRepository ほか)
                   # + 統合(wp-env: WpMetaStorage/WpOptionStorage/registerAll/#[WithMetaRevisions])
composer qa        # 上記一括
```

