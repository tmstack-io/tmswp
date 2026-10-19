# 04. Meta / Option 層設計書

## 目的

WordPressのメタデータ(post/term/user meta)とオプション(wp_options)を、属性によるスキーマ宣言と型付きアクセサで扱う層を定義する。本層が保証すること:

1. **宣言の一元化**: メタキーはエンティティクラスの定数として、オプションキーはオプショングループ enum のケースとして一度だけ宣言し、`#[MetaSchema]` / `#[OptionSchema]` 属性で型・デフォルト値・REST公開等のスキーマを付与する。
2. **WPコアへの自動登録**: 宣言されたメタスキーマはブート時に `register_post_meta()` / `register_term_meta()` / `register_meta('user', ...)` へ自動登録される。
3. **型安全な読み書き**: `MetaBag` / オプショングループ enum(`OptionAccess` trait)の型付きgetter(`int()`/`string()`/`bool()`/`float()`/`list()`/`json()`)と、FW同梱のPHPStan拡張による戻り値型ナローイングで、メタ値の型をコードとして検証可能にする。
4. **ストレージの差し替え可能性**: `MetaStorageInterface` / `OptionStorageInterface` により、ACF等の外部ストレージへの委譲を「エンティティ単位の明示オプトイン」で行える(DR-18)。グローバルフィルタによる暗黙差し替えと null センチネルは全廃する。
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

- Kernel/Discovery 設計書: `Kernel::boot()` のフェーズ、`Discovery` による属性付きクラスの収集、コンポーネントの初期化タイミング。本書の `MetaSchemaRegistry` には、Discovery が収集したエンティティの Initializer が初期化時に登録する。
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

エンティティの初期化時(Kernel が属性ごとに登録したフック。#[AsPostType] は init 10):
  PostTypeInitializer → MetaSchemaRegistry::registerEntity(ProductPost::class, ObjectType::Post, 'product')
            → 定数リフレクション → MetaSchemaDefinition 群を構築・検証
  init(priority 20) → MetaSchemaRegistry::registerAll()
            → register_post_meta('product', 'price', [...])  ※ACF管理下キーはスキップ

実行時(テンプレート/app):
  ProductPost::meta($post)                    ← エンティティ基底のファサード(エンティティ設計書)
    └→ MetaSchemaRegistry::bagFor(ProductPost::class, $post): MetaBag
         └→ MetaBag::int(ProductPost::META_PRICE)
              └→ MetaStorageInterface::has()/get() → ValueCaster で型キャスト
```

オプション側も値解決・キャスト規則は同型だが、宣言の器が異なる: `#[AsOptionGroup]` を付与した backed enum の `#[OptionSchema]` 付きケース(`OptionAccess` trait)→ `OptionRepository` → `OptionStorageInterface`。meta=属性付きクラス定数 / option=属性付き enum ケースという宣言形の非対称は意図的トレードオフである(4.12)。

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
- 付与対象の定数は `public const`(型付き定数 `public const string` 推奨)かつ値が空でない `string` であること。それ以外はブート時 `InvalidSchemaException`。

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

`#[MetaSchema]` 属性をエンティティの初期化時に検証・正規化した不変の値オブジェクトと、エンティティ1クラス分のスキーマ集合。

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

- `fromAttribute()` は 4.1 の検証規則(default互換・single×default禁止・sanitize形式・キー文字列非空)をすべて適用し、違反時は `InvalidSchemaException` にエンティティクラス名・定数名を含めて送出する(type は `MetaValueType` 受けのため検証対象外。4.1)。
- 同一エンティティ内でのキー重複(異なる定数が同じメタキー文字列を持つ)は `MetaSchemaSet` 構築時ではなく `MetaSchemaRegistry::registerEntity()`(4.9)が検出して `DuplicateMetaKeyException` を送出する。

### 4.5 `TMSWP\Core\Meta\MetaStorageInterface`

#### 役割

メタ値の物理ストレージ抽象。**`has()` と `get()` を分離**することで「存在しない」と「null/false/空文字が保存されている」を区別し、旧FWの null センチネル(フィルタがnullを返すとWP標準へフォールスルーするため、外部ストレージから正当なnull相当値を返せない)を全廃する。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

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

    /** 単一値を保存する(既存行は置換)。成功で true */
    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool;

    /**
     * 同一キーの全行を置換する(single=false スキーマ用)。
     *
     * @param list<mixed> $values
     */
    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool;

    /** 指定キーの全行を削除する。行が元々無い場合も true を返す(冪等) */
    public function delete(ObjectType $type, int $objectId, string $key): bool;
}
```

#### 実装者への規約

- 実装は **null を「特別な意味」に使ってはならない**。`get()` の null は「行なし」または「null相当値」のどちらでもあり得る曖昧な値であり、意味判定は常に `has()` が担う。
- `getMultiple()` は「キーが結果配列に存在するか」で has 相当の情報を持ち帰る。値がnullでもキーは含める。
- 例外: ストレージ層のI/O失敗(外部API障害等)は `TMSWP\Core\Exception\StorageException` を送出してよい。WP標準実装(4.6)は例外を送出しない。

### 4.6 `TMSWP\Core\Meta\WpMetaStorage`

#### 役割

`MetaStorageInterface` のWP標準実装。WPメタAPI(`metadata_exists` / `get_metadata_raw` / `get_metadata` / `update_metadata` / `add_metadata` / `delete_metadata`)への薄い委譲であり、本クラスがコアのデフォルトストレージとなる。WP関数呼び出しを本クラスに閉じ込めることで、`MetaBag` 以上の層をWP非依存でユニットテスト可能にする(DR-12の2層テスト戦略)。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

final class WpMetaStorage implements MetaStorageInterface
{
    public function has(ObjectType $type, int $objectId, string $key): bool;
    public function get(ObjectType $type, int $objectId, string $key): mixed;
    public function getAll(ObjectType $type, int $objectId, string $key): array;
    public function getMultiple(ObjectType $type, int $objectId, array $keys): array;
    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool;
    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool;
    public function delete(ObjectType $type, int $objectId, string $key): bool;
}
```

#### 振る舞い仕様(WP API対応表)

| メソッド | 実装 |
| --- | --- |
| `has()` | `metadata_exists($type->metaType(), $objectId, $key)` |
| `get()` | `get_metadata_raw($type->metaType(), $objectId, $key, true)`。行が無ければ null(WPメタは null を物理保存できないため、raw の null ⇔ 行なし が成立する) |
| `getAll()` | `array_values(get_metadata_raw($type->metaType(), $objectId, $key, false) ?? [])`(single=false かつ行なし時の null を `?? []` で吸収) |
| `getMultiple()` | `get_metadata($type->metaType(), $objectId)` で全メタを1回で取得(WPのメタキャッシュに載る)→ 要求キーのうち存在するものだけ `maybe_unserialize` 済み単一値(`$all[$key][0]`)で返す。single=false のキーがここで要求された場合も先頭行のみ返す(バルクは単一値スキーマ用途) |
| `set()` | `update_metadata($type->metaType(), $objectId, $key, $value)`。WPの戻り値 int(新規meta_id)/true は true に正規化、false は false |
| `setAll()` | `delete_metadata(...)` 後に `$values` を順に `add_metadata(...)`。途中失敗時は false を返す(ロールバックはしない。wpdbにトランザクション保証がないため) |
| `delete()` | `delete_metadata($type->metaType(), $objectId, $key)`。WPは行が無いと false を返すが、本実装は冪等性のため「行が無い場合」は true に正規化する(`has()` を先行確認) |

- `get_metadata_raw()`(WP 5.5+)はコアの default 注入層(`get_metadata_default()` / `filter_default_metadata`)を経由しないため、`register_meta` の default 介在が原理的に起きない。default 介在の回避は実装内の順序契約(`has()` の先行呼び出し)ではなく、コアAPI 1呼び出しの保証による。同関数は `_` 接頭辞なし・`@access private` 注記なしの正規の公開関数(`@since 5.5.0`)で、コア自身が default 機構の土台として安定使用している。なおこの最適化はWP実装(`WpMetaStorage`)固有であり、`AcfMetaStorage` 等の外部ストレージ実装には適用されない。
- `update_metadata` を経由するため、`register_meta` で登録した `sanitize_callback` は `sanitize_meta()` フィルタとしてWPコアが自動適用する。FW側で二重適用しない。
- slash 処理: `update_metadata` は `wp_unslash` 前提の生値を期待する。本クラスは受け取った値をそのまま渡す(呼び出し側=Form層等がunslash済みの値を渡す規約。rules/ に明記)。

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

- `accepts(type, value)` の判定: String→`is_scalar() || $value instanceof Stringable`、Boolean→`is_bool()`、Integer→`is_int()`、Number→`is_int() || is_float()`、Array→`is_array()`、Object→`is_array()`(連想配列で表現)。スキーマの `default` はキャストに頼らず宣言時点で正しい型を書かせる(緩い受け入れはしない)。

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
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly ObjectType $objectType,
        private readonly int $objectId,
        private readonly MetaStorageInterface $storage,
        private readonly MetaSchemaSet $schemas,
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

    /** @return array<string, mixed> 宣言済み全キーの生値マップ(バルク取得) */
    public function all(): array;

    // ---- 書き込み ----

    public function set(string $key, mixed $value): bool;
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

- `get($key, $default)`: キャストを行わない生値アクセス。single=false スキーマのキーに対しては `storage->getAll()` の list を返す。
- `int()`/`float()`/`bool()`/`string()`: 上記解決順 + `ValueCaster::to*()`。
- `list($key)`: スキーマが `single: false` → `storage->getAll()`(空配列なら解決順2以降へ)。`single: true` または未宣言キー → `storage->get()` の値を `ValueCaster::toList()`。
- `json($key)`: `storage->get()` の値を `ValueCaster::toJsonArray()`。
- `has($key)`: `storage->has()` への委譲。スキーマdefaultの有無は影響しない(「行が存在するか」のみ)。
- `all()`: `storage->getMultiple($type, $id, $schemas->keys())` を1回呼び、欠けたキーはスキーマdefault(無ければ null)で補完した `array<string, mixed>` を返す。**宣言済みキーのみ**が対象。
- `set($key, $value)`: スキーマが `single: false` の場合、`$value` が list でなければ `InvalidArgumentException`、listなら `storage->setAll()`。それ以外は `storage->set()`。サニタイズは行わない(WpMetaStorage 経由なら `register_meta` の sanitize_callback がWPコアで適用される。4.6参照)。
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
     * エンティティ1クラスを登録する。エンティティの Initializer(PostTypeInitializer 等)が初期化時に呼ぶ。
     *
     * @param class-string $entityClass #[AsPostType|AsTaxonomy|AsUserRole] が付与されたクラス
     * @param string $subtype Post→投稿タイプスラッグ / Term→タクソノミースラッグ / User→''(固定)
     * @throws \TMSWP\Core\Exception\InvalidSchemaException 4.1/4.4の検証違反、
     *         #[WithMetaRevisions] の付与違反(4.11)
     * @throws \TMSWP\Core\Exception\DuplicateMetaKeyException 同一エンティティ内キー重複、
     *         または同一 (ObjectType, subtype, key) の組を別エンティティが二重宣言した場合
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
     * 外部管理キーの宣言(acf-bridge が呼ぶ)。該当キーは registerAll() で register_*_meta をスキップする。
     * PHP側 #[MetaSchema] と同一 (objectType, subtype, key) が両方に存在する場合は
     * DuplicateMetaKeyException(DR-06: acf-json が真実源、二重宣言はエラー)。
     */
    public function markExternallyManaged(ObjectType $objectType, string $subtype, string $key): void;

    public function isExternallyManaged(ObjectType $objectType, string $subtype, string $key): bool;

    /**
     * init フック(priority 20)から1回だけ呼ばれ、全スキーマを register_*_meta へ登録する。
     * 2回目以降の呼び出しは LogicException。
     */
    public function registerAll(): void;
}
```

#### 振る舞い仕様

**registerEntity() の処理手順(擬似コード)**:

```
1. ReflectionClass($entityClass) の getConstants(ReflectionClassConstant::IS_PUBLIC)
   を列挙し、#[MetaSchema] 属性を持つ定数のみ対象にする
   (定数名プレフィックス規約は課さない。属性の有無だけが宣言の根拠。META_ 接頭辞は推奨規約として rules/ に記載)
2. 各定数 → MetaSchemaDefinition::fromAttribute() で検証付き生成
3. エンティティ内でメタキー文字列が重複 → DuplicateMetaKeyException
4. レジストリ全体で (objectType, subtype, key) が既登録 → DuplicateMetaKeyException
   (同一keyでも subtype が異なれば共存可。例: 'price' を product と shop の両CPTで宣言)
5. #[UseMetaStorage] 属性を読み取り、storageClass を記録(4.10)
6. #[WithMetaRevisions] 属性を読み取り(4.11)、付与されている場合は
   (a) objectType === ObjectType::Post であること
   (b) エンティティの definition() の supports が 'revisions' を含むこと
   を検証(違反は InvalidSchemaException)し、revisions フラグを記録
7. entityClass => {objectType, subtype, MetaSchemaSet, storageClass, revisions} を内部マップへ格納
```

- 親クラスから継承した定数も `getConstants()` に含まれるが、**宣言クラス(`ReflectionClassConstant::getDeclaringClass()`)が `$entityClass` 自身であるものだけ**を対象とする。エンティティ継承によるスキーマの暗黙共有は行わない(明示性優先)。
- メタスキーマは探索結果ファイル(Kernel設計書 §6)に載せない。探索結果ファイルが持つのはコンポーネントの属性と引数だけで、メタスキーマは `registerEntity()` が初期化時に定数のリフレクションで毎回構築する。

**bagFor() の処理手順**:

```
1. 内部マップから entityClass のエントリを取得。なければ EntityNotRegisteredException
   (メッセージ例: "ProductPost is not registered. Did you forget #[AsPostType]?")
2. objectType->resolveId($object) でIDを解決
3. storageClass(デフォルト WpMetaStorage::class)を container->get() で解決
4. new MetaBag(entityClass, objectType, id, storage, schemas) を返す
```

**registerAll() の処理手順**:

```
foreach 登録済みエンティティ:
  foreach MetaSchemaSet:
    if isExternallyManaged(objectType, subtype, key): continue   # ACF管理下はWP登録しない(DR-06)
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
    match objectType:
      Post: register_post_meta(subtype, key, args)
      Term: register_term_meta(subtype, key, args)
      User: register_meta('user', key, args)                      # userにsubtypeは無い
```

- フック登録はKernelが行う: `add_action('init', $registry->registerAll(...), 20)`(第一級callable構文)。priority 20 は、エンティティの `register_post_type`/`register_taxonomy`(init 10、エンティティ設計書)を確実に先行させるため。リビジョン保存宣言は属性(4.11)であり registerEntity() 時点で確定しているため、init より前に呼ぶべき有効化処理は存在しない。
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

- `MetaSchemaRegistry::registerEntity()` が読み取り、`bagFor()` でのストレージ解決に使う。属性なし時は `WpMetaStorage::class`。
- `storageClass` がコンテナで解決できない、または `MetaStorageInterface` 非実装の場合、`registerEntity()` 時に `InvalidSchemaException`(遅延させず起動時に失敗させる)。
- 1クラスに複数付与した場合(`IS_REPEATABLE` ではないためPHPがコンパイルエラーにする)は考慮不要。
- 利用例(acf-bridge 利用時):

```php
#[AsPostType]
#[UseMetaStorage(\TMSWP\AcfBridge\AcfMetaStorage::class)]
final class ProductPost extends PostType { /* ... */ }
```

- ストレージ差し替えは**読み書き経路のみ**に影響する。`register_*_meta` の発行可否は `markExternallyManaged()`(キー単位)が決める。acf-bridge は「ACF管理キーを markExternallyManaged で申告」+「エンティティに UseMetaStorage を付けさせる」の両輪で統合する(acf-bridge設計書)。

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
- 宣言は属性であるため「いつ有効化するか」という時間軸の概念が存在しない。宣言は `registerAll()`(init 20)実行前に構造上確定しているため、呼び出し順序の契約・手遅れ時の例外・レジストリの可変メソッドを持たない(設計根拠6)。
- ACF管理下キー(`isExternallyManaged()` = true)はそもそも `register_post_meta` されないため本機構の対象外。ACFフィールドのプレビュー対応はacf-bridge側の責務とする(本書はスコープ外と明記するに留める)。
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
use TMSWP\Core\Option\WpOptionStorage;

// enum もクラスの一種であるため TARGET_CLASS で enum に付与できる
#[Attribute(Attribute::TARGET_CLASS)]
final class AsOptionGroup
{
    /**
     * @param string $prefix 全キーに付くオプション名プレフィックス(例: 'myapp_')。
     *                       実オプション名 = prefix . ケースの backing value。空文字可だがスターターでは必須を推奨。
     * @param class-string<OptionStorageInterface> $storage グループ単位のストレージ実装
     */
    public function __construct(
        public readonly string $prefix = '',
        public readonly string $storage = WpOptionStorage::class,
    ) {
    }
}

/**
 * オプショングループ enum の各ケースに付与する。
 * PHP には enum case 専用の属性ターゲットフラグ(TARGET_ENUM_CASE 相当)が無いため、
 * ターゲットは無限定(#[Attribute])とし、enum case 以外への付与はブート時検証(4.13)で
 * InvalidSchemaException とする。meta 用 #[MetaSchema](TARGET_CLASS_CONSTANT 単独)とは
 * ターゲット宣言・反射経路を別管理とする点に注意。
 */
#[Attribute]
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
- `#[OptionSchema]` のターゲットは無限定宣言のため enum ケース以外への付与がコンパイルエラーにならないが、収集経路が `getCases()` のみであるため宣言としては無効である。Discovery が走査時に enum ケース以外への付与を検出した場合は `InvalidSchemaException` とする(黙殺しない)。

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

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

interface OptionStorageInterface
{
    /** $name は プレフィックス適用済みの実オプション名 */
    public function has(string $name): bool;

    /** 未保存時は null。存在判定には has() を使うこと */
    public function get(string $name): mixed;

    /** @param bool|null $autoload null はWPに委ねる(既存値を変更しない) */
    public function set(string $name, mixed $value, ?bool $autoload = null): bool;

    /** 冪等。元々存在しない場合も true */
    public function delete(string $name): bool;
}

final class WpOptionStorage implements OptionStorageInterface
{
    public function has(string $name): bool;
    public function get(string $name): mixed;
    public function set(string $name, mixed $value, ?bool $autoload = null): bool;
    public function delete(string $name): bool;
}
```

#### WpOptionStorage 振る舞い仕様

```
has($name):
  $sentinel = new \stdClass();              # 毎回生成するローカルセンチネル(外部に漏れない)
  return get_option($name, $sentinel) !== $sentinel;

get($name):
  $sentinel = new \stdClass();
  $value = get_option($name, $sentinel);
  return $value === $sentinel ? null : $value;

set($name, $value, $autoload):
  return update_option($name, $value, $autoload);
  # update_option は新規時に add_option へ委譲し $autoload を引き継ぐ。
  # 同値更新で false が返るWP仕様はそのまま返す(エラーと区別しない。rules/ に注意書き)

delete($name):
  if (!has($name)) return true;             # 冪等正規化
  return delete_option($name);
```

- センチネルオブジェクトは `WpOptionStorage` の実装詳細としてのみ存在し、インターフェース契約・戻り値には一切現れない。「APIとしてのnullセンチネル全廃」と矛盾しない。

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
    public function set(string $key, mixed $value): bool;
    public function delete(string $key): bool;

    /** 宣言済み全キーを削除する(アンインストール用)。削除した実オプション名の list を返す */
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
- **未宣言キーの扱い**: enum ファサード(4.16)はケースに存在しないキーを表現できないため、「グループ経由の未宣言キー読み取り」はファサードの対象外である。グループ名前空間内の未宣言キー(プレフィックス付きで他オプションを読む用途)は、本クラス `OptionRepository::get()` 等の**直接利用が正式経路**である(`SiteOption::repository()->get('legacy_key')` の形。4.16)。未宣言キーは「許可するがスキーマ恩恵なし」で、プレフィックスは**適用される**(グループ経由のアクセスは常にグループの名前空間内)。素のオプション名(他プラグインのオプション等)は `OptionStorageInterface`(`WpOptionStorage`)の直接利用を正とし、`get_option()` 直書きは非推奨とする(この経路規約は rules/ に明記)。
- `OptionRepositoryFactory::for()` の構築手順:

```
1. メモ化済みなら返す
2. enum_exists($groupClass) かつ string backed(ReflectionEnum::getBackingType() が string)
   でなければ InvalidSchemaException
3. ReflectionEnum: #[AsOptionGroup] を読む。なければ InvalidSchemaException。
   ReflectionEnum::getCases() で全ケースを列挙し、#[OptionSchema] 付きケースを
   ReflectionEnumUnitCase::getAttributes() 経由で OptionSchemaDefinition::fromAttribute()
   により検証付き収集(属性なしケースは宣言キーとして扱わずスキーマ恩恵なし。
   enum に継承は無いため 4.9 の宣言クラス規則は不要)
4. storage を container->get(attribute->storage) で解決
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
    /** 自グループのリポジトリを取得する。テスト時は repository() 差し替えではなく
        コンテナへのモックストレージ束縛で代替する(rules/ 参照) */
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

    // ---- ケース単位の書き込み ----

    final public function set(mixed $value): bool;
    final public function delete(): bool;

    // ---- グループ単位操作(staticメソッド) ----

    /** @return array<string, mixed> 宣言済み全キーの値マップ(プレフィックスなしキーで返す) */
    final public static function all(): array;

    /** 宣言済み全キーを削除する(アンインストール用)。削除した実オプション名の list を返す */
    final public static function deleteAll(): array;
}
```

#### 振る舞い仕様

- すべてのメソッドは `static::repository()` 経由で `OptionRepository` の同名メソッドへ1:1委譲する薄いラッパ。ケース単位メソッドはキーとして `$this->value` を渡す。ロジックの二重実装を禁止する。
- `repository()` の実装: `Kernel::container()->get(OptionRepositoryFactory::class)->for(static::class)`。Kernel未ブート時は `KernelNotBootedException`(Kernel設計書の例外。コンテナアクセサの正準名はKernel設計書に従う)。
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

### 4.17 例外クラス

本層が送出する例外。すべて `TMSWP\Core\Exception\` 配下(コーディング規約)。基底例外(`RuntimeException`/`LogicException` 系の階層)はKernel設計書の例外章に従い、ここでは本層固有のクラスのみ定義する。

```php
namespace TMSWP\Core\Exception;

/** スキーマ宣言の不備(type不正・default型不一致・属性欠落・storage解決不能 等)。\LogicException 系 */
final class InvalidSchemaException extends LogicException {}

/** 同一スコープ内のメタキー二重宣言、ACF管理キーとのPHP宣言衝突(DR-06)。\LogicException 系 */
final class DuplicateMetaKeyException extends LogicException {}

/** MetaSchemaRegistry 未登録エンティティへの bagFor()/objectTypeOf()。\LogicException 系 */
final class EntityNotRegisteredException extends LogicException {}

/** 引数不正(ObjectType とWPオブジェクトの不一致、ID<1、single=false への非list set 等)。\LogicException 系 */
final class InvalidArgumentException extends LogicException {}

/** 外部ストレージ実装のI/O失敗用(コアのWP標準実装は送出しない)。\RuntimeException 系 */
final class StorageException extends RuntimeException {}
```

- オプショングループ内キーの二重宣言を表す例外は持たない: グループは backed enum であり(4.16)、backing value の重複は PHP がコンパイル時に Fatal とするため、実行時例外は不要。
- ここでの `LogicException`/`RuntimeException` は `TMSWP\Core\Exception\` 配下のFW基底例外を指す(グローバルの `\LogicException` を直接継承するか否かはKernel設計書の例外章の決定に従う)。
- 方針: スキーマ・宣言系の誤りはすべて「ブート時に LogicException 系で即死」させ、本番実行時の黙殺・フォールスルー(旧FWのフィルタ名不一致が3年以上発覚しなかった原因)を構造的に防ぐ。

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

    #[MetaSchema(type: MetaValueType::Array, single: false)]
    public const META_RELATED_IDS = 'related_id';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')->label('製品', '製品')->hasArchive();
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
```

### ACFストレージへのオプトイン(acf-bridge 導入時)

```php
#[AsPostType]
#[UseMetaStorage(\TMSWP\AcfBridge\AcfMetaStorage::class)]
final class CampaignPost extends PostType
{
    // ACF管理下のキーは acf-json が真実源。PHP側で同一キーを #[MetaSchema] 宣言するとブート時エラー(DR-06)
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('campaign')->label('キャンペーン', 'キャンペーン');
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

命令的な有効化呼び出しは存在しない。宣言は `registerAll()`(init 20)が読み取り、`register_post_meta` へ `revisions_enabled => true` を渡す(4.9/4.11)。

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
- [ ] default型不一致・single=false+default併用・sanitize形式不正・空キーが、いずれもブート時に `InvalidSchemaException` となる(ユニットテスト)。type の不正値は `MetaValueType` enum 受けにより実行前(IDE/PHPStan/PHPの属性インスタンス化)に拒否されるため、ブート時検証・例外テストは存在しない。
- [ ] 同一エンティティ内・同一 (ObjectType, subtype) スコープのキー重複が `DuplicateMetaKeyException` となる。subtypeが異なる同名キーは共存できる。
- [ ] `markExternallyManaged()` 済みキーは `register_*_meta` が発行されず、同キーのPHP側 `#[MetaSchema]` 宣言が存在する場合は `DuplicateMetaKeyException` となる(acf-bridge連携点のコア側単体で検証)。
- [ ] `MetaBag` の全getterについて「ストレージ値 → 引数default → スキーマdefault → ゼロ値/null」の解決順がユニットテスト(モックストレージ、Brain Monkey不要)で網羅されている。
- [ ] `ValueCaster` のキャスト表(4.7)の全セルがユニットテストで固定されている。
- [ ] `MetaStorageInterface` 契約テスト(has/get分離、getMultipleのキー有無セマンティクス、deleteの冪等性)が抽象テストケースとして存在し、`WpMetaStorage` が統合テスト(wp-env)でそれを満たす。
- [ ] `register_meta` の `default` が設定されたキーでも、`WpMetaStorage::get()`/`getAll()` は default を介在させず「行なし」を null / `[]` として返す(統合テスト。`get_metadata_raw()` 経路の実証)。
- [ ] null/false/空文字を保存した場合に `has()` が true、`get()` がその値を返す(nullセンチネル不在の実証。メタ・オプション両方)。
- [ ] `#[UseMetaStorage]` 付きエンティティの `meta()` が指定ストレージ実装で読み書きし、属性なしエンティティは `WpMetaStorage` を使う。不正なstorageClassはブート時例外。
- [ ] `ObjectType::resolveId()` が種別不一致のWPオブジェクト・ID<1 で `InvalidArgumentException` を送出する。
- [ ] `#[WithMetaRevisions]` 付きエンティティのメタが `revisions_enabled => true` で登録され(統合テスト)、Post以外のエンティティ・revisions非サポート投稿タイプへの付与がブート時(`registerEntity()`)に `InvalidSchemaException` となる。
- [ ] `OptionAccess` を use したオプショングループ enum でプレフィックス適用(`name()`)・autoload指定・`deleteAll()` が仕様どおり動作する(統合テスト)。
- [ ] オプショングループ enum のスキーマ収集が `ReflectionEnum::getCases()` + `ReflectionEnumUnitCase::getAttributes()` 経由で行われ、string backed でない enum・`#[AsOptionGroup]` 欠落がブート時 `InvalidSchemaException` となる(ユニットテスト)。
- [ ] PHPStan拡張: `MetaBag::get(ProductPost::META_PRICE)` が `int`(default宣言あり時)/`int|null`(なし時)へ絞り込まれること、他エンティティ定数の混入(`tmswp.metaKeyOwnership`)と宣言済みキーの文字列リテラル直書き(`tmswp.metaKeyLiteral`)が報告されることを、PHPStanのルールテスト(`RuleTestCase`/`TypeInferenceTestCase`)で検証。オプション側は `SiteOption::ContactEmail->get()`(enum ケースレシーバ)の絞り込みを `TypeInferenceTestCase` で検証(キー所属の誤りは enum の型システム上表現不能のためルールテストは持たない)。
- [ ] 本層の全クラスが `declare(strict_types=1)`・final(基底・interface・enumを除く)・完全型宣言で、`composer analyse`(PHPStan level max, baselineなし)を通過する。
- [ ] マジックメソッド(`__callStatic`/`__get`/`__set`)・`extract()`・グローバルフィルタによるストレージ差し替えがコード上に存在しない(PHPCS/レビューで確認)。

### 検証コマンド

```bash
composer lint      # php-cs-fixer (PSR-12 + strict_types)
composer analyse   # PHPStan level max(自前PHPStan拡張も解析対象に含む)
composer test      # ユニット(WP非依存: ValueCaster/MetaBag/MetaSchemaDefinition/OptionRepository ほか)
                   # + 統合(wp-env: WpMetaStorage/WpOptionStorage/registerAll/#[WithMetaRevisions])
composer qa        # 上記一括
```

