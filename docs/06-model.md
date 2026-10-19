# 06. Model層設計書 — 独自テーブル(TableDefinition / Migrator / QueryBuilder / Connection)

## 目的

WordPressの独自テーブルを「スキーマ宣言 → 自動マイグレーション → 型安全なクエリ」の一貫したフローで扱うModel層を定義する。旧iwf2bの`AbstractModel`が持っていた「クラスにスキーマを宣言するだけでテーブルが作られ、テーブル名を一切書かずにCRUDできる」体験を維持しつつ、以下を全面的に解決する。

1. **SQLインジェクションの構造的排除**: すべてのクエリは`wpdb->prepare`を経由し、エスケープなしの文字列補間が型システムとAPI設計のレベルで不可能になる。
2. **暗黙ライフサイクルの廃止**: 毎リクエスト`init`フックでのマイグレーション実行をやめ、明示的な契機(有効化フック/テーマ切替/admin_init版数比較/WP-CLI)に限定する。
3. **AIフレンドリー化**: `__callStatic`によるwpdb透過プロキシを全廃し、全APIを静的解析・IDE補完が完全に効く明示シグネチャにする。

フルORM(リレーション定義・自動ハイドレーション・エンティティ追跡)は実装しない(DR-08)。提供するのは「スキーマ宣言+dbDeltaマイグレーション+prepare強制クエリビルダ+任意の軽量DTOハイドレーション」の4点である。

## スコープ(対象パッケージ)

- `packages/core` — `TMSWP\Core\Model\` 名前空間の全クラス、関連例外(`TMSWP\Core\Exception\`)、およびクエリ語彙enum(`TMSWP\Core\Query\`)。
- 本文書が正準定義するクラス:
  - `TMSWP\Core\Model\Model`(基底)
  - `TMSWP\Core\Model\TableDefinition`
  - `TMSWP\Core\Model\QueryBuilder`
  - `TMSWP\Core\Model\Migrator`
  - `TMSWP\Core\Model\Connection`
  - `TMSWP\Core\Model\MigrateCommand`(WP-CLI、本文書で新設定義)
  - クエリ語彙enum: `TMSWP\Core\Query\Compare` / `TMSWP\Core\Query\CastType` / `TMSWP\Core\Query\SortOrder`(QueryBuilder節で完全定義。03-entities.mdのエンティティクエリビルダ3種と共有)
  - 例外: `SchemaException` / `MigrationException` / `InvalidQueryException` / `QueryException` / `HydrationException`
- 対象外: `#[AsModel]`属性そのものの定義(Attribute設計書)、Discoveryによる走査機構(Kernel設計書)。本文書はそれらとの統合契約のみ規定する。

## 依存文書

- Kernel/Discovery設計書 — `Kernel::boot()`、`#[AsModel]`走査、Containerへの`Connection`/`Migrator`束縛、`Migrator::listen()`の呼び出し(本文書「Kernel統合契約」参照)。
- Attribute設計書 — `TMSWP\Core\Attribute\AsModel`の属性定義。
- QA設計書(DR-12) — `composer test / analyse / qa`の実行系。受け入れ基準の検証コマンドが依存。

---

## 全体像

```
app/Model/InquiryModel.php          ← 利用者が書くのはこれだけ
  #[AsModel]
  final class InquiryModel extends Model { table(): TableDefinition }
        │
        │  Kernel(Discovery)が #[AsModel] を発見
        ▼
Migrator ──── register(InquiryModel::class)
  │  契機: 有効化フック / after_switch_theme / admin_init / WP-CLI
  │  TableDefinition::toSql() → sha256ハッシュ比較 → 差分時のみ dbDelta()
  ▼
Connection(wpdbラッパ) ←── QueryBuilder(全クエリをprepare経由で実行)
                       ←── Model 静的API(find/insert/update/delete/count/query)
```

責務分担:

| クラス | 責務 |
|---|---|
| `Model` | 1テーブル=1クラスの静的ファサード。スキーマ宣言の置き場とCRUDショートカット |
| `TableDefinition` | fluentなスキーマ宣言とdbDelta互換`CREATE TABLE`文の生成 |
| `Migrator` | スキーマハッシュ比較によるマイグレーション実行と契機(フック/CLI)の管理 |
| `QueryBuilder` | SELECT/UPDATE/DELETEの組み立て。プレースホルダ強制 |
| `Connection` | wpdbの薄いラッパ。prefix解決・prepare実行・テスト時差し替え点 |

---

## 共通規約

本節の規約はModel層の全クラスに適用される。各クラスの仕様はこの規約を前提として記述する。

### R1. 識別子検証

SQLに埋め込まれる識別子(テーブル名・カラム名・エイリアス・インデックス名)は、利用箇所を問わず以下の正規表現で検証し、不一致なら`InvalidQueryException`(クエリ文脈)または`SchemaException`(スキーマ文脈)を投げる。検証済み識別子のみがSQL文字列に直接連結される。

- 単純識別子: `/^[A-Za-z_][A-Za-z0-9_]*$/`
- 修飾識別子(`alias.column`): `/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/`
- エイリアス付き(`expr AS alias`形式を受け付ける箇所のみ): 上記修飾識別子 + `/\s+(?i:AS)\s+[A-Za-z_][A-Za-z0-9_]*/` の組合せ
- ベアテーブル名(prefix無し): `/^[a-z][a-z0-9_]*$/`

### R2. raw断片の制約(プレースホルダ強制)

`whereRaw` / `orWhereRaw` / `selectRaw` / `innerJoinRaw` / `leftJoinRaw` に渡すSQL断片は以下を満たさなければならない。規約1〜3はランタイム検査であり違反は`InvalidQueryException`、規約4はPHPStanによる静的検査である。これにより「値の直接埋め込み」が構造的に不可能になる。

1. 文字 `'` `"` `` ` `` `;` のいずれも含まないこと(リテラル値・識別子クォート・複文の全面禁止)。値は必ずプレースホルダで渡す。断片内に直接書く識別子はコンパイル時定数に限り(規約4)、動的に決まる識別子は`%i`プレースホルダ+`$bindings`経由とする。
2. 断片内のプレースホルダ個数と`$bindings`の要素数が一致すること。プレースホルダの抽出は `/(?<!%)%(?:\d+\$)?[dfsi]/` で行う(`%i`はWP 6.2+の識別子プレースホルダ。下限WP 7.0のため常に利用可)。
3. リテラルの`%`(例: `DATE_FORMAT`の書式)は`%%`とエスケープして書くこと(最終的に`wpdb->prepare`を通過するため)。この規約はrules/にも記載する。
4. **raw断片はコンパイル時定数文字列であること(`@param literal-string`による静的強制)**: raw断片の文字列にPHP変数・ユーザ入力を連結してはならない(例: `->whereRaw("{$userColumn} = %s", [$v])` は禁止)。識別子を動的に与える必要がある場合は`%i`プレースホルダ+`$bindings`経由を必須とする(例: `->whereRaw('%i = %s', [$column, $value])`)。本規約は、raw断片を受ける引数 — `whereRaw()`/`orWhereRaw()`の`$sql`、`selectRaw()`の`$expression`、`innerJoinRaw()`/`leftJoinRaw()`の`$on` — に`@param literal-string`を宣言することで、`composer analyse`(PHPStan level max、DR-12)が機械検査する(`innerJoinRaw`/`leftJoinRaw`の`$table`は別途R1検証されるベア名のため対象外)。PHPStanのliteral-string型はリテラル同士の連結・`sprintf`(リテラル書式)の結果も追跡するため、PHP変数を連結した断片はlevel maxで静的解析エラーになる(Doctrine DBAL等で確立した手法)。
   - **効力範囲**: literal-stringが機械保証するのはraw断片の**非動的起源**(PHP変数・ユーザ入力の連結禁止=本規約の主目的)であり、開発者が手書きした定数述語の妥当性(例: `1=1 OR ...`のような恒真条件)までは保証しない。その層は規約1のクォート文字検査とコードレビューで担保する。本規約はrules/にも引き続き記載する(PHPStanを実行しない環境では機械的強制が及ばないため)。

ランタイムSQLトークナイザ(raw断片トークンの許可SQLキーワード集合検証)は**実装しない**(確定)。規約4のliteral-string静的検査と規約1〜3のランタイム検査(クォート文字・複文の禁止/プレースホルダ数一致/`%%`エスケープ)の組合せで足り、許可キーワードリストの保守・誤検知対応のコストに見合わないため。

### R3. 値 → プレースホルダ写像

バインド値はPHP型に応じて以下のプレースホルダと前処理に確定的に写像する。

| PHP型 | プレースホルダ | 前処理 |
|---|---|---|
| `int` | `%d` | なし |
| `float` | `%f` | なし |
| `bool` | `%d` | `(int)`キャスト |
| `string` | `%s` | なし |
| `\Stringable` | `%s` | `(string)`キャスト |
| `\DateTimeInterface` | `%s` | `->format('Y-m-d H:i:s')`(タイムゾーン変換は行わない。サイトTZへの正規化は呼び出し側責務) |
| `\BackedEnum` | backing typeに従い`%d`/`%s` | `->value` |
| `null` | — | 文脈依存(`= null`→`IS NULL`変換、IN句内は`InvalidQueryException`) |
| `array` | — | `InvalidQueryException`(`whereIn`を使うこと) |
| その他のオブジェクト | — | `InvalidQueryException` |

### R4. 例外体系

すべて`TMSWP\Core\Exception\`配下。実行前に検出される誤り(`SchemaException`・`InvalidQueryException`)はFWの基底`LogicException`を、実行時の失敗(`MigrationException`・`QueryException`・`HydrationException`)はFWの基底`RuntimeException`を継承する。FWの基底とマーカーinterface`TmswpExceptionInterface`はKernel設計書の例外章(`02-kernel.md` §9)が定義する。`QueryException`はエンティティ層(`03-entities.md` §1.7)と共有するため`final`にせず、他は`final`とする。

| 例外 | 投げる状況 |
|---|---|
| `SchemaException` | TableDefinitionの定義不正(識別子不正・PK未定義・名前長超過・デフォルト値にクォート文字等) |
| `MigrationException` | dbDelta実行後にエラーまたは残差分が検出された場合 |
| `InvalidQueryException` | QueryBuilderのAPI誤用(不正演算子・raw断片規約違反・offset単独指定・WHEREなしupdate/delete等)。**実行前**に検出される静的な誤り |
| `QueryException` | クエリ**実行時**のDBエラー(`wpdb->last_error`非空、`query()`がfalse等)。メッセージに失敗SQL(prepare適用後)と`last_error`を含める |
| `HydrationException` | DTOハイドレーション失敗(列欠落・型変換不能・コンストラクタ非public等) |

### R5. wpdbエラーモード

Connectionは構築時に`$wpdb->suppress_errors()`等のグローバル状態を変更**しない**。エラー判定は各操作直後の戻り値と`$wpdb->last_error`で行う。

---

## クラス設計

### 1. `TMSWP\Core\Model\Connection`

#### 役割

wpdbの薄いラッパ。(1) テーブルprefix解決、(2) `prepare`の一元適用、(3) 戻り値の型正規化、(4) テスト時の差し替え点(ユニットテストではwpdbのモック/フェイクを注入)を提供する。Model層でwpdbに触れるのはこのクラスだけであり、WP関数境界をここに集約する(コーディング規約「WP関数呼び出しは薄い境界に寄せる」の適用)。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

final class Connection
{
    public function __construct(
        private readonly \wpdb $wpdb,
    ) {}

    /** global $wpdb から生成するファクトリ。Kernelのデフォルト束縛が使用する */
    public static function fromGlobal(): self;

    /** 緊急避難用の生wpdbアクセス。利用はrules/で「最終手段」と位置付ける */
    public function wpdb(): \wpdb;

    /** 現在サイトのテーブルprefix($wpdb->prefix) */
    public function prefix(): string;

    /** ベアテーブル名(R1検証済み)→ prefix付きフルテーブル名 */
    public function tableName(string $bareName): string;

    /** LIKEパターン用エスケープ($wpdb->esc_like委譲) */
    public function escLike(string $value): string;

    /** $wpdb->get_charset_collate() の委譲 */
    public function charsetCollate(): string;

    /**
     * prepare適用。$bindingsが空の場合はSQLにプレースホルダが無いことを検証し
     * (R2の抽出正規表現で走査、存在すればInvalidQueryException)、$sqlをそのまま返す。
     * 非空の場合はプレースホルダ数と要素数の一致を検証してから
     * $this->wpdb->prepare($sql, ...$bindings) を返す。
     */
    public function prepare(string $sql, array $bindings = []): string;

    /** SELECT実行。常に連想配列のリストを返す(内部でARRAY_A固定) @return list<array<string, mixed>> */
    public function select(string $sql, array $bindings = []): array;

    /** 先頭1行 @return array<string, mixed>|null */
    public function selectRow(string $sql, array $bindings = []): ?array;

    /** 先頭行先頭カラムの値。結果なしはnull */
    public function selectVar(string $sql, array $bindings = []): mixed;

    /** 先頭カラムのリスト @return list<mixed> */
    public function selectCol(string $sql, array $bindings = []): array;

    /** 書き込み系・DDL実行。影響行数を返す。失敗(false)時はQueryException */
    public function execute(string $sql, array $bindings = []): int;

    /**
     * 1行INSERT。$dataはカラム名(R1検証)=>値。R3写像でformatを導出し
     * $wpdb->insert()へ委譲。null値はwpdb仕様によりSQL NULLとして挿入される。
     * 失敗時QueryException。戻り値は$wpdb->insert_id。
     */
    public function insert(string $table, array $data): int;

    /** UPDATE。$whereは等値条件のみ(null値はIS NULL)。影響行数を返す。失敗時QueryException */
    public function update(string $table, array $data, array $where): int;

    /** DELETE。$whereは等値条件のみ。影響行数を返す。失敗時QueryException */
    public function delete(string $table, array $where): int;

    public function insertId(): int;

    public function lastError(): string;
}
```

#### 振る舞い仕様

- `tableName()`: ベアテーブル名をR1で検証し、`$wpdb->prefix . $bareName`を返す(マルチサイトは対象外。00-overview §2.2)。
- `select`系: 内部は`prepare()`を通した後、`$wpdb->get_results($sql, ARRAY_A)` / `get_row` / `get_var` / `get_col`へ委譲。実行後に`$wpdb->last_error`が非空なら`QueryException`。
- `insert/update/delete`: format配列はR3写像から導出して`$wpdb->insert/update/delete`に渡す。`$where`に配列値が含まれる場合は`InvalidQueryException`(IN条件は`QueryBuilder`を使う)。
- wpdbの戻り値は数値もstringで返るが、Connectionはこの段階では変換しない(型の正規化はDTOハイドレーションの責務)。

#### エッジケース

- `prepare()`にプレースホルダ数≠bindings数 → `InvalidQueryException`(wpdbのnotice発生前に検出)。
- `insert()`に空配列 → `InvalidQueryException`。AUTO_INCREMENTのみの行を作る場合は`Model::insert([])`を使う(後述。Connectionレベルでは`execute('INSERT INTO ... () VALUES ()')`で表現される)。

---

### 2. `TMSWP\Core\Model\TableDefinition`

#### 役割

fluent APIによるスキーマ宣言と、**dbDelta互換**の`CREATE TABLE`文の決定的生成。旧iwf2bの「行パースが脆い文字列形式」「dbDeltaフォーマット要件がユーザー任せ」を、生成側でフォーマット要件を全部満たすことで解決する。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

final class TableDefinition
{
    /** @var list<string> カラム行(宣言順を保持) */
    private array $columnLines = [];

    /** @var list<string> 宣言済みカラム名(重複検出用) */
    private array $columnNames = [];

    /** @var list<string> PRIMARY KEY / KEY / UNIQUE KEY 行 */
    private array $keyLines = [];

    private ?string $primaryKeyColumn = null;

    private bool $hasPrimaryKey = false;

    private ?string $charset = null;

    private ?string $collation = null;

    private function __construct(
        private readonly string $name,
    ) {}

    /** @param string $name ベアテーブル名(prefix無し、R1ベアテーブル名規則) */
    public static function create(string $name): self;

    // ---- カラム定義(すべて$thisを返すfluent) ----

    /** bigint(20) unsigned NOT NULL AUTO_INCREMENT + PRIMARY KEY */
    public function id(string $name = 'id'): self;

    public function string(string $name, int $length = 191, bool $nullable = false, ?string $default = null): self;

    public function text(string $name, bool $nullable = true): self;

    public function int(string $name, bool $nullable = false, ?int $default = null, bool $unsigned = false): self;

    public function bigint(string $name, bool $nullable = false, ?int $default = null, bool $unsigned = false): self;

    /** $defaultは精度劣化を避けるため文字列で受ける(例: '0.00') */
    public function decimal(string $name, int $precision = 10, int $scale = 2, bool $nullable = false, ?string $default = null, bool $unsigned = false): self;

    /** tinyint(1) NOT NULL DEFAULT 0|1。3値が必要な場合はint(nullable: true)を使う */
    public function bool(string $name, bool $default = false): self;

    /** $default === 'CURRENT_TIMESTAMP' のみ非クォートで出力。それ以外はクォート付きリテラル */
    public function datetime(string $name, bool $nullable = false, ?string $default = null): self;

    /**
     * longtextとして生成する(JSON型は使わない)。
     * 理由: MariaDBではJSON=longtextエイリアスでありDESCRIBE結果が恒常的に
     * dbDelta差分となるため。値のencode/decodeはアプリ側またはDTOハイドレーションで行う。
     */
    public function json(string $name, bool $nullable = true): self;

    /** bigint(20) unsigned + 同名単一カラムKEYを自動追加。FK制約は発行しない(dbDelta非対応のため) */
    public function foreignId(string $name, bool $nullable = false): self;

    /** エスケープハッチ。$definitionは型以降の定義文字列(例: 'mediumint(9) NOT NULL')。R2と同じクォート文字禁止検査を行う */
    public function rawColumn(string $name, string $definition): self;

    // ---- キー定義 ----

    /** id()を使わない場合の主キー指定。複合可 */
    public function primary(string ...$columns): self;

    /** @param string|list<string> $columns 省略時のキー名は implode('_', $columns) */
    public function index(string|array $columns, ?string $name = null): self;

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): self;

    // ---- テーブルオプション ----

    public function charset(string $charset): self;

    public function collation(string $collation): self;

    // ---- 読み出し ----

    /** ベアテーブル名 */
    public function tableName(): string;

    /** 単一カラム主キー名。複合主キー・未定義時はnull */
    public function primaryKey(): ?string;

    /** dbDelta互換CREATE TABLE文(後述の生成仕様) */
    public function toSql(Connection $connection): string;

    /** sha256ハッシュ: hash('sha256', $this->toSql($connection)) */
    public function hash(Connection $connection): string;
}
```

#### 型写像(確定)

| メソッド | 生成される型定義 |
|---|---|
| `id()` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| `string($n, $len)` | `varchar({$len})` |
| `text()` | `text` |
| `int()` / `int(unsigned: true)` | `int(11)` / `int(11) unsigned` |
| `bigint()` / `bigint(unsigned: true)` | `bigint(20)` / `bigint(20) unsigned` |
| `decimal($n, $p, $s)` | `decimal({$p},{$s})`(unsigned時は` unsigned`付加) |
| `bool()` | `tinyint(1)` |
| `datetime()` | `datetime` |
| `json()` | `longtext` |
| `foreignId()` | `bigint(20) unsigned` |

表示幅(`(11)`/`(20)`)はWPコアスキーマの慣習に合わせて出力する(MySQL 8系のDESCRIBE正規化はWPコアのdbDelta側が吸収する)。`string()`のデフォルト長191はutf8mb4でユニークインデックスを張れる上限(767バイト/4)に由来する。

#### NULL/デフォルトの出力規則(確定)

- `nullable: false` → ` NOT NULL`を付加。`$default`非null時はさらに` DEFAULT {literal}`。
- `nullable: true` かつ `$default === null` → ` DEFAULT NULL`。
- `nullable: true` かつ `$default`非null → ` DEFAULT {literal}`。
- 文字列デフォルトのリテラル化: `'`を含む場合は`SchemaException`。それ以外は`'{$default}'`とシングルクォートで包む。`datetime`の`'CURRENT_TIMESTAMP'`のみ非クォート。int/bool/decimalはそのまま数値表記。

#### toSql() 生成仕様(dbDelta互換要件、確定手順)

dbDeltaのパーサ要件を生成側で全て満たす。**実装はこの手順に厳密に従うこと。**

1. 検証: カラム1つ以上、`id()`または`primary()`による主キー定義があること(なければ`SchemaException`)。フルテーブル名(prefix込み)が64文字以下であること。インデックス名も64文字以下(自動命名で超える場合は`SchemaException`とし明示`$name`を要求)。
2. フルテーブル名 = `$connection->tableName($this->name)`。
3. ボディ行を次の順に並べる: カラム行(宣言順) → `PRIMARY KEY  ({cols})` → `UNIQUE KEY {name} ({cols})`(宣言順) → `KEY {name} ({cols})`(宣言順)。
4. dbDeltaパーサ要件として以下を厳守する:
   - 各カラム・各キーを**1行ずつ**改行で区切る。
   - `PRIMARY KEY`とカラムリストの間は**スペース2個**(`PRIMARY KEY  (id)`)。
   - インデックスは`INDEX`ではなく必ず`KEY`を使い、キー名を必須とする(`KEY idx_name (col1,col2)`、カンマ後にスペースなし)。
   - カラム名・テーブル名にバッククォート・アポストロフィを付けない。
   - 型名はすべて小文字、`NOT NULL`/`DEFAULT`/`AUTO_INCREMENT`等のキーワードは大文字。
   - 長さ指定可能な型には必ず長さを明記する(`int(11)`等)。
5. charset句: `charset()`/`collation()`が未指定なら`$connection->charsetCollate()`をそのまま使用。指定時は`DEFAULT CHARACTER SET {charset}`+(collation指定時)` COLLATE {collation}`を合成(値は`/^[A-Za-z0-9_]+$/`で検証)。
6. 最終形:

```
CREATE TABLE {full_table_name} (
{body行を ",\n" で連結}
) {charset句};
```

生成は宣言順に依存する純粋な文字列構築であり、同一宣言からは常にバイト単位で同一のSQLが得られる(ハッシュ比較の前提)。

#### エッジケース

- 同名カラムの二重宣言 → `SchemaException`。
- `index()`/`unique()`/`primary()`に未宣言カラム名 → `SchemaException`(`rawColumn`で宣言したカラムは`$name`が登録されるため参照可)。
- `id()`と`primary()`の併用 → `SchemaException`。
- `text`/`json`(longtext)カラムへの`index()` → `SchemaException`(プレフィックスインデックスは非対応。必要なら`rawColumn`+明示キーではなく`string`への変更を促すメッセージ)。

---

### 3. `TMSWP\Core\Model\Model`

#### 役割

1テーブル=1クラスの抽象基底。サブクラスは`table()`でスキーマを宣言するだけでよい。テンプレート・アプリコードから使う静的ファサード(DR-09の二層構成のファサード側)であり、内部はすべて`Connection`/`QueryBuilder`へ委譲する。旧`AbstractSingleton`継承は廃止。インスタンス化は不要であり、コンストラクタは持たない(状態を持たない)。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

abstract class Model
{
    /** 全Model共通のテスト用差し替えConnection(useConnection()で設定) */
    private static ?Connection $sharedConnection = null;

    /** @var array<class-string, TableDefinition> table()結果のクラス別キャッシュ */
    private static array $definitionCache = [];

    /** サブクラスが実装するスキーマ宣言。冪等・副作用なしであること */
    abstract public static function table(): TableDefinition;

    /**
     * 行の既定ハイドレーション先DTO。nullならstdClassで返す。
     * @return class-string|null
     */
    public static function rowClass(): ?string
    {
        return null;
    }

    /** prefix付きフルテーブル名 */
    final public static function tableName(): string;

    /** prefix無しベアテーブル名 */
    final public static function bareTableName(): string;

    /** 単一カラム主キー名。複合主キー・未定義時はSchemaException */
    final public static function primaryKey(): string;

    /** このモデルのテーブルを対象とした新規QueryBuilder(rowClass()が既定ハイドレーション先) */
    final public static function query(): QueryBuilder;

    /** 主キー検索。rowClass()非nullならDTO、nullならstdClass */
    final public static function find(int $id): ?object;

    /**
     * INSERT。$dataはカラム名=>値(R3写像)。戻り値はinsert_id。
     * $dataが空配列の場合は 'INSERT INTO {t} () VALUES ()' を発行する
     * (AUTO_INCREMENT IDの採番のみ行うテーブル向け)。失敗時QueryException。
     */
    final public static function insert(array $data): int;

    /**
     * UPDATE。$whereがintなら主キー等値条件。配列なら等値条件マップ
     * (値null→IS NULL。配列値はInvalidQueryException、IN条件はquery()を使う)。
     * 戻り値は影響行数。$whereが空配列ならInvalidQueryException(全行更新事故防止)。
     */
    final public static function update(array $data, array|int $where): int;

    /** DELETE。$whereの解釈・制約はupdate()と同一。戻り値は影響行数 */
    final public static function delete(array|int $where): int;

    /**
     * 件数取得のショートカット。$whereは等値条件マップ
     * (値が配列ならIN句、nullならIS NULL)。空配列で全件数。
     */
    final public static function count(array $where = []): int;

    /** 解決済みConnection(解決順序は振る舞い仕様参照) */
    final public static function connection(): Connection;

    /** テスト用シーム。null指定で解除。プロダクションコードでの使用はrules/で禁止 */
    final public static function useConnection(?Connection $connection): void;
}
```

#### 振る舞い仕様

- **Connection解決順序**(`connection()`): (1) `useConnection()`で設定された`$sharedConnection`、(2) Kernelがbootしていれば`Kernel::container()->get(Connection::class)`、(3) `Connection::fromGlobal()`。この順で最初に得られたものを返す。これによりWP非依存のユニットテスト(Brain Monkey + wpdbフェイク)が`useConnection()`だけで成立する。
- `table()`の結果は`$definitionCache[static::class]`にキャッシュする(1リクエスト中の再構築防止)。`table()`は同一内容を返す純粋関数として実装する規約(rules/に記載)。
- `query()`は`new QueryBuilder(static::connection(), static::bareTableName(), static::rowClass())`を返す。毎回新規インスタンス(ビルダはミュータブルなので共有しない)。
- `find()`は`static::query()->where(static::primaryKey(), $id)->first()`と等価。
- `count()`の条件展開: 文字列キー必須(整数キーが含まれたら`InvalidQueryException`。旧FWの「整数キー=生SQL断片」仕様は**廃止**)。値が配列→`whereIn`、null→`whereNull`、それ以外→`where(key, value)`。
- すべての公開メソッドは`final`。サブクラスが拡張するのは`table()`/`rowClass()`と、自前で追加する業務メソッド(`InquiryModel::recentByEmail()`等)のみ。

#### エッジケース

- `update`/`delete`に`int`を渡したが主キーが単一カラムでない → `primaryKey()`由来の`SchemaException`。
- `insert`の戻り値: AUTO_INCREMENT列がないテーブルでは`insert_id`は0になる。これは仕様(例外にしない)。

---

### 4. `TMSWP\Core\Model\QueryBuilder`

#### 役割

SELECT/UPDATE/DELETE文の組み立てと実行。**すべての値は`Connection::prepare`(=`wpdb->prepare`)を経由する。** ビルダ内部では「検証済み識別子の連結」と「プレースホルダ+bindings配列の蓄積」しか行わず、値がSQL文字列に直接連結されるコードパスは存在しない。旧`make_conditions`のSQLインジェクション(設計根拠参照)の再発を構造的に防ぐ。

#### クエリ語彙enum(`TMSWP\Core\Query\`、正準定義)

閉集合のクエリ引数(比較演算子・キャスト型・並び順)はbacked enumで表現する。本文書が以下の3 enumを正準定義し、Model層の`QueryBuilder`(並び順)に加えて、エンティティクエリビルダ3種(03-entities.md §7の`PostQuery`/`TermQuery`/`UserQuery`)が共有する。文字列の許可リスト照合と実行時例外ではなく型システムで表すことで、typoは静的解析(PHPStan level max、DR-12)とIDE補完の段階で検出され、補完で全候補が可視化される。

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

/** meta_query等の比較演算子。EXISTS/NOT EXISTSは含まない(whereMetaExists()/whereMetaNotExists()に一本化) */
enum Compare: string
{
    case Eq         = '=';
    case NotEq      = '!=';
    case Gt         = '>';
    case Gte        = '>=';
    case Lt         = '<';
    case Lte        = '<=';
    case Like       = 'LIKE';
    case NotLike    = 'NOT LIKE';
    case In         = 'IN';
    case NotIn      = 'NOT IN';
    case Between    = 'BETWEEN';
    case NotBetween = 'NOT BETWEEN';
    case Regexp     = 'REGEXP';
    case NotRegexp  = 'NOT REGEXP';
    case Rlike      = 'RLIKE';
}

/** meta値のキャスト型(WP meta_queryのtype指定) */
enum CastType: string
{
    case Numeric  = 'NUMERIC';
    case Binary   = 'BINARY';
    case Char     = 'CHAR';
    case Date     = 'DATE';
    case Datetime = 'DATETIME';
    case Decimal  = 'DECIMAL';
    case Signed   = 'SIGNED';
    case Time     = 'TIME';
    case Unsigned = 'UNSIGNED';
}

/** 並び順 */
enum SortOrder: string
{
    case Asc  = 'ASC';
    case Desc = 'DESC';
}
```

運用規則(確定):

- enum→SQL/WP引数文字列への変換は、各句の構築時に`->value`で**1箇所**で行う(backing valueがそのままWP/SQLの期待文字列)。
- enum化の対象は真に閉じた3軸(compare/type/direction)に限定する。開いた・拡張余地のある値空間(エンティティクエリの`status()`、`whereTerm()`の`$field`/`$operator`等)は文字列のまま据え置く(開いた値空間へのenum強制は柔軟性を損なう)。
- Model層`QueryBuilder`の`where()`の`$operator`は、`<>`を含みIN/BETWEEN等を専用メソッド(`whereIn`/`whereBetween`)が担う独自の小集合のため、`Compare`を適用せず文字列許可リスト(振る舞い仕様参照)のまま据え置く。
- `string|SortOrder`等のunion両対応は契約が二重になるため採らない。
- limit/offset等の数値バリデーション(`InvalidQueryException`、エンティティクエリ側は`QueryException`)はenum化の対象外であり存置する。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use TMSWP\Core\Query\SortOrder;

final class QueryBuilder
{
    /** @var list<string> SELECT句断片(検証済み識別子またはraw) */
    private array $selects = [];

    /** @var list<mixed> SELECT句bindings */
    private array $selectBindings = [];

    private ?string $fromAlias = null;

    /** @var list<string> JOIN句断片(完成形) */
    private array $joins = [];

    /** @var list<mixed> JOIN句bindings */
    private array $joinBindings = [];

    /** @var list<array{0: 'AND'|'OR', 1: string}> WHERE断片(プレースホルダ込み) */
    private array $wheres = [];

    /** @var list<mixed> WHERE句bindings */
    private array $whereBindings = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<string> "{column} ASC|DESC" */
    private array $orders = [];

    private ?int $limitValue = null;

    private ?int $offsetValue = null;

    /** @var class-string|null */
    private ?string $rowClass;

    /**
     * @param string $table ベアテーブル名
     * @param class-string|null $rowClass 既定ハイドレーション先(Model::rowClass()由来)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        ?string $rowClass = null,
    ) {}

    // ---- FROM ----

    /** FROM句にエイリアスを付ける(自己結合・修飾用): FROM {full} AS {alias} */
    public function alias(string $alias): self;

    // ---- SELECT ----

    /** カラム指定。各要素は修飾識別子または '{識別子} AS {別名}'。デフォルトは '*' */
    public function select(string ...$columns): self;

    /**
     * 集計等の式。R2規約適用(クォート禁止+プレースホルダ数検証+literal-string静的検査)。
     * @param literal-string $expression
     */
    public function selectRaw(string $expression, array $bindings = []): self;

    // ---- WHERE ----

    /**
     * 3形態:
     *   where($column, $value)            … '=' 比較(値nullはIS NULL)
     *   where($column, $operator, $value) … 演算子指定
     *   where(Closure $group)             … 括弧グループ(ClosureはQueryBuilderを受け取る)
     * 許可演算子: = != <> > >= < <= LIKE NOT LIKE(大文字小文字不問、内部で正規化)
     */
    public function where(string|\Closure $column, mixed $operator = null, mixed $value = null): self;

    /** whereと同形態。直前条件にORで連結 */
    public function orWhere(string|\Closure $column, mixed $operator = null, mixed $value = null): self;

    /** IN句。$valuesの各要素はR3写像対象(null・配列が含まれたらInvalidQueryException) */
    public function whereIn(string $column, array $values): self;

    public function whereNotIn(string $column, array $values): self;

    public function whereNull(string $column): self;

    public function whereNotNull(string $column): self;

    public function whereBetween(string $column, int|float|string|\DateTimeInterface $min, int|float|string|\DateTimeInterface $max): self;

    /** LIKE検索。パターン合成にはConnection::escLike()を使う(利用例参照) */
    public function whereLike(string $column, string $pattern): self;

    /**
     * R2規約適用のrawエスケープハッチ。
     * @param literal-string $sql
     */
    public function whereRaw(string $sql, array $bindings = []): self;

    /** @param literal-string $sql */
    public function orWhereRaw(string $sql, array $bindings = []): self;

    // ---- JOIN(最小限) ----

    /**
     * 単純等値結合。$tableは「ベア名」または「ベア名 AS 別名」。
     * $prefix: trueなら$tableにサイトprefixを付加(WPコアテーブル・他Modelのテーブルを
     * ベア名で参照できる)。falseで完全名をそのまま使用。
     * $left/$rightは修飾識別子、$operatorは = != <> > >= < <= のみ。
     */
    public function innerJoin(string $table, string $left, string $operator, string $right, bool $prefix = true): self;

    public function leftJoin(string $table, string $left, string $operator, string $right, bool $prefix = true): self;

    /**
     * 複合ON条件用(LEFT JOINでは ON への条件追加とWHERE追加は意味が異なるため必須)。
     * $onはR2規約適用。コンパイル時定数の識別子は直接書き、値は%s/%d/%fプレースホルダ、動的識別子は%iプレースホルダで書く(R2規約4)。
     * @param literal-string $on
     */
    public function innerJoinRaw(string $table, string $on, array $bindings = [], bool $prefix = true): self;

    /** @param literal-string $on */
    public function leftJoinRaw(string $table, string $on, array $bindings = [], bool $prefix = true): self;

    // ---- GROUP / ORDER / LIMIT ----

    public function groupBy(string ...$columns): self;

    /** 並び順はSortOrder enum(クエリ語彙enum参照)。句構築時に->valueでSQL文字列へ変換 */
    public function orderBy(string $column, SortOrder $direction = SortOrder::Asc): self;

    public function orderByDesc(string $column): self;

    /** 負数はInvalidQueryException */
    public function limit(int $limit): self;

    /** 負数はInvalidQueryException。limit()なしのままビルドするとInvalidQueryException */
    public function offset(int $offset): self;

    // ---- ハイドレーション ----

    /**
     * 結果行のDTOクラスを指定(Model::rowClass()より優先)。
     * @template T of object
     * @param class-string<T> $class
     */
    public function as(string $class): self;

    // ---- 終端(実行) ----

    /** @return list<object> rowClass未指定時はstdClass */
    public function get(): array;

    public function first(): ?object;

    /** 先頭行の指定カラム値。結果なしはnull。$columnは修飾識別子または別名 */
    public function value(string $column): mixed;

    /**
     * 単一カラムの値リスト。$keyColumn指定時はそれをキーにした連想配列
     * (キー重複時は後勝ち)。
     * @return array<int|string, mixed>
     */
    public function pluck(string $column, ?string $keyColumn = null): array;

    // value()/pluck()のカラム照合: 結果行のキー(=SELECT句の別名または非修飾カラム名)と
    // 照合する。修飾識別子(t.col)が渡された場合はドット以降のカラム名で照合する。
    // JOINで同名カラムが衝突する場合はselect()で別名を明示すること(rules/に記載)。

    /** COUNT(*)。select/orderBy/limit/offsetは無視。groupBy指定時はサブクエリで件数化 */
    public function count(): int;

    /** SELECT 1 ... LIMIT 1 の存在判定 */
    public function exists(): bool;

    /**
     * UPDATE実行。$valuesはカラム=>値(R3写像)。
     * 制約: JOINとの併用不可・WHERE必須(違反はInvalidQueryException)。影響行数を返す。
     */
    public function update(array $values): int;

    /** DELETE実行。制約はupdate()と同一。影響行数を返す */
    public function delete(): int;

    /** prepare適用後の最終SQL(デバッグ・ログ用)。終端メソッドと同じ組み立てを行うが実行しない */
    public function toSql(): string;
}
```

#### 振る舞い仕様

**where()の引数解釈**(`func_num_args()`で判定):

- 引数1個: `Closure`ならグループ、`string`なら`InvalidQueryException`(「`whereNull()`または値を渡せ」とメッセージで誘導)。
- 引数2個: `where($column, $value)`。`$value === null`なら`{col} IS NULL`にコンパイル。それ以外はR3写像で`{col} = {placeholder}`。
- 引数3個: 演算子を大文字正規化し許可リストと照合。`=`+null→`IS NULL`、`!=`/`<>`+null→`IS NOT NULL`。それ以外の演算子+null、および値が配列の場合は`InvalidQueryException`。

**グループ(Closure)**: 子の`QueryBuilder`(同一Connection・同一テーブル)を生成してClosureに渡す。コンパイル時、子のwhere断片を`( ... )`で括って親に連結し、子のbindingsを親に連結する。子でwhere系以外(`select`/`join`/`orderBy`/`limit`等)が呼ばれていた場合は親のビルド時に`InvalidQueryException`。これが旧`__OR__`/`__AND__`再帰ネストの後継である。

**条件はメソッド呼び出し時に即コンパイル**する(`['AND', 'email = %s']` + bindings `['a@b.c']`の形で蓄積)。遅延コンパイルは行わない。仕様が単純になり、`toSql()`が常に現状態を反映する。

**whereInの空配列**: `whereIn([], ...)` → 恒偽条件`0 = 1`、`whereNotIn([], ...)` → 恒真条件`1 = 1`にコンパイル(実行時SQLエラーを防ぐ)。

**SELECT文の組み立て手順**(`get()`/`first()`/`value()`/`pluck()`/`exists()`/`count()`共通):

1. SELECT句: `$selects`が空なら`*`。
2. FROM句: `{$connection->tableName($table)}`+(alias指定時)` AS {alias}`。
3. JOIN句: 蓄積順に連結。
4. WHERE句: 先頭断片の論理演算子を捨て、以降を` AND `/` OR `で連結。
5. GROUP BY、ORDER BY、`LIMIT %d`、`OFFSET %d`(limit/offsetの値もbindingsに積む)。
6. bindingsの最終順序は **SELECT句 → JOIN句 → WHERE句 → LIMIT/OFFSET**(SQL内の出現順と一致させる)。
7. `Connection::select($sql, $bindings)`等で実行。

**first()**: `limit(1)`を適用(既存のlimit指定は1で上書き)して`get()`し先頭を返す。

**count()**: ビルダを複製し、select/orderBy/limit/offsetを破棄。`$groups`が空なら`SELECT COUNT(*)`に、非空なら`SELECT COUNT(*) FROM (SELECT 1 {from以降 group byまで}) AS tmswp_count`にコンパイルし、`(int) Connection::selectVar()`を返す。

**update()/delete()の組み立て**: `UPDATE {full} SET {col} = {ph}, ... WHERE ...` / `DELETE FROM {full} WHERE ...`。bindingsはSET句→WHERE句の順。alias・JOIN・groupBy・orderBy・limitが設定されていたら`InvalidQueryException`(最小限仕様)。「本当に全行を対象にしたい」場合のみ`whereRaw('1 = 1')`を書く規約とし、rules/に記載する。

**ハイドレーション**: 実行結果(連想配列リスト)を行クラスへ変換する。

- `rowClass`がnull → `(object) $row`(stdClass)。**wpdbの仕様により全カラム値はstringまたはnullのまま**である点をPHPDocとrules/に明記する。
- `rowClass`が指定されている → 後述「DTOハイドレーション規則」で変換。
- `value()`/`pluck()`/`count()`/`exists()`はハイドレーションを行わない(生値)。

**DTOハイドレーション規則(確定)**:

1. 対象クラスのコンストラクタをReflectionで取得。コンストラクタが存在しpublicでない場合は`HydrationException`。コンストラクタなしのクラスは全カラムを動的プロパティ代入せず`HydrationException`(constructor promotion前提の設計)。
2. 各コンストラクタ引数について行カラムを解決する: (a) 引数名と完全一致するカラム、なければ (b) snake_case変換名(`preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $name)`を小文字化。例: `createdAt`→`created_at`)。
3. どちらにも該当なし: デフォルト値があればそれを使用、なければ`HydrationException`(引数名・クラス名・利用可能カラム一覧をメッセージに含める)。
4. 型強制(宣言型ベース。DB値はstring|nullで来る前提):
   - `int`/`float`/`string` → 各キャスト。
   - `bool` → `(bool)(int)` 変換(`'0'`→false、`'1'`→true)。
   - `\DateTimeImmutable`または`\DateTimeInterface` → `\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, wp_timezone())` の厳密書式パース。書式はdatetime/timestampカラムへの書き込み書式`'Y-m-d H:i:s'`(R3参照)と整合させ、先頭の`!`で未指定フィールド(マイクロ秒)を0に確定させる。戻り値が`false`、または`\DateTimeImmutable::getLastErrors()`が配列を返し`warning_count`/`error_count`のいずれかが0でない場合は`HydrationException`(PHP 8.2+ではエラー・警告なしのとき`getLastErrors()`は`false`を返す)。`new \DateTimeImmutable($value)`による相対書式(`'tomorrow'`・`'+1 day'`等)・`'@unixtime'`・任意文字列の暗黙解釈は行わない。
   - `\BackedEnum`サブクラス → backing typeにキャストして`::from()`。`ValueError`は`HydrationException`に変換。
   - `array` → `json_decode($value, true, 512, JSON_THROW_ON_ERROR)`。失敗は`HydrationException`(`json()`カラム用)。
   - nullable型(`?T`)+DB値null → null。非nullable型+null → `HydrationException`。
   - `mixed`/型宣言なし → 生値のまま。
   - 上記以外のユニオン型・intersection型・オブジェクト型 → `HydrationException`。
5. 行に存在するがコンストラクタ引数にないカラムは無視する。
6. `new $class(...$args)`で生成(named argumentsではなく解決済み位置引数)。

PHPStan向けに`as()`/`get()`/`first()`へ`@template T of object` / `@param class-string<T>` / `@return list<T>` / `@return T|null`のジェネリクスdocblockを付与する(`as()`は`self`を返すため完全な型伝搬はFW同梱のPHPStan拡張(DR-05のMetaBag拡張と同梱)で`as()`呼び出し以降の`get()`戻り値をナローイングする)。

#### エッジケース

- `offset()`のみ指定してビルド → `InvalidQueryException`(旧FWはMySQL構文エラーになるSQLを生成していた。設計根拠参照)。
- `ORDER BY RAND()`等の方向指定は非対応と確定する。`$direction`は`SortOrder` enumのため、不正な方向指定は型レベルで表現不能(静的解析・IDEが検出)。乱択は`pluck`後にPHP側で行う。
- 同一ビルダで終端メソッドを複数回呼ぶことは許可(ビルダ状態は終端呼び出しで変化しない。`first()`のlimit上書きはクローン上で行う)。

---

### 5. `TMSWP\Core\Model\Migrator`

#### 役割

登録された全Modelのスキーマを「ハッシュ比較 → 差分時のみdbDelta」で同期する。実行契機の配線(フック/CLI)もここが持つ。旧FWの「毎リクエストinitフックで実行」「optionキーがprefixなしで衝突」「autoload無制御」を解消する。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

final class Migrator
{
    /** 保存先option名。値は array<string フルテーブル名, string sha256>。autoload=false固定 */
    public const OPTION_KEY = 'tmswp_schema_hashes';

    /** @var list<class-string<Model>> */
    private array $models = [];

    /** @var list<self> listen()済みインスタンスのプロセス内レジストリ(CLIコマンドが横断利用) */
    private static array $instances = [];

    private static bool $cliRegistered = false;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * モデル登録。Kernelが#[AsModel]発見時に呼ぶ。
     * Modelを継承していないクラスはSchemaException。
     * 同一クラスの二重登録は無視(冪等)。別クラスが同名テーブルを宣言した場合はSchemaException。
     * @param class-string<Model> $modelClass
     */
    public function register(string $modelClass): void;

    /** @return list<class-string<Model>> 登録順 */
    public function registeredModels(): array;

    /**
     * 全登録モデルを移行。戻り値は フルテーブル名 => dbDeltaを実行したか。
     * @return array<string, bool>
     */
    public function migrateAll(bool $force = false): array;

    /** 単一モデルの移行。dbDeltaを実行したらtrue @param class-string<Model> $modelClass */
    public function migrate(string $modelClass, bool $force = false): bool;

    /**
     * 差分状況の取得(dry-run)。dbDeltaは実行しない。
     * @return array<string, bool> フルテーブル名 => 保存済みハッシュと相違があるか
     */
    public function status(): array;

    /**
     * DROP TABLE IF EXISTS + 保存ハッシュ削除。uninstall.php用。
     * @param class-string<Model> $modelClass
     */
    public function dropTable(string $modelClass): void;

    /**
     * 実行契機の配線。Kernelがboot時に1回呼ぶ(Kernel統合契約参照)。
     * @param bool $isTheme テーマ文脈ならtrue
     * @param string|null $pluginFile プラグイン文脈なら本体ファイルの絶対パス
     */
    public function listen(bool $isTheme, ?string $pluginFile = null): void;
}
```

#### migrate() の手順(確定)

```
1. $definition = $modelClass::table()
2. $sql  = $definition->toSql($this->connection)
3. $hash = hash('sha256', $sql)
4. $stored = get_option(self::OPTION_KEY, [])[フルテーブル名] ?? null
5. if (!$force && $stored === $hash) return false   // スキップ(差分なし)
6. require_once ABSPATH . 'wp-admin/includes/upgrade.php'
7. dbDelta($sql)                                     // 実行
8. 検証1: $this->connection->lastError() が非空 → MigrationException
   (ハッシュは更新しない=次回契機で自動リトライされる)
9. 検証2: dbDelta($sql, false) (dry-run)の戻り配列が非空 →
   MigrationException(メッセージに残差分クエリを列挙)。
   本FWのtoSql()生成規則はdbDeltaの比較器と整合するため、正常時の残差分は空になる
10. option配列を更新して update_option(..., autoload: false)。return true
```

- dbDeltaは複数クエリを発行し`$wpdb->last_error`は最後のクエリしか反映しないため、検証2(apply後dry-run)を必須とする。
- dbDeltaの既知の制約 — **カラム削除・カラム名変更・型縮小のデータ変換は行われない** — はそのまま引き継ぐ。不要カラムはDBに残置される(削除が必要な運用は利用側が`Connection::execute()`で明示ALTERを書く)。この制約はrules/とPHPDocに明記する。
- 同時実行(管理画面への並行アクセス等)でdbDeltaが二重に走っても、dbDeltaは差分適用型なので冪等であり整合性は壊れない。ロックは設けない。

#### listen() の配線(確定)

```
1. self::$instances[] = $this
2. $pluginFile !== null の場合:
     register_activation_hook($pluginFile, fn() => $this->migrateAll(force: true))
3. $isTheme の場合:
     add_action('after_switch_theme', fn() => $this->migrateAll(force: true))
4. 共通(版数比較=ハッシュ比較。管理画面のみ・フロントでは一切動かない。
   MigrationExceptionは非致死に捕捉する — 下記「admin_init契機のエラー処理」参照):
     add_action('admin_init', function (): void {
         try {
             $this->migrateAll();
         } catch (MigrationException $e) {
             if ($debug) {   // このMigratorを起動したKernelのKernelConfig::$debug(00-overview.md §5.6)
                 wp_trigger_error(self::class . '::migrateAll', $e->getMessage(), E_USER_WARNING);
             }
             add_action('admin_notices', static fn() => wp_admin_notice(
                 'TMSWP: テーブル移行に失敗しました — ' . esc_html($e->getMessage()),
                 ['type' => 'error'],
             ));
         }
     }, 5)
5. defined('WP_CLI') && WP_CLI かつ self::$cliRegistered === false の場合:
     \WP_CLI::add_command('tmswp migrate', new MigrateCommand())
     self::$cliRegistered = true
   ※コマンドはself::$instancesを横断するため、テーマ+複数プラグインが
     それぞれKernelをbootしていても 'wp tmswp migrate' 1コマンドで全テーブルが対象になる
```

- `admin_init`での`migrateAll()`は差分なし時「モデル数ぶんの文字列構築+`get_option` 1回」のみで、実DBクエリはoption読込(autoload対象外のため1クエリ)に限られる。フロントエンドのリクエストではフック自体が発火しない。
- 有効化フック/テーマ切替は`force: true`で必ずdbDeltaを通す(再有効化時にテーブルが手動DROPされていても復旧できるようにするため)。
- **admin_init契機のエラー処理(非致死)**: WPフックコールバック内の未捕捉例外はリクエストを致死させ、失敗テーブルのハッシュは未更新のため毎`admin_init`で再fatal=管理画面全体の恒久ロックアウトに転化する。これを防ぐため、`admin_init`契機に限り`MigrationException`を捕捉し、(a) `wp_admin_notice()`(WP 6.4+、`type: error`。01-architecture.mdの正準API棚卸しどおり)で管理者へ**無条件**に通知、(b) このMigratorを起動したKernelの`KernelConfig::$debug`がtrueのときはさらに`wp_trigger_error(..., E_USER_WARNING)`でログへ可視化する(debugの判定は00-overview.md §5.6のとおりKernelConfig::$debugに一本化する)。捕捉してもハッシュは未更新のままなので、原因解消後の次回`admin_init`で自動的に再試行・回復する(migrate()手順8の「次回契機で自動リトライ」の設計意図と整合)。
- 有効化フック・`after_switch_theme`・WP-CLIの各契機は現行どおり例外を伝播させる(失敗を即座に顕在化すべき文脈)。契機ごとにエラー方針が2種(admin_init=非致死捕捉/それ以外=伝播)となる旨はrules/に1行記載する。

#### エッジケース

- `migrateAll()`内の1モデルで`MigrationException`が発生したら即座に伝播し、後続モデルは処理しない(fail fast。部分適用済みテーブルのハッシュは更新済み、失敗テーブルは未更新なので再実行で収束する)。`admin_init`契機ではこの伝播例外をlisten()手順4のtry/catchが非致死に捕捉する(他契機は伝播のまま)。
- 旧iwf2bのoptionキー`{table_name}_schema_hash`は読み取らない(DR-03: 互換なし)。

---

### 6. `TMSWP\Core\Model\MigrateCommand`

#### 役割

WP-CLIコマンド`wp tmswp migrate`の実装。`Migrator`のプロセス内レジストリを横断して全テーブルを移行する。

#### シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

/**
 * 独自テーブルのマイグレーションを実行する。
 *
 * ## OPTIONS
 *
 * [--force]
 * : ハッシュ比較をスキップして全テーブルにdbDeltaを実行する
 *
 * [--dry-run]
 * : 実行せず、差分のあるテーブル一覧のみ表示する
 *
 * ## EXAMPLES
 *
 *     wp tmswp migrate
 *     wp tmswp migrate --force
 *     wp tmswp migrate --dry-run
 */
final class MigrateCommand
{
    /**
     * @param list<string> $args 位置引数(未使用)
     * @param array<string, mixed> $assocArgs --force / --dry-run
     */
    public function __invoke(array $args, array $assocArgs): void;
}
```

#### 振る舞い仕様

1. `--dry-run`: 各Migratorの`status()`を集約し、`WP_CLI::log()`で「`{テーブル名}: changed|up-to-date`」を1行ずつ出力して終了(終了コード0)。
2. 通常/`--force`: 各Migratorの`migrateAll($force)`を呼び、適用テーブルは「`Migrated: {テーブル名}`」、スキップは「`Skipped: {テーブル名} (up to date)`」を出力。最後に`WP_CLI::success('{n} table(s) migrated, {m} skipped.')`。
3. `MigrationException`発生時は`WP_CLI::error($e->getMessage())`(非0終了)。
4. 登録Migratorが0件(テーマ・プラグインのどのKernelもModelを持たない)の場合は`WP_CLI::warning('No models registered.')`で正常終了。

---

### 7. Kernel統合契約(`#[AsModel]`)

本文書はKernel/Discoveryの内部仕様を定義しないが、Model層が機能するためにKernel側が満たすべき契約を以下に固定する(Kernel設計書はこれを実装する)。

1. Containerに`Connection::class`(デフォルトファクトリ`Connection::fromGlobal()`、共有インスタンス)と`Migrator::class`(共有インスタンス)を束縛する。
2. Discoveryが`#[AsModel]`属性を持つクラスを発見したら、`Migrator::register($class)`を呼ぶ。属性クラス`TMSWP\Core\Attribute\AsModel`は引数なし(`#[Attribute(Attribute::TARGET_CLASS)]`)。
3. boot完了時(DR-04のWPフック整合タイミング)に`Migrator::listen($isTheme, $pluginFile)`を1回呼ぶ。`$isTheme`/`$pluginFile`は`KernelConfig::theme()`/`KernelConfig::plugin()`の文脈から導出する。
4. `#[AsModel]`クラスは`Model`のサブクラスであること。違反は`Migrator::register()`が`SchemaException`で報告する(Discoveryの探索失敗エラー方針と整合)。

Modelクラス自体は登録以外の初期化を必要としない(静的ファサードのため、利用時に`connection()`が遅延解決する)。

---

## 利用例(app側コード)

### 例1: 正準形 — 宣言とCRUD

```php
<?php
// app/Model/InquiryModel.php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class InquiryModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('inquiries')
            ->id()
            ->string('email', 191)
            ->text('body')
            ->datetime('created_at');
        // 検索頻度が高いカラムには ->index('email') を追加するとよい
    }
}
```

```php
$id = InquiryModel::insert([
    'email'      => $email,                  // %s
    'body'       => $body,                   // %s
    'created_at' => new \DateTimeImmutable('now', wp_timezone()), // %s 'Y-m-d H:i:s'
]);

$rows = InquiryModel::query()
    ->where('email', $email)
    ->orderByDesc('id')
    ->limit(10)
    ->get();                                  // list<stdClass>

$found   = InquiryModel::find($id);           // ?stdClass
$total   = InquiryModel::count(['email' => $email]);
$updated = InquiryModel::update(['body' => $newBody], $id);   // 主キー指定
$deleted = InquiryModel::delete(['email' => $email]);          // 等値条件
```

### 例2: キューテーブル(旧T16 AbstractQueueModelの置換)

旧FWでは`find_one_by(['completed' => 0], ['orderby' => ['datetime ASC']])`(生SQL文字列のorderby)や、`clear()`での文字列連結生SQL`"DELETE FROM {$table} WHERE class = '...'"`が必要だった。新ビルダでは全てprepare経由で書ける。

```php
<?php
// app/Model/QueueModel.php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class QueueModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('queue')
            ->id()
            ->string('object_type', 40, nullable: true)
            ->int('object_id', nullable: true, unsigned: true)
            ->text('url')
            ->text('output_path', nullable: true)
            ->string('handler', 100)
            ->datetime('queued_at')
            ->bool('completed')
            ->index(['handler', 'completed']);
    }

    /** 次に処理すべきキューを1件取得 */
    public static function nextPending(string $handler): ?object
    {
        return static::query()
            ->where('handler', $handler)
            ->where('completed', false)
            ->orderBy('queued_at')      // ASC
            ->first();
    }

    /** 処理済みフラグ更新(旧 set_completed) */
    public static function markCompleted(int $id): int
    {
        return static::update(['completed' => true], $id);
    }

    /** ハンドラ単位の全消去(旧 clear() の生SQL DELETE を置換) */
    public static function clearHandler(string $handler): int
    {
        return static::query()->where('handler', $handler)->delete();
        // 生成SQL: DELETE FROM wp_queue WHERE handler = %s
    }

    /** 進捗率(旧 get_progress_percentage) */
    public static function progressPercentage(string $handler): int
    {
        $total = static::count(['handler' => $handler]);
        $done  = static::count(['handler' => $handler, 'completed' => 1]);

        return $total > 0 ? (int) (round($done / $total, 2) * 100) : 100;
    }
}
```

### 例3: 翻訳リレーション — 自己結合JOINと集計(旧T18の生SQLフォールバックの置換)

旧FWはJOIN手段がなく、`T18/app/ACF.php`が`terms_clauses`フィルタ内で`LEFT JOIN {$relation_table} AS trl ON ... AND trl.object_type = 'term'`という**エスケープなし生JOIN SQLを手書き**し、`TranslationGroupModel::create()`は空行INSERTのために`self::db()->query('INSERT INTO ... () VALUES ();')`へフォールバックしていた。新APIでの等価実装:

```php
<?php
// app/Model/TranslationGroupModel.php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class TranslationGroupModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('translation_group')->id();
    }

    /** AUTO_INCREMENT採番のみの空行INSERT(旧: 生SQLフォールバックが必要だった) */
    public static function create(): int
    {
        return static::insert([]);   // INSERT INTO wp_translation_group () VALUES ()
    }
}
```

```php
<?php
// app/Model/TranslationRelationModel.php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class TranslationRelationModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('translation_relation')
            ->id()
            ->foreignId('group_id')
            ->bigint('object_id', unsigned: true)
            ->string('object_type', 20)
            ->string('language_code', 10)
            ->index(['object_id', 'object_type', 'language_code']);
    }

    /**
     * ある投稿の翻訳先object_idを取得(自己結合。旧FWでは表現不可能だった)
     */
    public static function translatedObjectId(int $objectId, string $objectType, string $language): ?int
    {
        // 自己結合で両テーブルにobject_idが存在するため、select()で取得カラムを明示する
        $value = static::query()
            ->alias('src')
            ->select('dst.object_id')
            ->innerJoinRaw(
                'translation_relation AS dst',
                'dst.group_id = src.group_id AND dst.language_code = %s',
                [$language],
            )
            ->where('src.object_id', $objectId)
            ->where('src.object_type', $objectType)
            ->value('object_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * WPコアテーブルとのLEFT JOIN(旧ACF.phpのterms_clauses手書きSQLの置換)。
     * 未翻訳タームも含めて取得する例。ON句への条件追加とWHEREの違いをleftJoinRawで表現。
     */
    public static function termIdsForLanguage(string $language): array
    {
        return static::query()
            ->alias('trl')
            ->leftJoinRaw(
                'terms AS t',                        // prefix=true → wp_terms
                't.term_id = trl.object_id AND trl.object_type = %s',
                ['term'],
            )
            ->where(function ($q) use ($language): void {
                $q->where('trl.language_code', $language)
                  ->orWhere('trl.language_code', null);   // IS NULL
            })
            ->pluck('object_id');
    }

    /** 言語別の登録件数(集計。旧FWではfind_byで表現不可) */
    public static function countsByLanguage(): array
    {
        return static::query()
            ->selectRaw('language_code, COUNT(*) AS total')
            ->groupBy('language_code')
            ->pluck('total', 'language_code');   // ['ja' => '120', 'en' => '34']
    }
}
```

### 例4: DTOハイドレーション

```php
<?php
// app/Model/Row/InquiryRow.php

declare(strict_types=1);

namespace App\Model\Row;

final class InquiryRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $body,
        public readonly \DateTimeImmutable $createdAt,   // カラム created_at に自動対応
    ) {}
}
```

```php
// その場で指定
$rows = InquiryModel::query()
    ->as(\App\Model\Row\InquiryRow::class)
    ->orderByDesc('id')
    ->limit(10)
    ->get();   // list<InquiryRow>(id:int / createdAt:DateTimeImmutable に型強制済み)

// モデル既定にする場合は rowClass() をオーバーライド
// public static function rowClass(): ?string { return InquiryRow::class; }
// → InquiryModel::find($id) も InquiryRow を返す
```

### 例5: LIKE検索とテストでの差し替え

```php
// LIKE: パターン合成は escLike を使う(% や _ を含むユーザー入力の誤解釈防止)
$conn = InquiryModel::connection();
$rows = InquiryModel::query()
    ->whereLike('email', '%' . $conn->escLike($keyword) . '%')
    ->get();

// ユニットテスト(WP非依存): wpdbフェイクを注入
$fake = new \Tests\Support\FakeWpdb();             // \wpdb互換のテストダブル
\TMSWP\Core\Model\Model::useConnection(new Connection($fake));
// ... テスト実行 ...
\TMSWP\Core\Model\Model::useConnection(null);    // tearDownで解除
```

### 例6: アンインストール時のテーブル削除(プラグイン)

```php
<?php
// uninstall.php(プラグインルート)

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

$migrator = new \TMSWP\Core\Model\Migrator(\TMSWP\Core\Model\Connection::fromGlobal());
$migrator->dropTable(\App\Model\InquiryModel::class);
```

---

## 旧iwf2bからの設計根拠(簡潔に)

| 旧FWの問題 | 本設計での解決 |
|---|---|
| **SQLインジェクション**: `make_conditions()`(AbstractModel.php L337-382)が`$wpdb->prepare`を一切使わず`"{$key} = '{$value}'"`と直接補間(L364-377)。IN句・join・orderby・fieldsも未検証の生文字列 | 全クエリを`Connection::prepare`経由に強制。識別子はR1検証、値はR3写像、raw断片はR2でクォート文字禁止+プレースホルダ数検証+literal-string静的検査(規約4)。エスケープなし補間のコードパスが存在しない |
| `find_by()`のlimit/offsetが整数キャストなしで直接補間(L242-247)。offset単独指定で不正SQL生成 | limit/offsetは`int`型宣言+`%d`バインド。offset単独はビルド時`InvalidQueryException` |
| `__callStatic`によるwpdb透過プロキシ(L97-114)。IDE補完・静的解析・AI支援が効かない | マジックメソッド全面禁止(共通規約)。明示APIのみ。緊急避難は`Connection::wpdb()`に一本化 |
| 毎リクエスト`init`フックでmigrate実行。有効化フック・アンインストール機構なし(T03指摘) | 契機を有効化フック/`after_switch_theme`/`admin_init`(管理画面のみ・ハッシュ比較)/WP-CLIに限定。`dropTable()`でuninstall対応 |
| optionキー`{table_name}_schema_hash`がprefixなしで衝突リスク、autoload無制御 | 単一option `tmswp_schema_hashes`(autoload=false)に集約 |
| `$sql`文字列形式の脆い行パース、dbDeltaフォーマット要件がユーザー任せ | fluent宣言から決定的に生成し、dbDelta要件(1行1定義・`PRIMARY KEY  `2スペース・`KEY`+名前必須・小文字型名等)を生成側で全充足 |
| 表現力不足で生SQLフォールバック多発: T18の空行INSERT(`TranslationGroupModel::create()`)・手書きLEFT JOIN(`ACF.php` terms_clauses)、T16の文字列連結DELETE(`AbstractQueueModel::clear()` L303) | `insert([])`の空行INSERT対応、`innerJoin/leftJoin(+Raw)`、ビルダレベル`update()/delete()`、`selectRaw+groupBy`集計で全事例をprepare経由で表現可能(利用例2・3で実証) |
| `AbstractSingleton`継承でDI不可・モック不可、Modelのテストが皆無 | シングルトン廃止。`Connection`注入+`Model::useConnection()`テストシームでWP非依存ユニットテストが可能 |
| stdClass直返しのみで型情報なし | stdClassを既定としつつ`as(Dto::class)`/`rowClass()`の任意DTOハイドレーション(コンストラクタ引数マッピング+型強制)を追加 |

旧APIの「テーブル名を書かない静的CRUD」「条件配列のIN句展開」「count等の戻り値形状別ショートカット」という筋肉記憶価値は、`Model::insert/update/delete/count`と`QueryBuilder`の対応メソッドとして継承した。旧`__OR__`/`__AND__`ネストはClosureグループに、整数キー生SQL断片は`whereRaw`(プレースホルダ強制)に置き換え、無検証の自由度は意図的に残していない。

---

## 受け入れ基準

### チェックリスト

**TableDefinition**
- [ ] 正準形(`create('inquiries')->id()->string('email', 191)->text('body')->datetime('created_at')`)の`toSql()`が、dbDelta要件(1行1定義 / `PRIMARY KEY  (id)`の2スペース / 小文字型名 / バッククォートなし)をすべて満たすCREATE TABLE文を生成する
- [ ] 同一宣言からの`toSql()`/`hash()`が常にバイト同一(複数回呼び出しで不変)
- [ ] `json()`が`longtext`を生成する(JSON型を出力しない)
- [ ] PK未定義・同名カラム重複・text系へのindex・64文字超のテーブル/インデックス名で`SchemaException`
- [ ] `foreignId('group_id')`がカラムと`KEY group_id (group_id)`の両方を生成し、FOREIGN KEY制約を生成しない

**QueryBuilder / Connection**
- [ ] すべての終端メソッドの発行SQLがbindings非空時に`wpdb->prepare`を通過する(統合テストで`query`フィルタを用いて検証)
- [ ] `where('email', "x' OR 1=1 --")`等の攻撃的入力がリテラルとして安全にバインドされる(統合テスト)
- [ ] `whereRaw("col = 'x'")`(クォート含有)、`whereRaw('col = %s', [])`(個数不一致)、`where('col', [1,2])`(配列値)、`offset()`単独、WHEREなし`update()/delete()`がいずれも`InvalidQueryException`
- [ ] raw断片引数(`whereRaw`/`orWhereRaw`の`$sql`、`selectRaw`の`$expression`、`innerJoinRaw`/`leftJoinRaw`の`$on`)が`@param literal-string`宣言を持ち、PHP変数を連結した断片(例: `->whereRaw("{$col} = %s", [$v])`)が`composer analyse`(PHPStan level max)でエラーになる(リテラル断片`->whereRaw('%i = %s', [...])`は通過する)
- [ ] `TMSWP\Core\Query\Compare`/`CastType`/`SortOrder`が正準定義どおりのcase・backing値を持ち(`Compare`はEXISTS/NOT EXISTSを含まない)、`orderBy()`が`SortOrder`を受け取って句構築時に`->value`でSQLへ変換する(文字列`'RAND()'`等の不正方向は型レベルで渡せない)
- [ ] `whereIn`空配列が恒偽、`whereNotIn`空配列が恒真にコンパイルされる
- [ ] Closureグループが`( a OR b )`の括弧付きSQLを生成し、bindings順序がSQL出現順と一致する
- [ ] `groupBy`+`count()`がサブクエリ件数を返す
- [ ] `as(Dto::class)`がint/bool/DateTimeImmutable/BackedEnum/array(json)の型強制とcamelCase→snake_case解決を行い、欠落カラム(デフォルトなし)で`HydrationException`
- [ ] DTOの`\DateTimeImmutable`変換が厳密書式パースであり、`'tomorrow'`(相対書式)・`'@0'`(Unixタイム)・`'2024-02-30 00:00:00'`(存在しない日付=parse warning)がいずれも`HydrationException`になる
- [ ] `get()`既定でstdClass(値はDB由来string|null)を返す
- [ ] `toSql()`が実行なしで最終SQLを返す
- [ ] `Model::useConnection()`+wpdbフェイクでWP非依存のユニットテストが書ける(Brain Monkey層のテストが存在する)

**Model / Migrator / CLI**
- [ ] `Model::insert([])`が`INSERT INTO {t} () VALUES ()`を発行しinsert_idを返す(統合テスト)
- [ ] 初回`migrateAll()`でテーブルが作成され、無変更の2回目はdbDeltaが実行されない(`status()`が全テーブルfalse)
- [ ] スキーマ宣言にカラム追加後の`migrateAll()`でALTERが適用され、ハッシュが更新される
- [ ] dbDelta後の残差分検出(検証2)が機能し、失敗時はハッシュ未更新のまま`MigrationException`が投げられる
- [ ] optionが`tmswp_schema_hashes`単一キー・autoload=falseで保存される
- [ ] プラグイン有効化フック/`after_switch_theme`/`admin_init`で移行が走り、フロントエンドのリクエストではマイグレーション関連処理が一切実行されない
- [ ] `admin_init`契機の`migrateAll()`で`MigrationException`が発生しても管理画面はfatalにならず、`wp_admin_notice()`(`type: error`)が無条件で表示され、Kernelのdebugがtrueのときは`wp_trigger_error`(E_USER_WARNING)も発火する。ハッシュは未更新のままで、原因解消後の次回`admin_init`で移行が自動再試行される(統合テスト)
- [ ] 有効化フック/`after_switch_theme`/WP-CLI契機では`MigrationException`が捕捉されず伝播する
- [ ] `wp tmswp migrate` / `--force` / `--dry-run`が仕様どおり動作する(wp-env統合テスト)
- [ ] 利用例2(キュー)・例3(自己結合JOIN/LEFT JOIN+集計)のコードが動作する統合テストが存在する(旧生SQLフォールバック事例の置換実証)

**品質ゲート**
- [ ] 本層の全クラスが`declare(strict_types=1)`・`final`(Model基底を除く)・全シグネチャ型宣言済みで、`__callStatic`等のマジックメソッドを含まない
- [ ] PHPStan level max(szepeviktor/phpstan-wordpress)をbaselineなしで通過
- [ ] ユニットテスト(WP非依存)がTableDefinition生成・QueryBuilderコンパイル・ハイドレーション・例外系を網羅する

### 検証コマンド

```bash
composer lint      # php-cs-fixer (PSR-12 + strict_types)
composer analyse   # PHPStan level max・baselineなし
composer test      # ユニット(Brain Monkey / wpdbフェイク)
composer wp:start && composer test:integration   # 統合(WordPress のテストライブラリ: dbDelta実機・prepare通過・WP-CLI)
composer qa        # 上記一括
```
