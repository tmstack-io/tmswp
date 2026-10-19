# 03 エンティティ定義層(PostType / Taxonomy / UserRole)

## 目的

WordPressの3種のエンティティ(投稿タイプ・タクソノミー・ユーザーロール)を「1エンティティ=1クラス」で宣言的に定義し、

1. `register_post_type` / `register_taxonomy` / `add_role` への決定的かつ冪等な自動登録
2. クラス既定スコープ付きのクエリビルダ(PostQuery / TermQuery / UserQuery)
3. WPテンプレートから直接echoできる静的ファサード(`permalink()` / `archiveUrl()` / `thumbnailUrl()` 等)

を提供する。旧iwf2bで最も利用された層(AbstractPost継承58クラス、AbstractTax継承42クラス、テンプレートからの静的呼び出し100箇所超)の後継であり、`__callStatic`・null/false不統一・多段文字列フォールバック等の旧問題を全廃した上で「app/に置くだけ」「テンプレートからワンライナー」の体験を維持する。

## スコープ(対象パッケージ)

`packages/core` (tmswp/core)。本文書が正準定義するクラス:

| 名前空間(`TMSWP\Core\` 配下) | クラス |
|---|---|
| `Post\` | `PostType`(基底), `PostTypeDefinition`, `PostQuery`, `Thumbnail` |
| `Tax\` | `Taxonomy`(基底), `TaxonomyDefinition`, `TermQuery` |
| `User\` | `UserRole`(基底), `UserRoleDefinition`, `UserQuery` |
| `Label\` | `LabelLanguage`(enum), `LabelGenerator` |
| `Query\` | `PageResult` |
| `Exception\` | `EntityDefinitionException`, `EntityPersistenceException`, `QueryException` |

`Label\`・`Query\`・`Thumbnail`・例外3種はCONTRACT未掲載の新設APIであり、本文書が完全定義する(他文書は本文書の定義に従う)。

## 依存文書

- **Kernel/Discovery設計書(02想定)**: `#[AsPostType]` / `#[AsTaxonomy]` / `#[AsUserRole]` 属性の定義と探索。本文書は「Kernelが各クラスの `register()` をいつ・どの順で呼ぶか」の契約(後述)のみを規定する。
- **meta/option設計書(04想定)**: `Meta\MetaBag` / `Meta\ObjectType` / `#[MetaSchema]`。本文書は各基底の `meta()` ファサードのシグネチャと委譲先のみを規定し、MetaBagの内部仕様には立ち入らない。
- **拡張パッケージ設計書(thumbnail)**: `tmswp/post/thumbnail_fallback` フィルタ(本文書が定義)に本文img抽出フォールバックを差し込む側。

---

## 1. 全体像と共通規約

### 1.1 定義クラスの基本形

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class ProductPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')
            ->label('製品')
            ->hasArchive();
    }
}
```

- 定義は `definition()` が返す Definition オブジェクトに集約する。旧iwf2bのstaticプロパティ宣言(`protected $post_type` / `$args`)の後継であり、型付き・IDE補完可能・PHPStan level max通過可能な形に置き換える。
- 属性(`#[AsPostType]` 等)はDiscoveryの探索マーカーであり、引数を持たない(全情報は `definition()` が持つ)。

### 1.2 Kernelとの登録契約

エンティティ層がKernel(02文書)に要求する契約。02文書はこれを満たすこと。

1. Kernelは、Discoveryが収集した各エンティティクラスの `register()`(`final public static`)を **WP `init` アクション**の中で、02文書の属性スケジュール表が定める優先度(**Taxonomy=9、PostType/UserRole=10**)で呼ぶ。`init` より前(`plugins_loaded` 等)での呼び出しは禁止。
2. 呼び出し順は **Taxonomy群 → PostType群 → UserRole群** とする。Taxonomy群の先行は優先度差(init 9 → 10)で保証され、同一優先度内のPostType群→UserRole群の順序はKernelのグループランナー登録順(02文書)で、同種内はFQCNの昇順(`strcmp`)で決定的にする。旧iwf2bで `$dependencies` によりTax→Postの順序を手動宣言していた実態(T04)を、FW側の固定順序で不要化する。
3. Kernelは収集リストの1巡走査により、各クラスの `register()` を**正確に1回**呼ぶ。`register()` 側は重複防止(再入ガード)を持たない(1.6節)。

### 1.3 get系戻り値の `?T` 統一(全エンティティ共通)

旧iwf2bは `AbstractPost::get()` がnull、`AbstractTax::get()` がfalseを返す不統一があった。本FWでは以下に統一する。

| 種別 | 規約 |
|---|---|
| 単体取得(`get()` / `findBy*()` / `first()` / `firstTerm()` / `current()`) | 見つからない・スコープ不一致なら **null**(`?WP_Post` / `?WP_Term` / `?WP_User` / `?Thumbnail`) |
| URL取得(`permalink()` / `archiveUrl()` / `url()` / `authorUrl()` / `thumbnailUrl()`) | 解決不能なら **null**(`?string`)。WP関数の `false` / `WP_Error` 戻りはすべてnullへ正規化 |
| 複数取得(`get()` / `ids()` / `terms()` / `parents()` / `all()`) | 常に **配列**(`list<T>`)。`WP_Error` は空配列へ正規化 |
| 判定(`is()` / `exists()`) | 常に **bool** |
| 書き込み(`create()`) | 成功時 **int**(ID)。失敗時は `EntityPersistenceException` をthrow(`WP_Error` は返さない) |

### 1.4 ID解決規約(多段文字列フォールバックの全廃)

旧iwf2bの `get()` は「数値→slug→タイトル検索」「email→login→slug」の多段マジック解決を行い、同名タイトルの取り違え事故源だった。本FWでは:

- `get()` の引数型は **エンティティオブジェクトまたはint ID のみ**。文字列は受けない。
- 文字列からの解決は明示メソッドに分解する: `findBySlug()` / `findByName()` / `findByEmail()` / `findByLogin()`。
- タイトルによる投稿検索は提供しない(完全廃止)。
- `get()` は対象のスコープ(post_type / taxonomy / role)を必ず検証し、不一致ならnullを返す。
- int引数が `<= 0` の場合はnull(例外にしない。`$post->post_parent`(0)等をそのまま渡せるようにするため)。

### 1.5 FQCN相互参照の解決規約

タクソノミーの `objectType()`、`PostQuery::whereTerm()`、`PostType::terms()` 等は、スラッグ文字列の代わりに **エンティティクラスのFQCN** を受け取れる(旧AbstractTaxの良設計を踏襲・拡張)。解決アルゴリズムは全箇所で同一とする:

```
入力: string $ref
1. class_exists($ref) が false → $ref をスラッグ文字列としてそのまま返す
2. class_exists($ref) が true:
   a. $ref が期待する基底(PostType または Taxonomy)のサブクラス → $ref::slug() を返す
   b. サブクラスでない → EntityDefinitionException をthrow
     (メッセージにはクラス名と期待基底名を含める)
```

実装はコア内部の私的ヘルパ(`@internal`)に集約してよい。解決はargs構築時(`toArgs()` / クエリ実行時)に遅延実行する。`definition()` 構築時点で相互参照先クラスのロードを強制しないこと(循環参照回避)。

### 1.6 メモ化・テスト用リセット

- `definition()` の結果は基底クラスがFQCNをキーにstatic配列でメモ化する。`definition()` 自体は純粋関数として実装する規約(WP関数を呼ばない。rules/に明記)。
- `register()` は重複防止(再入ガード)を持たない。「正確に1回」の保証は1.2節契約3のとおりKernel(収集リストの1巡走査)へ一本化する。なお下層WP API(`register_post_type` / `add_post_type_support` / `register_taxonomy_for_object_type`)は再実行しても冪等であり、UserRoleのDB書き込みは `get_role()` / `has_cap()` チェック(12.2節)で保護されるため、万一重複呼び出しが起きても実害はない。
- 各基底は `resetRuntimeCache(): void`(`@internal`、テスト専用)を持ち、呼び出したクラス(`static::class`)のメモ化定義を破棄する。旧AbstractSingletonの「グローバル状態がリセット不能でテスト不可」問題への回答。

### 1.7 例外

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Exception;

/** エンティティ定義の不正(スラッグ形式違反・builtin制約違反・登録失敗・FQCN解決失敗)。 */
class EntityDefinitionException extends \LogicException
{
}

/** エンティティ書き込み(wp_insert_post / wp_insert_term / wp_insert_user)の失敗。 */
class EntityPersistenceException extends \RuntimeException
{
    public readonly ?\WP_Error $wpError;

    public function __construct(string $message, ?\WP_Error $wpError = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->wpError = $wpError;
    }

    public static function fromWpError(\WP_Error $error): self
    {
        // メッセージは $error->get_error_message() を使用
        return new self($error->get_error_message(), $error);
    }
}

/** クエリビルダの使用方法不正(不正なdirection・compare・limit等)。 */
class QueryException extends \LogicException
{
}
```

DECISION: FW共通の基底例外(例: `TMSWPException`)を全体方針として導入する場合、上記3クラスはそれを継承する形に変更してよい(継承元の変更のみ可。クラス名・名前空間・プロパティは本文書が正準)。

---

## 2. Label\LabelLanguage / Label\LabelGenerator

### 2.1 LabelLanguage

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Label;

enum LabelLanguage: string
{
    case Ja = 'ja';
    case En = 'en';
}
```

### 2.2 LabelGenerator

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Label;

final class LabelGenerator
{
    /** @return array<string, string> register_post_type の labels 引数 */
    public static function forPostType(string $label, LabelLanguage $language): array;

    /** @return array<string, string> register_taxonomy の labels 引数 */
    public static function forTaxonomy(string $label, LabelLanguage $language): array;
}
```

**振る舞い仕様**:

- テンプレート文字列はFWに内蔵し、`sprintf` で `$label` を展開して返す。**gettext(`__()` 等)は通さない**(翻訳ファイル非依存・決定的。DR-17)。テキストドメイン `tmswp` はFWの他のUI文字列用であり、ラベル生成には使わない。
- デフォルト言語は `LabelLanguage::En`。日本語サイトでは定義側で `labelLanguage(LabelLanguage::Ja)` を明示する。

**投稿タイプ用テンプレート(全キー確定)**(`%s` = `$label`):

| キー | ja | en |
|---|---|---|
| name | `%s` | `%s` |
| singular_name | `%s` | `%s` |
| add_new | `新規追加` | `Add New` |
| add_new_item | `新規%sを追加` | `Add New %s` |
| edit_item | `%sを編集` | `Edit %s` |
| new_item | `新規%s` | `New %s` |
| view_item | `%sを表示` | `View %s` |
| view_items | `%s一覧を表示` | `View %s` |
| search_items | `%sを検索` | `Search %s` |
| not_found | `%sが見つかりませんでした` | `No %s found.` |
| not_found_in_trash | `ゴミ箱内に%sが見つかりませんでした` | `No %s found in Trash.` |
| parent_item_colon | `親%s:` | `Parent %s:` |
| all_items | `%s一覧` | `All %s` |
| archives | `%sアーカイブ` | `%s Archives` |
| attributes | `%sの属性` | `%s Attributes` |
| insert_into_item | `%sに挿入` | `Insert into %s` |
| uploaded_to_this_item | `この%sへのアップロード` | `Uploaded to this %s` |
| filter_items_list | `%s一覧を絞り込む` | `Filter %s list` |
| items_list_navigation | `%s一覧ナビゲーション` | `%s list navigation` |
| items_list | `%s一覧` | `%s list` |
| item_published | `%sを公開しました` | `%s published.` |
| item_updated | `%sを更新しました` | `%s updated.` |

**タクソノミー用テンプレート(全キー確定)**:

| キー | ja | en |
|---|---|---|
| name | `%s` | `%s` |
| singular_name | `%s` | `%s` |
| search_items | `%sを検索` | `Search %s` |
| popular_items | `よく使われている%s` | `Popular %s` |
| all_items | `%s一覧` | `All %s` |
| parent_item | `親%s` | `Parent %s` |
| parent_item_colon | `親%s:` | `Parent %s:` |
| edit_item | `%sを編集` | `Edit %s` |
| view_item | `%sを表示` | `View %s` |
| update_item | `%sを更新` | `Update %s` |
| add_new_item | `新規%sを追加` | `Add New %s` |
| new_item_name | `新規%s名` | `New %s Name` |
| separate_items_with_commas | `複数の%sはコンマで区切ってください` | `Separate %s with commas` |
| add_or_remove_items | `%sの追加または削除` | `Add or remove %s` |
| choose_from_most_used | `よく使われている%sから選択` | `Choose from the most used %s` |
| not_found | `%sが見つかりませんでした` | `No %s found.` |
| no_terms | `%sなし` | `No %s` |
| items_list_navigation | `%s一覧ナビゲーション` | `%s list navigation` |
| items_list | `%s一覧` | `%s list` |
| back_to_items | `← %s一覧へ戻る` | `← Back to %s` |

---

## 3. Query\PageResult

3クエリビルダ共通のページネーション結果。readonly・イテレーション可能なDTO。

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

/**
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final readonly class PageResult implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T> $items       現在ページのアイテム
     * @param int     $total       条件全体の総件数
     * @param int     $perPage     1ページあたり件数(>= 1)
     * @param int     $currentPage 現在ページ(1始まり)
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
    ) {
    }

    /** 総ページ数。total=0 のとき 0。 */
    public function totalPages(): int;     // (int) ceil($this->total / $this->perPage)

    public function hasPrev(): bool;       // $this->currentPage > 1
    public function hasNext(): bool;       // $this->currentPage < $this->totalPages()
    public function isEmpty(): bool;       // $this->items === []

    /** @return \ArrayIterator<int, T> */
    public function getIterator(): \ArrayIterator;

    /** 現在ページの件数(countではなくtotalに注意)。 */
    public function count(): int;          // count($this->items)
}
```

`totalPages()` は `paginate_links(['total' => $page->totalPages(), 'current' => $page->currentPage])` にそのまま渡せる値とする。

---

## 4. Post\PostTypeDefinition

### 4.1 役割

`register_post_type` に渡す全引数を型付きfluent APIで構築する値オブジェクト。組込投稿タイプ(post/page等)への「supports追加のみ」のアダプタモードも持つ。

### 4.2 シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Label\LabelGenerator;
use TMSWP\Core\Label\LabelLanguage;

final class PostTypeDefinition
{
    private string $label = '';

    /** @var array<string, string> labels()による部分上書き */
    private array $labelOverrides = [];

    private LabelLanguage $labelLanguage = LabelLanguage::En;

    /** @var array<string, mixed> セッターが構築する register_post_type 引数 */
    private array $args = [];

    /** @var list<string>|null nullは「supports未指定=WP既定に委ねる」 */
    private ?array $supports = null;

    /** @var list<string> 未解決(FQCN混在可)のタクソノミー参照 */
    private array $taxonomies = [];

    /** @var bool|null use_block_editor_for_post_type の希望値。nullはWP判定に委ねる */
    private ?bool $blockEditor = null;

    private function __construct(
        private readonly string $slug,
        private readonly bool $builtin,
    ) {
    }

    /** 新規投稿タイプの定義を開始する。 */
    public static function create(string $slug): self;

    /** 登録済み(組込含む)投稿タイプへのアダプタ定義を開始する。 */
    public static function builtin(string $slug): self;

    // ---- ラベル ----
    public function label(string $label): self;
    /** @param array<string, string> $labels 自動生成結果へのキー単位上書き */
    public function labels(array $labels): self;
    public function labelLanguage(LabelLanguage $language): self;

    // ---- 基本属性 ----
    public function description(string $description): self;
    public function public(bool $public = true): self;
    public function hierarchical(bool $hierarchical = true): self;
    public function excludeFromSearch(bool $exclude = true): self;
    public function publiclyQueryable(bool $queryable = true): self;

    // ---- 管理画面 ----
    public function showUi(bool $show = true): self;
    public function showInMenu(bool|string $show = true): self;
    public function showInNavMenus(bool $show = true): self;
    public function showInAdminBar(bool $show = true): self;
    public function menuPosition(int $position): self;
    public function menuIcon(string $icon): self;

    // ---- REST / ブロックエディタ ----
    public function showInRest(bool $show = true): self;
    public function restBase(string $base): self;
    /** use_block_editor_for_post_type フィルタで強制する。showInRestは自動変更しない。 */
    public function blockEditor(bool $enabled): self;

    // ---- 権限 ----
    /** @param string|array{0: string, 1: string} $type */
    public function capabilityType(string|array $type, bool $mapMetaCap = true): self;
    /** @param array<string, string> $capabilities */
    public function capabilities(array $capabilities): self;

    // ---- 機能・関連 ----
    public function supports(string ...$features): self;
    /** @param string ...$taxonomies スラッグまたはTaxonomyサブクラスFQCN */
    public function taxonomies(string ...$taxonomies): self;

    // ---- URL / アーカイブ ----
    public function hasArchive(bool|string $archive = true): self;
    /** @param bool|array{slug?: string, with_front?: bool, feeds?: bool, pages?: bool, ep_mask?: int} $rewrite */
    public function rewrite(bool|array $rewrite): self;
    /** rewrite(['slug' => $slug, 'with_front' => $withFront]) の短縮形 */
    public function rewriteSlug(string $slug, bool $withFront = true): self;
    public function queryVar(bool|string $queryVar): self;

    // ---- その他 ----
    public function canExport(bool $canExport = true): self;
    public function deleteWithUser(bool $delete = true): self;
    /** @param array<int, array<int, mixed>> $blockTemplate */
    public function template(array $blockTemplate, string|false $templateLock = false): self;
    /** エスケープハッチ: 任意のregister_post_type引数を直接設定 */
    public function arg(string $key, mixed $value): self;

    // ---- 読み取り(FW内部・PostType::register()用) ----
    public function slug(): string;
    public function isBuiltin(): bool;
    public function blockEditorPreference(): ?bool;
    /** @return list<string> supports未指定時は [] */
    public function supportsList(): array;
    /** @return array<string, mixed> register_post_type の $args(builtin時はEntityDefinitionException) */
    public function toArgs(): array;
}
```

### 4.3 振る舞い仕様

**`create(string $slug)`**:

- スラッグ検証: `/^[a-z0-9_\-]{1,20}$/` に一致しない場合 `EntityDefinitionException`(WPの20文字制限を事前検査)。
- FW既定値として `public => true` を `$args` に設定する(WP既定のfalseから変更。受託サイトCPTの実態に合わせた唯一の既定値変更であり、rules/に明記する)。それ以外の引数はWP既定に委ね、**呼ばれなかったセッターのキーは `toArgs()` に含めない**。

**`builtin(string $slug)`**:

- スラッグ検証は `create()` と同一(ただし長さ上限は適用しない。既存タイプ名をそのまま受ける)。
- builtinモードで呼び出し可能なメソッドは `supports()` と `blockEditor()` のみ。それ以外のセッターを呼んだ時点で `EntityDefinitionException` をthrow(設定が無視される事故を防ぐ。旧iwf2bはbuiltin時にargsが黙って捨てられていた)。

**各セッター**: snake_caseのWP引数キーへ1:1で書き込む(`excludeFromSearch` → `exclude_from_search` 等)。同一セッターの再呼び出しは上書き。`capabilityType()` は `capability_type` と `map_meta_cap` の2キーを設定する。

**`toArgs()`** の構築手順(決定的):

1. builtinなら `EntityDefinitionException`。
2. labels決定:
   - `label` が空かつ `labelOverrides` に `name` がない → `EntityDefinitionException`(「labelまたはlabels(name)が必須」)。
   - `label` 非空 → `LabelGenerator::forPostType($label, $labelLanguage)` で生成し、`labelOverrides` をキー単位で上書きマージ(`array_merge(生成, 上書き)`)。
   - `label` 空で `labelOverrides` のみ → そのまま `labels` に採用。
   - 結果を `$args['labels']` に設定し、`$args['label']` には `labels['name']` を設定。
3. `supports` が非null → `$args['supports']` に設定。
4. `taxonomies` が非空 → 各要素を1.5節の規約で解決し `$args['taxonomies']` に設定。
5. `$args` を連想配列のまま返す(キー順は挿入順でよい。比較テストでは `ksort` して検証する)。

---

## 5. Post\PostType(基底)

### 5.1 役割

投稿タイプ1種を表す抽象基底。サブクラスは `definition()` の実装のみ必須。テンプレート向け静的ファサード・クエリ入口・メタアクセス入口を提供する(DR-09の二層構成の「外側」)。インスタンス化は想定せず、全APIをstaticで提供する(状態はFQCNキーのメモ化のみで、`resetRuntimeCache()` で破棄可能)。

### 5.2 シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Meta\MetaBag;
use TMSWP\Core\Query\PageResult;

abstract class PostType
{
    /** @var array<class-string<self>, PostTypeDefinition> definition()のメモ化 */
    private static array $definitions = [];

    /** サブクラスが実装する唯一の必須メソッド。純粋関数であること(WP関数呼び出し禁止)。 */
    abstract public static function definition(): PostTypeDefinition;

    final public static function slug(): string;

    /** @internal Kernelがinit(優先度10)で正確に1回呼ぶ(1.2節契約3)。重複防止は持たない。 */
    final public static function register(): void;

    // ---- 取得(?T統一) ----
    final public static function get(\WP_Post|int $post): ?\WP_Post;
    final public static function findBySlug(string $slug): ?\WP_Post;
    final public static function is(\WP_Post|int|null $post): bool;

    // ---- クエリ ----
    /** サブクラスはオーバーライドして既定スコープを適用してよい(旧$find_argsの後継)。 */
    public static function query(): PostQuery;

    // ---- メタ(04文書連携) ----
    /** @return MetaBag<static> */
    final public static function meta(\WP_Post|int $post): MetaBag;

    // ---- テンプレート向けファサード ----
    final public static function permalink(\WP_Post|int $post): ?string;
    final public static function archiveUrl(): ?string;
    final public static function title(\WP_Post|int $post): string;
    final public static function thumbnail(\WP_Post|int $post, string|array $size = 'full'): ?Thumbnail;
    final public static function thumbnailUrl(\WP_Post|int $post, string|array $size = 'full'): ?string;

    /**
     * @param string $taxonomy スラッグまたはTaxonomyサブクラスFQCN
     * @return list<\WP_Term>
     */
    final public static function terms(\WP_Post|int $post, string $taxonomy): array;
    final public static function firstTerm(\WP_Post|int $post, string $taxonomy): ?\WP_Term;

    /** @return list<\WP_Post> ルート祖先→直近親の順 */
    final public static function parents(\WP_Post|int $post, bool $includeSelf = false): array;

    // ---- 書き込み ----
    /** @param array<string, mixed> $postarr 成功時は投稿ID。失敗時EntityPersistenceException */
    final public static function create(array $postarr): int;

    // ---- その他 ----
    final public static function postTypeObject(): ?\WP_Post_Type;

    /** @internal テスト専用: static::classのメモ化定義を破棄 */
    final public static function resetRuntimeCache(): void;
}
```

### 5.3 振る舞い仕様

**`slug()`**: メモ化済み `definition()` の `slug()` を返す。

**`register()`** の手順:

1. `$def = static::definition()`(メモ化)。
2. **builtinの場合**: `supportsList()` が非空なら各featureを `add_post_type_support($def->slug(), $feature)`。手順4へ。`register_post_type` は呼ばない。
3. **非builtinの場合**:
   - `supportsList()` に `'thumbnail'` を含む場合、テーマサポートをマージ登録する:
     - `get_theme_support('post-thumbnails')` が `true`(全タイプ有効) → 何もしない
     - 配列(対象タイプ限定) → 既存配列とこのスラッグの和集合で `add_theme_support('post-thumbnails', $merged)`
     - 未登録 → `add_theme_support('post-thumbnails', [$def->slug()])`
   - `register_post_type($def->slug(), $def->toArgs())` を実行。戻り値が `WP_Error` なら `EntityDefinitionException`(エラーメッセージを含める)。
4. `blockEditorPreference()` が非null → `add_filter('use_block_editor_for_post_type', $callback, 10, 2)`。callbackは「`$postType === $def->slug()` なら希望値を返し、それ以外は受け取った値を素通しする」クロージャ。

**`get()`**:

- `\WP_Post` 渡し: `$post->post_type === static::slug()` ならそのまま返す。不一致はnull。
- int渡し: `<= 0` はnull。`get_post($id)` がnull、またはpost_type不一致ならnull。
- post_statusでは絞らない(下書き・非公開も取得できる。公開状態の制御はクエリ側の責務)。

**`findBySlug()`**: `get_page_by_path($slug, OBJECT, static::slug())`。階層パス(`parent/child`)も受け付ける。null/タイプ不一致はnull。**タイトル検索フォールバックは行わない。**

**`is()`**: nullはfalse。それ以外は `static::get($post) !== null`。

**`query()`**: `PostQuery::of(static::class)` を返す。基底実装はスコープを追加しない。既定スコープが必要なサブクラスは次の規約でオーバーライドする:

```php
public static function query(): PostQuery
{
    return parent::query()->orderBy('menu_order')->status('publish');
}
```

**`meta()`**: `static::get($post)` でID正規化し(null時は対象ID=0のMetaBagを返すのではなく `EntityDefinitionException` をthrowする。存在しない投稿へのメタ書き込み事故防止)、04文書のMetaBagファクトリへ `ObjectType::Post`・投稿ID・`static::class` を渡して生成する。生成APIの正確な形は04文書の定義に従う。

PHPDocの `@return MetaBag<static>` は必須である。`MetaBag` は04文書で `@template TEntity of object` を宣言するジェネリッククラスであり、この注釈によって呼び出し元へエンティティクラスが型伝播する。04文書のPHPStan拡張(`MetaBagGetterReturnTypeExtension` の戻り値ナローイング、`MetaKeyOwnershipRule` のメタキー所属検査)はこの注釈を前提とするため、省略するとエンティティ単位の静的検査が機能しない。Taxonomy/UserRole基底の `meta()` も同一注釈を持つ(9.1/12.1のシグネチャ参照)。

**`permalink()`**: `get($post)` → null時null。`get_permalink($post)` の `false` はnullへ。

**`archiveUrl()`**: `get_post_type_archive_link(static::slug())` の `false` はnullへ。

**`title()`**: `get($post)` → null時 `''`。それ以外 `get_the_title($post)`(the_titleフィルタ適用済み)。

**`thumbnail()`** の手順(コア主経路+拡張フォールバック。DR-15):

1. `get($post)` → null時null。
2. `has_post_thumbnail($post)` がtrue:
   - `$src = wp_get_attachment_image_src(get_post_thumbnail_id($post), $size)`。falseなら手順3へ。
   - alt解決: `$alt = trim(wp_strip_all_tags((string) get_post_meta(get_post_thumbnail_id($post), '_wp_attachment_image_alt', true), true))` の1段のみ(コアの `wp_get_attachment_image()` / `get_the_post_thumbnail()` と同一セマンティクス)。添付の `post_excerpt` / `post_title` へはフォールバックしない(メタ未設定なら `''`。装飾画像の `alt=""` はa11y上正しい既定であり、WP 4.7で廃止された多段挙動は再実装しない)。
   - `new Thumbnail(url: $src[0], width: $src[1], height: $src[2], alt: $alt)` を返す。
3. フォールバックフィルタ: `$fallback = apply_filters('tmswp/post/thumbnail_fallback', null, $post, $size);`
   - `$fallback instanceof Thumbnail` ならそれを返す。それ以外(null含む)はnullを返す。
   - このフィルタの第1引数nullは「フォールバックなし」を意味する実値であり、ストレージ差し替え用のフォールスルーセンチネル(DR-18で禁止)ではない。Thumbnailは常に非空URLを持つため曖昧さは生じない。
   - tmswp/thumbnail拡張が本文img抽出フォールバックをここに差し込む。コアは正規表現抽出・`getimagesize` による同期HTTP I/Oを一切行わない。

**`thumbnailUrl()`**: `static::thumbnail($post, $size)?->url`。ダミー画像は呼び出し側の `?? $dummyUrl` で表現する(旧 `dummy_image` 引数は廃止)。

**`terms()`**: `get($post)` → null時 `[]`。taxonomyを1.5節規約で解決し `get_the_terms($post, $taxonomy)`(WPのオブジェクトタームキャッシュを内包するため旧FWの手動キャッシュ管理は廃止)。`false` / `WP_Error` は `[]`。戻りは `array_values()` で `list<\WP_Term>` に正規化。

**`firstTerm()`**: `terms()` の先頭、空ならnull。

**`parents()`**:

- `get($post)` → null時 `[]`。
- `post_parent` を `static::get()` でたどる(タイプ不一致・不存在で打ち切り)。無限ループ防止に深さ上限100を設け、超過時は `EntityDefinitionException`。
- `$includeSelf` がtrueなら自身を末尾に含む。戻り順は **ルート祖先が先頭、直近親(→自身)が末尾**(パンくず用)。

**`create()`**:

- `$postarr['post_type']` を `static::slug()` で強制上書き。`$postarr['ID']` はunset(更新誤用防止)。
- `wp_insert_post($postarr, true)` が `WP_Error` なら `EntityPersistenceException::fromWpError()`。成功時はint IDを返す。

**`postTypeObject()`**: `get_post_type_object(static::slug())`(`init` 前はnull)。

---

## 6. Post\Thumbnail

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

final readonly class Thumbnail
{
    public function __construct(
        public string $url,
        public ?int $width,
        public ?int $height,
        public string $alt,
    ) {
    }

    /** width/height属性付きimgタグ用の補助。width/heightがnullの場合は属性を出力しない。 */
    public function img(array $attributes = []): string;
}
```

- `url` は常に非空(空URLのThumbnailは生成禁止。コンストラクタで空文字なら `\InvalidArgumentException`)。
- `img()` は `Support\Html` の属性レンダラ(Support文書定義)で `<img src="..." width="..." height="..." alt="..." ...>` を生成し、エスケープ済み文字列を返す。`$attributes` で `class` 等を追加できる。`src` / `alt` / `width` / `height` を `$attributes` で上書きすることは不可(無視する)。
- `srcset` / `sizes` / `loading` 等のレスポンシブ画像属性は生成しない。これらが必要な場面では、Thumbnailを介さずテンプレート側で `get_the_post_thumbnail()` / `wp_get_attachment_image()` 等のWP標準手法を直接使う(ThumbnailはURLベースの軽量正規化DTOであり、コア画像レンダリングのラッパではない。フォールバック経路は添付IDを持たないため、この境界はDR-15の分割と一致する)。

---

## 7. Post\PostQuery

### 7.1 役割

投稿タイプスコープ付きのクエリビルダ。内部は常に `WP_Query` を生成する(`get_posts()` は使わない。`suppress_filters=true` による多言語プラグイン非互換を避けるため)。ビルダは可変(各メソッドは `$this` を返す)。複製は `clone` で行える(プロパティは全てスカラ・配列のためシャローコピーで安全)。

### 7.2 シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Query\PageResult;

final class PostQuery
{
    /** @var array<string, mixed> ビルダが構築するWP_Query引数 */
    private array $args = [];

    /** @var list<array<string, mixed>> meta_query句(relation AND) */
    private array $metaClauses = [];

    /** @var array<string, array<string, mixed>> 名前付きmeta句(orderByMeta用) */
    private array $namedMetaClauses = [];

    /** @var list<array<string, mixed>> tax_query句(relation AND) */
    private array $taxClauses = [];

    /** @var array<string, string> orderby指定(field => ASC|DESC) */
    private array $orderBy = [];

    /** @var array<string, mixed> withArg()による生引数(最後にマージ) */
    private array $rawArgs = [];

    private function __construct(
        private readonly string $postType,
    ) {
    }

    /** @param class-string<PostType> $postTypeClass */
    public static function of(string $postTypeClass): self;

    /** クラスを定義していない投稿タイプ向け(スラッグ直指定)。 */
    public static function ofType(string $postTypeSlug): self;

    // ---- スコープ ----
    public function status(string ...$status): self;
    public function anyStatus(): self;
    public function search(string $keyword): self;
    public function author(int|\WP_User $author): self;
    public function parent(int|\WP_Post $parent): self;
    public function slugIn(string ...$slugs): self;
    public function idIn(int ...$ids): self;
    public function idNotIn(int ...$ids): self;

    public function whereMeta(string $key, mixed $value, string $compare = '=', string $type = 'CHAR'): self;
    public function whereMetaExists(string $key): self;
    public function whereMetaNotExists(string $key): self;

    /**
     * @param string                       $taxonomy スラッグまたはTaxonomyサブクラスFQCN
     * @param int|string|list<int|string>  $terms
     */
    public function whereTerm(string $taxonomy, int|string|array $terms, string $field = 'slug', string $operator = 'IN'): self;

    /** 固定ページテンプレートで絞り込む(_wp_page_template疑似キー。旧FWの'template'キー後継)。 */
    public function template(string $templateFile): self;

    /** アイキャッチ有無で絞り込む(_thumbnail_id疑似キー)。 */
    public function hasThumbnail(bool $has = true): self;

    // ---- 並び順 ----
    public function orderBy(string $field, string $direction = 'ASC'): self;
    public function orderByDesc(string $field): self;
    public function orderByMeta(string $metaKey, string $direction = 'ASC', bool $numeric = false): self;

    // ---- 件数・ページング ----
    public function limit(int $limit): self;
    public function offset(int $offset): self;
    public function noPaging(): self;
    public function ignoreSticky(bool $ignore = true): self;

    /** エスケープハッチ。post_typeのみ上書き不可(常に強制再注入)。 */
    public function withArg(string $key, mixed $value): self;

    // ---- 終端 ----
    /** @return list<\WP_Post> */
    public function get(): array;
    public function first(): ?\WP_Post;
    /** @return list<int> */
    public function ids(): array;
    public function count(): int;
    public function exists(): bool;
    /** first()のパーマリンク。該当なしはnull。 */
    public function url(): ?string;
    /** @return PageResult<\WP_Post> */
    public function paginate(int $perPage, ?int $page = null): PageResult;
    /** WP標準ループ用のWP_Queryを返す(no_found_rowsは設定しない)。 */
    public function wpQuery(): \WP_Query;
    /** @return array<string, mixed> 構築されるWP_Query引数(テスト・デバッグ用) */
    public function toArgs(): array;
}
```

### 7.3 引数変換仕様(決定的マッピング)

| メソッド | WP_Query引数 |
|---|---|
| (常時) | `post_type` = スコープのスラッグ(最終段で強制注入。`withArg` でも上書き不可) |
| (既定) | `post_status` = `['publish']`、`ignore_sticky_posts` = `true` |
| `status(...$s)` | `post_status` = `$s` |
| `anyStatus()` | `post_status` = `'any'` |
| `search($k)` | `s` = `$k` |
| `author($a)` | `author` = ID(WP_Userは `->ID`) |
| `parent($p)` | `post_parent` = ID |
| `slugIn(...$s)` | `post_name__in` = `$s` |
| `idIn(...$ids)` | `post__in` = `$ids` |
| `idNotIn(...$ids)` | `post__not_in` = `$ids` |
| `whereMeta($key, $value, $compare, $type)` | `meta_query` 句 `['key' => $key, 'value' => $value, 'compare' => $compare, 'type' => $type]` を追加(relation AND) |
| `whereMetaExists($key)` / `whereMetaNotExists($key)` | `meta_query` 句 `['key' => $key, 'compare' => 'EXISTS'\|'NOT EXISTS']` |
| `whereTerm($tax, $terms, $field, $operator)` | `tax_query` 句 `['taxonomy' => 解決済スラッグ, 'field' => $field, 'terms' => (array) $terms, 'operator' => $operator]`(relation AND) |
| `template($file)` | `meta_query` 句 `['key' => '_wp_page_template', 'value' => $file]` |
| `hasThumbnail($has)` | `meta_query` 句 `['key' => '_thumbnail_id', 'compare' => $has ? 'EXISTS' : 'NOT EXISTS']` |
| `orderBy($f, $d)` | `orderby[$f] = $d`(複数呼び出しで累積。同一fieldは上書き) |
| `orderByMeta($key, $d, $numeric)` | 名前付きmeta句 `tms_order_{n}`(`['key' => $key, 'compare' => 'EXISTS', 'type' => $numeric ? 'NUMERIC' : 'CHAR']`)を追加し、`orderby['tms_order_{n}'] = $d`。`{n}` は呼び出し順の0始まり連番 |
| `limit($n)` | `posts_per_page` = `$n`(`-1` は全件。`0` 以下(-1除く)は `QueryException`) |
| `offset($n)` | `offset` = `$n`(負値は `QueryException`) |
| `noPaging()` | `nopaging` = `true` |
| `ignoreSticky($b)` | `ignore_sticky_posts` = `$b` |

**バリデーション**: `$direction` は大文字小文字を無視して `ASC`/`DESC` のみ許可(正規化して保持)、`$compare` はWPの許可リスト(`=`, `!=`, `>`, `>=`, `<`, `<=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `EXISTS`, `NOT EXISTS`, `REGEXP`, `NOT REGEXP`, `RLIKE`)、`$type` は(`NUMERIC`, `BINARY`, `CHAR`, `DATE`, `DATETIME`, `DECIMAL`, `SIGNED`, `TIME`, `UNSIGNED`)のみ。違反は `QueryException`。

**`toArgs()` のマージ順(決定的)**: ①既定値 → ②セッター由来 `$args` → ③`meta_query`(無名句+名前付き句、`relation => 'AND'`。句が1つでもrelationを付ける) → ④`tax_query`(同上) → ⑤`orderby` / 対応する `order` 削除(連想配列形式を使う) → ⑥`rawArgs`(`withArg`) → ⑦`post_type` 強制再注入。

### 7.4 終端メソッド仕様

| メソッド | 追加引数 | 振る舞い |
|---|---|---|
| `get()` | `no_found_rows=true` | `(new \WP_Query($args))->posts` を `list<\WP_Post>` で返す(`fields` 指定があっても無視し、必ずWP_Postで返すため `fields` キーは強制unset) |
| `first()` | `posts_per_page=1`, `no_found_rows=true` | 先頭1件 or null |
| `ids()` | `fields='ids'`, `no_found_rows=true` | `list<int>` |
| `count()` | `fields='ids'`, `posts_per_page=1`, `paged=1`, `no_found_rows=false` | `(int) $query->found_posts` |
| `exists()` | — | `first() !== null` と等価(実装は `ids()` + `posts_per_page=1` でよい) |
| `url()` | — | `first()` のパーマリンク。null連鎖 |
| `paginate($perPage, $page)` | `posts_per_page=$perPage`, `paged=$page`, `no_found_rows=false` | `$perPage < 1` は `QueryException`。`$page` がnullなら `max(1, (int) get_query_var('paged') ?: (int) get_query_var('page') ?: 1)` で解決。`PageResult<\WP_Post>` を返す(`total` = `found_posts`、`items` = `posts`) |
| `wpQuery()` | — | `new \WP_Query(toArgs())` をそのまま返す(`no_found_rows` を設定しない=WP標準ページネーション可能) |

終端メソッドが追加する引数はビルダ状態を変更しない(`toArgs()` の結果をローカルに複製して使う)。

---

## 8. Tax\TaxonomyDefinition

### 8.1 役割

`register_taxonomy` に渡す全引数のfluentビルダ。`objectType()` にPostTypeサブクラスのFQCNを渡せる相互参照(旧FWの良設計を踏襲)を持つ。

### 8.2 シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Label\LabelGenerator;
use TMSWP\Core\Label\LabelLanguage;

final class TaxonomyDefinition
{
    private string $label = '';

    /** @var array<string, string> */
    private array $labelOverrides = [];

    private LabelLanguage $labelLanguage = LabelLanguage::En;

    /** @var array<string, mixed> */
    private array $args = [];

    /** @var list<string> 未解決(FQCN混在可)のオブジェクトタイプ参照 */
    private array $objectTypes = [];

    private function __construct(
        private readonly string $slug,
        private readonly bool $builtin,
    ) {
    }

    public static function create(string $slug): self;
    /** 登録済みタクソノミー(category / post_tag等)へのアダプタ。 */
    public static function builtin(string $slug): self;

    /** @param string ...$objectTypes 投稿タイプスラッグまたはPostTypeサブクラスFQCN */
    public function objectType(string ...$objectTypes): self;

    // ---- ラベル ----
    public function label(string $label): self;
    /** @param array<string, string> $labels */
    public function labels(array $labels): self;
    public function labelLanguage(LabelLanguage $language): self;

    // ---- 基本属性 ----
    public function description(string $description): self;
    public function public(bool $public = true): self;
    public function hierarchical(bool $hierarchical = true): self;
    public function publiclyQueryable(bool $queryable = true): self;

    // ---- 管理画面 ----
    public function showUi(bool $show = true): self;
    public function showInMenu(bool $show = true): self;
    public function showInNavMenus(bool $show = true): self;
    public function showTagcloud(bool $show = true): self;
    public function showInQuickEdit(bool $show = true): self;
    public function showAdminColumn(bool $show = true): self;

    // ---- REST ----
    public function showInRest(bool $show = true): self;
    public function restBase(string $base): self;

    // ---- 権限・URL・その他 ----
    /** @param array<string, string> $capabilities */
    public function capabilities(array $capabilities): self;
    /** @param bool|array{slug?: string, with_front?: bool, hierarchical?: bool, ep_mask?: int} $rewrite */
    public function rewrite(bool|array $rewrite): self;
    public function rewriteSlug(string $slug, bool $withFront = true, bool $hierarchical = false): self;
    public function queryVar(bool|string $queryVar): self;
    /** @param string|array{name: string, slug?: string, description?: string} $term */
    public function defaultTerm(string|array $term): self;
    public function sort(bool $sort = true): self;
    public function arg(string $key, mixed $value): self;

    // ---- 読み取り(FW内部用) ----
    public function slug(): string;
    public function isBuiltin(): bool;
    /** @return list<string> 1.5節規約で解決済みの投稿タイプスラッグ */
    public function resolvedObjectTypes(): array;
    /** @return array<string, mixed> register_taxonomy の $args(builtin時はEntityDefinitionException) */
    public function toArgs(): array;
}
```

### 8.3 振る舞い仕様

- **`create()`**: スラッグ検証 `/^[a-z0-9_\-]{1,32}$/`(WPの32文字制限)。違反は `EntityDefinitionException`。既定値の変更はしない(投稿タイプと異なり `public` のWP既定はtrueのため)。
- **`builtin()`**: 呼び出し可能なのは `objectType()` のみ。それ以外のセッターは即 `EntityDefinitionException`。組込タクソノミーをCPTに紐付ける用途(`register_taxonomy_for_object_type`)に限定する。
- **`objectType()`**: 複数回呼ぶと追記(重複は解決後に除去)。未指定のまま登録すると「どの投稿タイプにも紐付かないタクソノミー」として `register_taxonomy($slug, [], $args)` で登録される(後から紐付ける運用を許容)。
- **`resolvedObjectTypes()`**: 各要素を1.5節規約(期待基底=`Post\PostType`)で解決し、`array_values(array_unique(...))` で返す。
- **`toArgs()`**: labels決定はPostTypeDefinitionと同一手順(生成元は `LabelGenerator::forTaxonomy`)。`label` も `labels['name']` も無い場合は `EntityDefinitionException`。

---

## 9. Tax\Taxonomy(基底)

### 9.1 シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Meta\MetaBag;

abstract class Taxonomy
{
    /** @var array<class-string<self>, TaxonomyDefinition> */
    private static array $definitions = [];

    abstract public static function definition(): TaxonomyDefinition;

    final public static function slug(): string;

    /** @internal Kernelがinit(優先度9)・PostType群(優先度10)より先に呼ぶ。 */
    final public static function register(): void;

    // ---- 取得(?T統一) ----
    final public static function get(\WP_Term|int $term): ?\WP_Term;
    final public static function findBySlug(string $slug): ?\WP_Term;
    final public static function findByName(string $name): ?\WP_Term;
    final public static function is(\WP_Term|int|null $term): bool;

    // ---- クエリ ----
    public static function query(): TermQuery;
    /** 全タームの取得(テンプレートのナビ用最頻出ショートカット)。 @return list<\WP_Term> */
    final public static function all(bool $hideEmpty = false): array;

    // ---- メタ ----
    /** @return MetaBag<static> */
    final public static function meta(\WP_Term|int $term): MetaBag;

    // ---- テンプレート向けファサード ----
    /** タームアーカイブURL。stringはスラッグとして解決。 */
    final public static function url(\WP_Term|int|string $term): ?string;

    /** @return list<\WP_Term> ルート祖先→直近親の順 */
    final public static function parents(\WP_Term|int $term, bool $includeSelf = false): array;

    // ---- 書き込み ----
    /** @param array<string, mixed> $args wp_insert_termの$args。成功時はterm_id */
    final public static function create(string $name, array $args = []): int;

    // ---- その他 ----
    final public static function taxonomyObject(): ?\WP_Taxonomy;

    /** @internal テスト専用 */
    final public static function resetRuntimeCache(): void;
}
```

### 9.2 振る舞い仕様

**`register()`** の手順:

1. `$def = static::definition()`(メモ化)。
2. **builtinの場合**: `resolvedObjectTypes()` の各スラッグに対し `register_taxonomy_for_object_type($def->slug(), $objectType)` を実行する。
   - 対象がbuiltin投稿タイプ(post/page等。コアが `init` 優先度0で登録済み)なら初回試行で成功する(fast-path)。
   - 対象が同一 `init` 内の後続(02文書の属性スケジュール表: PostType群=優先度10)で登録されるCPTの場合、初回試行はfalseを返す(ロード順による未登録であり異常ではない)。**falseになったスラッグのみ**を `init` 優先度11で1回だけ再試行する。
   - 再試行後もfalseの場合は真の失敗(スラッグtypo・未登録投稿タイプ等)であり、`Log::warning`(Log文書のLogファサード)で警告する。これによりfalseの意味は「再試行前=ロード順起因(正常)」「再試行後=真の失敗」に純化され、warningは常に対処すべき信号になる。
3. **非builtinの場合**: `register_taxonomy($def->slug(), $def->resolvedObjectTypes(), $def->toArgs())`。戻り値が `WP_Error` なら `EntityDefinitionException`。

(注: Kernel契約1.2によりTaxonomy群はPostType群より先に登録される。`register_taxonomy` の `$object_types` は未登録スラッグでも受理されるため、相互参照は同一init内で完結する。)

**`get()`**:

- `\WP_Term` 渡し: `taxonomy` プロパティ一致を検証。不一致null。
- int渡し: `<= 0` null。`get_term($id, static::slug())` が `WP_Error`/null ならnull。

**`findBySlug()` / `findByName()`**: `get_term_by('slug'|'name', $value, static::slug())`。falseはnull。旧FWの「slug→name」二段フォールバックは廃止し、2つの明示メソッドに分解。

**`all()`**: `static::query()->hideEmpty($hideEmpty)->get()` と等価。

**`url()`**:

- string渡しは `findBySlug()` で解決。`get()`/解決失敗はnull。
- `get_term_link($term)` が `WP_Error` ならnull。

**`parents()`**: `parent` プロパティを `get_term((int) $parent, static::slug())` でたどる(`WP_Error`/null/深さ100超で打ち切り。深さ超過は `EntityDefinitionException`)。戻り順・`$includeSelf` の意味はPostTypeと同一。

**`create()`**: `wp_insert_term($name, static::slug(), $args)`。`WP_Error` は `EntityPersistenceException::fromWpError()`。成功時は戻り配列の `term_id`(int)を返す。

**`meta()`**: PostType::meta() と同一規約(`ObjectType::Term`)。

---

## 10. Tax\TermQuery

### 10.1 シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

final class TermQuery
{
    /** @var array<string, mixed> */
    private array $args = [];

    /** @var list<array<string, mixed>> */
    private array $metaClauses = [];

    /** @var array<string, array<string, mixed>> */
    private array $namedMetaClauses = [];

    /** @var array<string, string> */
    private array $orderBy = [];

    /** @var array<string, mixed> */
    private array $rawArgs = [];

    private function __construct(
        private readonly string $taxonomy,
    ) {
    }

    /** @param class-string<Taxonomy> $taxonomyClass */
    public static function of(string $taxonomyClass): self;
    public static function ofTaxonomy(string $taxonomySlug): self;

    // ---- スコープ ----
    /** 既定はhide_empty=false(WP既定のtrueから変更。テンプレートのナビ用途を優先)。 */
    public function hideEmpty(bool $hide = true): self;
    public function search(string $keyword): self;
    /** 直下の子のみ。 */
    public function parent(int|\WP_Term $parent): self;
    /** 全子孫。 */
    public function childrenOf(int|\WP_Term $ancestor): self;
    public function slugIn(string ...$slugs): self;
    public function idIn(int ...$ids): self;
    public function idNotIn(int ...$ids): self;
    /** 指定投稿に付与されたタームに絞る。 */
    public function forPost(\WP_Post|int ...$posts): self;

    public function whereMeta(string $key, mixed $value, string $compare = '=', string $type = 'CHAR'): self;
    public function whereMetaExists(string $key): self;
    public function whereMetaNotExists(string $key): self;

    // ---- 並び順 ----
    public function orderBy(string $field, string $direction = 'ASC'): self;
    public function orderByDesc(string $field): self;
    public function orderByMeta(string $metaKey, string $direction = 'ASC', bool $numeric = false): self;

    // ---- 件数 ----
    public function limit(int $limit): self;
    public function offset(int $offset): self;

    /** エスケープハッチ。taxonomyのみ上書き不可。 */
    public function withArg(string $key, mixed $value): self;

    // ---- 終端 ----
    /** @return list<\WP_Term> */
    public function get(): array;
    public function first(): ?\WP_Term;
    /** @return list<int> */
    public function ids(): array;
    public function count(): int;
    public function exists(): bool;
    /** first()のタームアーカイブURL。該当なしはnull。 */
    public function url(): ?string;
    public function wpTermQuery(): \WP_Term_Query;
    /** @return array<string, mixed> */
    public function toArgs(): array;
}
```

### 10.2 引数変換仕様

| メソッド | WP_Term_Query引数 |
|---|---|
| (常時) | `taxonomy` = スコープのスラッグ(最終段で強制注入) |
| (既定) | `hide_empty` = `false` |
| `hideEmpty($b)` | `hide_empty` = `$b` |
| `search($k)` | `search` = `$k` |
| `parent($p)` | `parent` = ID |
| `childrenOf($t)` | `child_of` = ID |
| `slugIn(...$s)` | `slug` = `$s` |
| `idIn(...$ids)` | `include` = `$ids` |
| `idNotIn(...$ids)` | `exclude` = `$ids` |
| `forPost(...$p)` | `object_ids` = ID配列 |
| `whereMeta*` / `orderByMeta` | PostQueryと同一方式(`meta_query` + 名前付き句 `tms_order_{n}`) |
| `orderBy($f, $d)` | `orderby` / `order`。複数指定時は最初の1組のみ有効とし、2組目以降の `orderBy()` 呼び出しは `QueryException`(WP_Term_Queryは複数orderbyの連想配列をサポートしないため。`orderByMeta` との併用も不可) |
| `limit($n)` | `number` = `$n`(`0` は全件=WP仕様。負値は `QueryException`) |
| `offset($n)` | `offset` = `$n` |

バリデーション規則(direction/compare/type)はPostQueryと同一。マージ順も同一(taxonomy強制注入で終わる)。

### 10.3 終端メソッド仕様

| メソッド | 振る舞い |
|---|---|
| `get()` | `get_terms(toArgs())`。`WP_Error` は `[]`。`array_values()` で `list<\WP_Term>` |
| `first()` | `number=1` を適用して先頭 or null |
| `ids()` | `fields='ids'` で `list<int>`(`WP_Error` は `[]`) |
| `count()` | `fields='count'` で実行し `(int)` キャスト(`WP_Error` は `0`) |
| `exists()` | `first() !== null` 相当 |
| `url()` | `first()` → `get_term_link`。`WP_Error`/該当なしはnull(旧 `get_term_link($args)` の後継。既定hide_empty=falseのため旧FWの強制falseと同等の挙動) |
| `wpTermQuery()` | `new \WP_Term_Query(toArgs())` |

ページネーションは提供しない(`limit()`+`offset()`+`count()` で構成可能。タームの paginate 需要は実利用調査でゼロ)。

---

## 11. User\UserRoleDefinition

### 11.1 役割

ロール1種の宣言。冪等なロール+capability登録のみを責務とし、認証・登録フローは含めない(app側レシピ。rules/に記載)。

### 11.2 シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\User;

final class UserRoleDefinition
{
    private string $label = '';

    /** @var list<string> */
    private array $capabilities = [];

    private function __construct(
        private readonly string $role,
        private readonly bool $builtin,
    ) {
    }

    /** 新規ロールの定義。 */
    public static function create(string $role): self;

    /** 既存ロール(administrator等)へのアダプタ。add_roleせずcap追加のみ可能。 */
    public static function builtin(string $role): self;

    public function label(string $label): self;

    /** 宣言capの追加(複数回呼び出しで追記、重複除去)。 */
    public function capabilities(string ...$capabilities): self;

    // ---- 読み取り ----
    public function role(): string;
    public function isBuiltin(): bool;
    /** 未設定時はrole名を返す。 */
    public function roleLabel(): string;
    /** @return list<string> */
    public function capabilityList(): array;
}
```

### 11.3 振る舞い仕様

- **`create()`**: ロール名検証 `/^[a-z0-9_\-]+$/`。違反は `EntityDefinitionException`。
- **`builtin()`**: `label()` の呼び出しは `EntityDefinitionException`(既存ロールの表示名は変更しない)。`capabilities()` は許可(管理者へのカスタムcap付与等)。
- capの自動削除は行わない(宣言から外したcapはDBに残る。削除はWP-CLIまたはapp側で明示的に行う規約。rules/に明記)。

---

## 12. User\UserRole(基底)

### 12.1 シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\User;

use TMSWP\Core\Meta\MetaBag;

abstract class UserRole
{
    /** @var array<class-string<self>, UserRoleDefinition> */
    private static array $definitions = [];

    abstract public static function definition(): UserRoleDefinition;

    final public static function role(): string;

    /** @internal Kernelがinit(優先度10)・PostType群の後に呼ぶ。冪等。 */
    final public static function register(): void;

    // ---- 取得(?T統一) ----
    final public static function get(\WP_User|int $user): ?\WP_User;
    final public static function findByEmail(string $email): ?\WP_User;
    final public static function findByLogin(string $login): ?\WP_User;
    /** ログイン中ユーザーがこのロールならWP_User、未ログイン・ロール外はnull。 */
    final public static function current(): ?\WP_User;
    /** nullは「現在のログインユーザー」を意味する。 */
    final public static function is(\WP_User|int|null $user = null): bool;

    // ---- クエリ ----
    public static function query(): UserQuery;

    // ---- メタ ----
    /** @return MetaBag<static> */
    final public static function meta(\WP_User|int $user): MetaBag;

    // ---- テンプレート向けファサード ----
    final public static function authorUrl(\WP_User|int $user): ?string;

    // ---- 書き込み ----
    /** @param array<string, mixed> $userdata 成功時はユーザーID。失敗時EntityPersistenceException */
    final public static function create(string $login, string $password, array $userdata = []): int;

    /** @internal テスト専用 */
    final public static function resetRuntimeCache(): void;
}
```

### 12.2 振る舞い仕様

**`register()`** の手順(冪等):

1. `$def = static::definition()`(メモ化)。
2. `$role = get_role($def->role())`。
3. builtinで `$role` がnull → `EntityDefinitionException`(存在しないロールへのアダプタ)。
4. 非builtinで `$role` がnull → `$role = add_role($def->role(), $def->roleLabel())`(`add_role` はDB(`wp_user_roles` オプション)へ書き込むが、手順2のチェックにより2回目以降のリクエストでは実行されない=冪等)。`add_role` がnullを返した場合は `EntityDefinitionException`。
5. `$def->capabilityList()` の各capについて `$role->has_cap($cap)` がfalseのときのみ `$role->add_cap($cap)`(DB書き込みは欠如時のみ=冪等)。

**ロール所属判定(共通内部規則)**: `in_array(static::role(), (array) $user->roles, true)`。旧FWの `user_can($user, $role)`(ロール名をcap扱いするWP慣用)は暗黙的すぎるため `roles` 配列の直接検査に変更する。

**`get()`**:

- `\WP_User` 渡し: ロール所属判定。不一致null。
- int渡し: `<= 0` null。`get_user_by('id', $id)` がfalse、またはロール不一致ならnull。

**`findByEmail()` / `findByLogin()`**: `get_user_by('email'|'login', $value)` + ロール所属判定。falseはnull。旧FWの「email→login→slug」多段解決は廃止。

**`current()`**: `is_user_logged_in()` がfalseならnull。`wp_get_current_user()` がロール所属ならそのWP_User、外ならnull。

**`is()`**: 引数nullは `current() !== null`。それ以外は `get($user) !== null`。

**`create()`**:

- `$userdata['user_login'] = $login`、`$userdata['user_pass'] = $password`、`$userdata['role'] = static::role()` を強制設定。`$userdata['ID']` はunset(更新誤用防止)。
- `wp_insert_user($userdata)` が `WP_Error` なら `EntityPersistenceException::fromWpError()`。成功時int ID。
- builtin定義でも使用可(既存ロールのユーザー作成)。

**`authorUrl()`**: `get($user)` → null時null。`get_author_posts_url($user->ID)`。空文字はnullへ正規化。

**`meta()`**: PostType::meta() と同一規約(`ObjectType::User`)。

---

## 13. User\UserQuery

### 13.1 シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\User;

use TMSWP\Core\Query\PageResult;

final class UserQuery
{
    /** @var array<string, mixed> */
    private array $args = [];

    /** @var list<array<string, mixed>> */
    private array $metaClauses = [];

    /** @var array<string, array<string, mixed>> */
    private array $namedMetaClauses = [];

    /** @var array<string, mixed> */
    private array $rawArgs = [];

    private function __construct(
        private readonly string $role,
    ) {
    }

    /** @param class-string<UserRole> $userRoleClass */
    public static function of(string $userRoleClass): self;
    public static function ofRole(string $role): self;

    // ---- スコープ ----
    /**
     * キーワード検索。$keywordに'*'が含まれない場合は前後に'*'を自動付与する。
     * @param string ...$columns user_login / user_email / user_nicename / user_url / display_name / ID
     */
    public function search(string $keyword, string ...$columns): self;
    public function idIn(int ...$ids): self;
    public function idNotIn(int ...$ids): self;

    public function whereMeta(string $key, mixed $value, string $compare = '=', string $type = 'CHAR'): self;
    public function whereMetaExists(string $key): self;
    public function whereMetaNotExists(string $key): self;

    // ---- 並び順 ----
    /** $field: login / email / registered / display_name / ID / include / meta_value 等(WP_User_Query準拠) */
    public function orderBy(string $field, string $direction = 'ASC'): self;
    public function orderByDesc(string $field): self;
    public function orderByMeta(string $metaKey, string $direction = 'ASC', bool $numeric = false): self;

    // ---- 件数・ページング ----
    public function limit(int $limit): self;
    public function offset(int $offset): self;

    /** エスケープハッチ。roleのみ上書き不可。 */
    public function withArg(string $key, mixed $value): self;

    // ---- 終端 ----
    /** @return list<\WP_User> */
    public function get(): array;
    public function first(): ?\WP_User;
    /** @return list<int> */
    public function ids(): array;
    public function count(): int;
    public function exists(): bool;
    /** first()の投稿者アーカイブURL。該当なしはnull。 */
    public function url(): ?string;
    /** @return PageResult<\WP_User> */
    public function paginate(int $perPage, ?int $page = null): PageResult;
    public function wpUserQuery(): \WP_User_Query;
    /** @return array<string, mixed> */
    public function toArgs(): array;
}
```

### 13.2 引数変換・終端仕様

| メソッド | WP_User_Query引数 |
|---|---|
| (常時) | `role` = スコープのロール(最終段で強制注入)。`fields` = `'all'` 既定 |
| (既定) | `count_total` = `false` |
| `search($k, ...$c)` | `search` = ワイルドカード処理後の `$k`、`$c` 非空なら `search_columns` = `$c` |
| `idIn` / `idNotIn` | `include` / `exclude` |
| `whereMeta*` / `orderByMeta` | PostQueryと同一方式(`meta_query` + 名前付き句 `tms_order_{n}`、`orderby[tms_order_{n}]`) |
| `orderBy($f, $d)` | `orderby[$f] = $d`(WP_User_Queryは連想配列orderbyをサポート。複数累積可) |
| `limit($n)` | `number` = `$n`(`-1` は全件。`0` 以下(-1除く)は `QueryException`) |
| `offset($n)` | `offset` = `$n` |

| 終端 | 振る舞い |
|---|---|
| `get()` | `(new \WP_User_Query($args))->get_results()` を `list<\WP_User>` で |
| `first()` | `number=1` → 先頭 or null |
| `ids()` | `fields='ID'` → `list<int>`(各要素を `(int)` キャスト) |
| `count()` | `fields='ID'`, `number=1`, `count_total=true` → `(int) $query->get_total()` |
| `exists()` | `first() !== null` 相当 |
| `url()` | `first()` → `get_author_posts_url()`。該当なしnull |
| `paginate($perPage, $page)` | `number=$perPage`, `offset=($page - 1) * $perPage`, `count_total=true`。`$page` 解決規則はPostQueryと同一。`PageResult<\WP_User>`(`total` = `get_total()`) |
| `wpUserQuery()` | `new \WP_User_Query(toArgs())` |

---

## 14. 利用例(app側コード)

### 14.1 エンティティ定義(テーマ/プラグイン共通、app/に置くだけ)

```php
<?php
// app/Post/BlogPost.php

declare(strict_types=1);

namespace App\Post;

use App\Tax\BlogCategoryTax;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostQuery;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class BlogPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String, default: '')]
    public const META_SUBTITLE = 'subtitle';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('blog')
            ->label('ブログ・導入事例')
            ->hasArchive()
            ->supports('title', 'editor', 'thumbnail')
            ->rewriteSlug('blog')
            ->menuIcon('dashicons-edit')
            ->blockEditor(false);
    }

    /** 既定スコープ: 公開済みを新しい順 */
    public static function query(): PostQuery
    {
        return parent::query()->orderByDesc('date');
    }

    /** 頻出アクセサは明示メソッドを書く規約(DR-05) */
    public static function subtitle(\WP_Post|int $post): string
    {
        return static::meta($post)->string(self::META_SUBTITLE);
    }
}
```

```php
<?php
// app/Tax/BlogCategoryTax.php

declare(strict_types=1);

namespace App\Tax;

use App\Post\BlogPost;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;

#[AsTaxonomy]
final class BlogCategoryTax extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('blog_category')
            ->label('ブログカテゴリ')
            ->objectType(BlogPost::class)   // FQCN相互参照
            ->hierarchical()
            ->rewriteSlug('blog/category')
            ->showAdminColumn();
    }
}
```

```php
<?php
// app/Post/PagePost.php — 組込'page'へのアダプタ(スターター同梱)

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class PagePost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('page');
    }
}
```

```php
<?php
// app/User/ContractorUser.php — 会員サイト向けロール

declare(strict_types=1);

namespace App\User;

use TMSWP\Core\Attribute\AsUserRole;
use TMSWP\Core\User\UserRole;
use TMSWP\Core\User\UserRoleDefinition;

#[AsUserRole]
final class ContractorUser extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('contractor')
            ->label('契約者')
            ->capabilities('read', 'upload_files');
    }
}
```

### 14.2 WP標準テンプレートからの利用(旧FW高頻度API 100箇所超の置き換え)

```php
<?php
// header.php — アーカイブリンク(旧 get_archive_link、27箇所相当)
use App\Post\BlogPost;
use App\Post\PagePost;
?>
<a href="<?php echo esc_url(BlogPost::archiveUrl() ?? home_url('/')); ?>">ブログ</a>

<?php // テンプレート名→固定ページURL逆引き(旧 PagePost::get_permalink(['template' => ...])、39箇所相当) ?>
<a href="<?php echo esc_url(PagePost::query()->template('tmpl-contact.php')->url() ?? '#'); ?>">お問い合わせ</a>
```

```php
<?php
// archive-blog.php — サムネイル+ターム(旧 get_thumbnail / get_terms、計34箇所相当)
use App\Post\BlogPost;
use App\Tax\BlogCategoryTax;

while (have_posts()) {
    the_post();
    $thumbUrl = BlogPost::thumbnailUrl(get_the_ID(), 'medium')
        ?? get_theme_file_uri('assets/img/dummy.png');
    $category = BlogPost::firstTerm(get_the_ID(), BlogCategoryTax::class);
    ?>
    <article>
        <img src="<?php echo esc_url($thumbUrl); ?>" alt="">
        <?php if ($category !== null) : ?>
            <a href="<?php echo esc_url(BlogCategoryTax::url($category) ?? '#'); ?>">
                <?php echo esc_html($category->name); ?>
            </a>
        <?php endif; ?>
        <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
    </article>
    <?php
}
```

```php
<?php
// front-page.php — スコープ付きクエリ+タクソノミーナビ
use App\Post\ProductPost;
use App\Tax\BusinessTax;

$recent = ProductPost::query()
    ->whereTerm(BusinessTax::class, 'water-treatment')
    ->hasThumbnail()
    ->limit(4)
    ->get();

foreach (BusinessTax::all() as $term) {
    printf(
        '<a href="%s">%s</a>',
        esc_url(BusinessTax::url($term) ?? '#'),
        esc_html($term->name)
    );
}
```

```php
<?php
// page-members.php — ページネーション
use App\User\ContractorUser;

$page = ContractorUser::query()
    ->whereMeta('prefecture', '東京都')
    ->orderBy('registered', 'DESC')
    ->paginate(perPage: 20);

foreach ($page as $user) {
    echo esc_html($user->display_name);
}

echo paginate_links([
    'total'   => $page->totalPages(),
    'current' => $page->currentPage,
]);
```

### 14.3 メタ・書き込み

```php
use App\Post\ProductPost;

$price = ProductPost::meta($post)->int(ProductPost::META_PRICE);   // 型付き読み取り(04文書)
ProductPost::meta($post)->set(ProductPost::META_PRICE, 1200);

$id = ProductPost::create([
    'post_title'  => '新製品',
    'post_status' => 'draft',
]); // 失敗時は EntityPersistenceException
```

---

## 15. 旧iwf2bからの設計根拠(簡潔に)

| 旧iwf2b | 本設計 | 根拠 |
|---|---|---|
| staticプロパティ宣言(`$post_type`/`$args`)+AbstractSingleton | `definition()` が返す型付きDefinition+純static基底 | 宣言的定義の価値(継承58+42クラスの実績)は維持しつつ、型なし配列とシングルトンのテスト不能性を排除(DR-09) |
| `get()` のnull/false不統一、slug→タイトル多段解決 | `?T` 統一+`findBy*` 明示分解、タイトル検索廃止 | 同名タイトル取り違え事故源・型不統一の根治 |
| labels自動生成(英語フォーマット+iwf2bテキストドメイン依存) | en/jaテンプレート内蔵の `LabelGenerator`、gettext非依存 | 既定 en でソース文字列方針に揃え、日本語サイトは `labelLanguage(LabelLanguage::Ja)` で決定的に切り替える(DR-17)。2クラスに重複していた生成コードの一元化 |
| `$find_args`+`create_args()` | `query()` オーバーライド+クエリビルダ | グローバルスコープ相当の価値は維持し、配列マージの暗黙性を型付きAPIへ |
| `template`/`thumbnail` 擬似キー | `PostQuery::template()`/`hasThumbnail()` | 実利用39箇所超の高価値機能を明示メソッド化 |
| `get_thumbnail` の3段フォールバック(本文img抽出+getimagesize) | コアは主経路(WP標準API)のみ+`tmswp/post/thumbnail_fallback` フィルタ | レンダリング中の同期HTTP I/O排除。分割境界は「主経路コア・本文抽出は拡張」(DR-15) |
| `$object_type` にAbstractPost FQCN | `objectType()` のFQCN受理を1.5節規約で全クエリAPIへ拡張 | 旧FWの良設計(マジックストリング排除)の踏襲・拡張 |
| 手動タームキャッシュ(`get_object_term_cache`) | `get_the_terms()` に委譲 | WP4.7以降の車輪の再発明の除去 |
| `insert_default_meta`(空文字メタ自動挿入) | 廃止 | `register_*_meta` のdefault値(04文書・DR-05)で代替。空文字レコードのDB増殖を解消 |
| `AbstractUser`(非abstract・`user_register` をadd_filterで誤登録) | abstract基底+登録フックなし(メタdefaultは04文書の責務) | バグの根治と責務縮小。ロール定義+クエリの薄さは維持し、認証フローは引き続きapp側(rules/レシピ) |
| `__callStatic`(MK_*/ON_*) | 全廃。`meta()` ファサード+明示アクセサ規約 | 本FW最大のAIアンフレンドリー箇所の除去(DR-05) |
| `Loader` の `$dependencies` 手動宣言(Tax→Post順) | Kernel固定順序(Taxonomy→PostType→UserRole、FQCN昇順) | 非決定的ロード順・依存宣言漏れの根治(DR-04) |

上記に加え、モダン化監査(2026-06-13)による改稿を反映している:

- `register()` の再入ガード(static `$registered`)を撤廃し、「正確に1回」の保証をKernelの1巡走査へ一本化(1.2節契約3・1.6節)。`resetRuntimeCache()` はメモ化定義の破棄のみに縮小。
- `thumbnail()` のalt解決を `_wp_attachment_image_alt` メタ1段(コア同一セマンティクス)へ簡素化し、添付 `post_excerpt`/`post_title` フォールバックを廃止(5.3節)。レスポンシブ画像はWP標準手法に委ねる方針を明記(6節)。
- builtinタクソノミー紐付けのfalseの意味を「再試行前=ロード順起因(正常)/再試行後=真の失敗」に純化し、再試行後の失敗のみ `Log::warning` で警告(9.2節)。

---

## 16. 受け入れ基準

### チェックリスト

**定義・登録**

- [ ] `PostTypeDefinition::create('product')->label('Product')->hasArchive()` の `toArgs()` が `public=true`・en自動生成labels・`has_archive=true` を含み、未指定キーを含まない
- [ ] `labelLanguage(LabelLanguage::Ja)` で日本語labelsに切り替わり、`labels(['menu_name' => '...'])` がキー単位で上書きされる
- [ ] label/labels両方未指定の `toArgs()` が `EntityDefinitionException` を投げる
- [ ] スラッグ形式違反(`create('Invalid Slug!')`、21文字超のpost type、33文字超のtaxonomy)が `EntityDefinitionException` を投げる
- [ ] `builtin()` 定義に許可外セッターを呼ぶと即 `EntityDefinitionException`
- [ ] Kernelが収集リストの1巡走査で各エンティティクラスの `register()` を正確に1回だけ呼ぶ(統合テストで呼び出し回数を検証。`register()` 自身は重複防止を持たない)
- [ ] `supports('thumbnail')` を持つ複数のPostTypeを登録しても `post-thumbnails` テーマサポートが和集合でマージされる
- [ ] `TaxonomyDefinition::objectType(BlogPost::class)` が登録時に `'blog'` へ解決される。Taxonomy/PostType以外のクラスFQCNは `EntityDefinitionException`
- [ ] builtinタクソノミーのCPT紐付けで、初回falseになったスラッグのみが `init` 優先度11で再試行されて成功し(警告なし)、未登録投稿タイプ相手は再試行後に `Log::warning` が1回記録される
- [ ] `UserRole::register()` が2リクエスト目以降にDB書き込み(`add_role`/`add_cap`)を行わない(`get_role()`/`has_cap()` チェックによる冪等性。統合テストで `update_option` 呼び出し回数を検証)

**取得系(`?T` 統一)**

- [ ] `get()`/`findBySlug()`/`findByName()`/`findByEmail()`/`findByLogin()`/`first()`/`firstTerm()`/`current()` の戻り値型宣言がすべて `?WP_Post`/`?WP_Term`/`?WP_User` である(falseを返すメソッドが存在しない)
- [ ] `ProductPost::get($pagePost)`(タイプ不一致)・`get(0)`・`get(-1)` がnull
- [ ] `permalink()`/`archiveUrl()`/`url()`/`authorUrl()`/`thumbnailUrl()` がWP関数のfalse/WP_Errorをnullへ正規化する
- [ ] `thumbnail()` がアイキャッチなし+フォールバック未登録でnull、`tmswp/post/thumbnail_fallback` にThumbnailを返すフィルタを登録するとそれを返す。Thumbnail以外の返却値は無視される
- [ ] `thumbnail()` のaltが `_wp_attachment_image_alt` メタのみから解決される(メタが空なら `''`。添付の `post_excerpt`/`post_title` へはフォールバックしない)
- [ ] `parents()` が「ルート祖先→直近親」順で返り、深さ100超で `EntityDefinitionException`

**クエリ**

- [ ] `PostQuery::toArgs()` が7.3節のマッピング表どおりの引数を生成する(ユニットテストで全メソッドを網羅)
- [ ] `post_type`/`taxonomy`/`role` が `withArg()` でも上書きできない
- [ ] 既定値: PostQueryは `post_status=['publish']`+`ignore_sticky_posts=true`、TermQueryは `hide_empty=false`、UserQueryは `count_total=false`
- [ ] 不正な `direction`/`compare`/`type`/`limit(0)`/`paginate(0)` が `QueryException` を投げる
- [ ] `orderByMeta()` が名前付きmeta_query句(`tms_order_0`...)と連想配列orderbyを生成する
- [ ] `TermQuery::orderBy()` の2回目呼び出しが `QueryException`
- [ ] `paginate()` が `PageResult` を返し、`total`/`totalPages()`/`hasNext()` が統合テスト(wp-env、投稿25件×perPage 10)で正しい
- [ ] `whereTerm(BlogCategoryTax::class, ...)` がスラッグへ解決される
- [ ] `count()` が件数のみ取得し全件をロードしない(`fields=ids` + `posts_per_page=1` の引数生成をユニットで検証)

**書き込み**

- [ ] `create()` がpost_type/taxonomy/roleを強制注入し、`ID` キーを除去し、WP_Error時に `EntityPersistenceException`(`wpError` プロパティに元のWP_Errorを保持)を投げる

**静的解析・規約**

- [ ] 本層の全クラスが `declare(strict_types=1)`・全シグネチャ型宣言済みで、PHPStan level max(`szepeviktor/phpstan-wordpress`、baselineなし)を通過する
- [ ] `PageResult` のジェネリクス(`@template T`)により `ProductPost::query()->paginate(10)->items` が `list<WP_Post>` と推論される(PHPStanの型テスト `assertType` で検証)
- [ ] マジックメソッド(`__callStatic` 等)・`extract()` を一切使用していない(PHPStan/PHPCSルールで検出)

### 検証コマンド

```bash
composer lint      # php-cs-fixer (PSR-12 + strict_types)
composer analyse   # PHPStan level max + phpstan-wordpress + 型テスト(assertType)
composer test      # ユニット層: Definition/LabelGenerator/各Query::toArgs()/PageResult (WP非依存・Brain Monkey)
composer test:integration  # 統合層: wp-env + wp-phpunit。register往復・クエリ実行・paginate・UserRole登録のDB書込冪等性
composer qa        # 上記一括
```
