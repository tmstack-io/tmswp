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
| `Query\` | `PageResult`, `TermField`(enum), `TermOperator`(enum) |
| `Exception\` | `EntityDefinitionException`, `EntityPersistenceException`, `EntityDataException`, `QueryException` |

`Label\`・`Query\`(`PageResult`・`TermField`・`TermOperator`)・`Thumbnail`・例外4種は本文書が完全定義する(他文書は本文書の定義に従う)。クエリの比較演算子・キャスト型・並び順のenum(`Query\Compare`・`Query\CastType`・`Query\SortOrder`)は06文書が正準定義し、エンティティのクエリ3種もそれを受け取る。

## 依存文書

- **Kernel/Discovery設計書(02想定)**: `#[AsPostType]` / `#[AsTaxonomy]` / `#[AsUserRole]` 属性の定義と探索。本文書は「Kernelが各クラスの `register()` をいつ・どの順で呼ぶか」の契約(後述)のみを規定する。
- **meta/option設計書(04想定)**: `Meta\MetaBag` / `Meta\ObjectType` / `#[MetaSchema]`。本文書は各基底の `meta()` ファサードのシグネチャと委譲先のみを規定し、MetaBagの内部仕様には立ち入らない。
- **AI向け資料(rules/post-type.md)**: `tmswp/post/thumbnail_fallback` フィルタ(本文書が定義)へ app 側からフォールバック画像を差し込むレシピを持つ。

---

## 1. 全体像と共通規約

### 1.1 定義クラスの基本形

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class ProductPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')
            ->label('製品')
            ->labelLanguage(LabelLanguage::Ja)
            ->hasArchive();
    }
}
```

- 定義は `definition()` が返す Definition オブジェクトに集約する。旧iwf2bのstaticプロパティ宣言(`protected $post_type` / `$args`)の後継であり、型付き・IDE補完可能・PHPStan level max通過可能な形に置き換える。
- 属性(`#[AsPostType]` 等)はDiscoveryの探索マーカーであり、引数を持たない(全情報は `definition()` が持つ)。

### 1.2 Kernelとの登録契約

エンティティ層がKernel(02文書)に要求する契約。02文書はこれを満たすこと。

1. Kernelは、Discoveryが収集した各エンティティクラスの `register()`(`final public static`)を **WP `init` アクション**の中で、02文書の属性スケジュール表が定める優先度(**Taxonomy=9、PostType=10、UserRole=11**)で呼ぶ。`init` より前(`plugins_loaded` 等)での呼び出しは禁止。メタスキーマの登録(`MetaSchemaRegistry::registerEntity()`)はこれと別に、Kernel の boot の中で行う(02文書 §7。04文書 §4.9)。
2. 呼び出し順は **Taxonomy群 → PostType群 → UserRole群** とする。この順は優先度差(init 9 → 10 → 11)で保証し、同種内はFQCNの昇順(`strcmp`)で決定的にする。
3. Kernelは収集リストの1巡走査により、各クラスの `register()` を**正確に1回**呼ぶ。`register()` 側は重複防止(再入ガード)を持たない(1.6節)。

### 1.3 get系戻り値の `?T` 統一(全エンティティ共通)

旧iwf2bは `AbstractPost::get()` がnull、`AbstractTax::get()` がfalseを返す不統一があった。本FWでは以下に統一する。

| 種別 | 規約 |
|---|---|
| 単体取得(`get()` / `findBy*()` / `first()` / `firstTerm()` / `current()`) | 見つからない・スコープ不一致なら **null**(`?WP_Post` / `?WP_Term` / `?WP_User` / `?Thumbnail`) |
| URL取得(`permalink()` / `archiveUrl()` / `url()` / `authorUrl()` / `thumbnailUrl()`) | 解決不能なら **null**(`?string`)。WP関数の `false`(該当なし)はnull。`WP_Error` は1.7節のエラー方針に従う(本番の既定値はnull) |
| 複数取得(`get()` / `ids()` / `terms()` / `parents()` / `all()`) | 常に **配列**(`list<T>`)。該当なしは空配列。`WP_Error` は1.7節のエラー方針に従う(本番の既定値は空配列) |
| 判定(`is()` / `exists()`) | 常に **bool** |
| 書き込み(`create()`) | 成功時 **int**(ID)。失敗時は `EntityPersistenceException` をthrow(`WP_Error` は返さない) |

「該当なし」と「エラー」は区別する。WP関数が返す `false`・空配列・null(タームが付いていない、条件に合うレコードが無い等)は該当なしであり、上の表の正常な結果で返す。WP関数が返す `WP_Error` はエラーであり、黙って空配列・null・0に変えない(1.7節)。

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
1. $ref が `\` を含まない → $ref をスラッグ文字列としてそのまま返す(クラスの有無を調べない)
2. $ref が `\` を含む → クラスの参照とみなす:
   a. class_exists($ref) が false → EntityDefinitionException をthrow
     (メッセージ: class '{$ref}' does not exist)
   b. $ref が期待する基底(PostType または Taxonomy)のサブクラス → $ref::slug() を返す
   c. サブクラスでない → EntityDefinitionException をthrow
     (メッセージにはクラス名と期待基底名を含める)
```

WordPressのスラッグは `\` を含まないため、`\` を含む文字列をスラッグとして渡すことはない。存在しないクラスの指定(クラス名の誤り・autoloadの設定漏れ)は設定の誤り(00-overview §5.6)であり、スラッグとして黙って素通しせず、debugに関係なく例外にする。名前空間の無いクラスは `\` を含まないため参照できない(appのクラスは常に名前空間を持つ)。

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

/** エンティティ定義の不正(スラッグ形式違反・builtin制約違反・存在しないbuiltinのアダプタ先・登録失敗・FQCN解決失敗)。 */
class EntityDefinitionException extends LogicException
{
}

/** エンティティ書き込み(wp_insert_post / wp_insert_term / wp_insert_user)の失敗。 */
class EntityPersistenceException extends RuntimeException
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

/** 実行時のデータの不整合(親の循環による深さの超過等)。debug のときだけ投げる(下記)。 */
final class EntityDataException extends RuntimeException
{
}

/** クエリの実行時の失敗。エンティティ層では WordPress の関数が返した WP_Error を表し、debug のときだけ投げる(下記)。 */
class QueryException extends RuntimeException
{
}
```

継承元の `LogicException` / `RuntimeException` は `TMSWP\Core\Exception\` のFWの基底で、TMSWP共通のマーカーinterface `TmswpExceptionInterface` を実装する(`02-kernel.md` §9)。クラス名・名前空間・プロパティは本文書が正準。

エラー方針(`00-overview.md` §5.6)との対応:

- `EntityDefinitionException` は設定の誤り(定義の宣言の誤り・存在しないbuiltinのアダプタ先・登録の失敗・FQCN解決失敗)に限って使い、debugに関係なく投げる。実行時のデータには使わない。
- 実行時のデータの不整合(`parents()` の深さの超過。5.3節・9.2節)は実行時のデータ取得の失敗として扱う。debugでは `EntityDataException` を投げ、本番ではそのエンティティクラスのKernel(`Kernel::forClass(static::class)`)のロガーに `error` で記録して既定値(空配列)を返す。
- WordPressの関数が返す `WP_Error`(`get_terms()`・`get_the_terms()`・`get_term()`・`get_term_link()`。存在しないタクソノミーの `invalid_taxonomy` 等)は、実行時のデータ取得の失敗として扱う。debugでは `QueryException`(メッセージは `{関数名}() returned WP_Error {コード}: {メッセージ} ({エンティティクラス})`)を投げ、本番ではそのエンティティクラスのKernel(`Kernel::forClass()`)のロガーに `error`(メッセージは `tmswp.entity: {function}() returned WP_Error {code}: {message} ({class})`)で記録して既定値(複数取得は空配列、単体取得・URLはnull、`count()` は0)を返す。この扱いはコア内部のヘルパ(`Entity\WpErrorHandler`。`@internal`)の1か所に置き、各メソッドはそこへ委ねる。`WP_Error` を返し得ないWP関数(`WP_Query`・`WP_User_Query` 等)には適用しない。
- データが無いこと(投稿・ターム・ユーザーが無い、アイキャッチ未設定、タームが付いていない、条件に合うレコードが無い等)はエラーにせず、1.3節の正常な結果(null・空配列・false)で返す。
- クエリビルダの使い方の誤りは、debugに関係なくその場で投げる。値の誤り(`limit()` が1未満、`offset()` が負、`paginate()` のページ・1ページの件数が1未満、`PageResult` の不正な値)は `InvalidArgumentException`、指定どうしの矛盾(`noPaging()` と `paginate()` の併用、`limit()` の無い `offset()` 等。7.5節)は `InvalidQueryException`(`LogicException` を継承する。06文書 R4のModel層と同じ使い分け)。比較演算子・キャスト型・並び順・`whereTerm()` のfield/operatorはenumで受けるため、未知の値は型の段階で渡せない。
- `meta()` だけは対象のエンティティを前提とするため、無い対象・スコープ外の対象は呼び出しの誤りとして、debugに関係なく `InvalidArgumentException`(`TMSWP\Core\Exception\`。`07-support.md` §6)を投げる(5.3節)。存在しない対象へのメタの書き込みを防ぐためで、04文書の `ObjectType::resolveId()` が不正なIDと種別の違うオブジェクトを `InvalidArgumentException` にするのと同じ扱いである。

### 1.8 WordPressの関数に渡す引数の型

- WordPressの関数とクラス(`register_post_type`・`register_taxonomy`・`register_post_meta`・`register_term_meta`・`register_meta`・`wp_insert_post`・`wp_insert_term`・`wp_insert_user`・`get_terms`・`WP_Term_Query`・`WP_User_Query`・`WP_Query`)は直接呼ぶ。関数名の文字列や可変のクラス名で呼んで、PHPStanの型検査を避けない。
- WordPressに渡す配列(各 `toArgs()` の戻り値・各 `create()` の引数・メタの登録の引数)のPHPDocは、php-stubs/wordpress-stubsの該当する `@phpstan-param` と同じ配列の形にする。stubsが要素の型を書いていない `array` だけは、要素の型を書いて絞る(PHPStan level maxは要素の型の無い `array` を許さない)。stubsの型が配列の形を持たない `WP_Query` の引数は `array<string, mixed>` とする。
- 形の合わない値は、渡す側で明示的に変換する(LabelGeneratorが返すnullのラベルを除いて渡す等。4.3節)。
- エスケープハッチ(定義の `args()`・クエリの `withArgs()`)も同じ配列の形を受け取るため、専用のセッターが無い引数の型もPHPStanが呼び出し元で検査する。stubsの配列の形に無いキー(プラグインが読む独自の引数等)は、配列リテラルに書けば型検査を通る(PHPStanは配列の形に無いキーを拒まない)。

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
    /** @return array<string, string|null> register_post_type の labels 引数 */
    public static function forPostType(string $singular, string $plural, LabelLanguage $language, bool $hierarchical): array;

    /** @return array<string, string|null> register_taxonomy の labels 引数 */
    public static function forTaxonomy(string $singular, string $plural, LabelLanguage $language, bool $hierarchical): array;
}
```

**振る舞い仕様**:

- テンプレート文字列はFWに内蔵し、`sprintf` で `%1$s` に単数形、`%2$s` に複数形を展開して返す。**gettext(`__()` 等)は通さない**(翻訳ファイル非依存・決定的。DR-17)。テキストドメイン `tmswp` はFWの他のUI文字列用であり、ラベル生成には使わない。
- 出すキーは、WordPress コアの `get_post_type_labels()` / `get_taxonomy_labels()` が組み立てるラベルのキーと過不足なく一致させる(`WP_Post_Type::get_default_labels()`・`WP_Taxonomy::get_default_labels()` のキーに、`get_*_labels()` が加える `menu_name`・`template_name` と、`_get_custom_object_labels()` が加える `name_admin_bar`・`archives` を足したもの)。キーごとに単数形と複数形のどちらを使うかは、コアの既定の英語ラベルの使い分けに合わせる。
- en の文言は WordPress 7.0 コアの既定の英語ラベルに揃え、オブジェクト名(post/posts、tag/tags 等)の部分に単数形・複数形のラベルをそのまま入れる。大文字・小文字は変えず、コアが名詞の前に置く不定冠詞(a/an)は除く(例: `A link to %1$s.`)。ja の文言は FW 独自のテンプレートとする。
- コアが階層の有無で既定を null にするキー(下表の「階層の有無」列)は、コアと同じく null を返す。階層の有無は `$hierarchical`(定義の `hierarchical` 引数の真偽)で受け取る。
- 複数形の省略は定義側(`label()`)の規則で扱い、Generator は常に単数形と複数形の両方を受け取る。`LabelLanguage::Ja` では複数形を省略でき、省略時は単数形を複数形にも使う(日本語に複数形が無いため)。`LabelLanguage::En` で複数形が無ければ、labels を組み立てる時点で `EntityDefinitionException`(4.3節)。
- 言語は、定義側で `labelLanguage()` を指定すればその言語、指定しなければ Kernel の既定(`KernelConfig` の `labelLanguage`。02-kernel §2)を使う。Kernel の既定の既定値は `LabelLanguage::En`。日本語サイトでは `KernelConfig::theme(..., labelLanguage: LabelLanguage::Ja)` でまとめて指定し、個々の定義では `labelLanguage()` を省略できる。定義の指定は Kernel の既定より優先する。
- 定義は、`labelLanguage()` を指定していないこと(null)と Kernel の既定を区別して持ち、labels を組み立てる時点(`toArgs(LabelLanguage $defaultLabelLanguage)`。PostTypeInitializer・TaxonomyInitializer が `register($kernel)` 経由で Kernel の既定を渡す)に言語を決める。複数形の規則(En では必須、Ja では省略可)と空文字の拒否は、決まった言語に対して適用する。

**投稿タイプ用テンプレート(全キー確定)**(`%1$s` = 単数形、`%2$s` = 複数形):

| キー | ja | en | 階層の有無 |
|---|---|---|---|
| name | `%2$s` | `%2$s` |  |
| singular_name | `%1$s` | `%1$s` |  |
| add_new | `新規追加` | `Add` |  |
| add_new_item | `新規%1$sを追加` | `Add %1$s` |  |
| edit_item | `%1$sを編集` | `Edit %1$s` |  |
| new_item | `新規%1$s` | `New %1$s` |  |
| view_item | `%1$sを表示` | `View %1$s` |  |
| view_items | `%2$s一覧を表示` | `View %2$s` |  |
| search_items | `%2$sを検索` | `Search %2$s` |  |
| not_found | `%2$sが見つかりませんでした` | `No %2$s found.` |  |
| not_found_in_trash | `ゴミ箱内に%2$sが見つかりませんでした` | `No %2$s found in Trash.` |  |
| parent_item_colon | `親%1$s:` | `Parent %1$s:` | 非階層では null |
| all_items | `%2$s一覧` | `All %2$s` |  |
| archives | `%1$sアーカイブ` | `%1$s Archives` |  |
| attributes | `%1$sの属性` | `%1$s Attributes` |  |
| insert_into_item | `%1$sに挿入` | `Insert into %1$s` |  |
| uploaded_to_this_item | `この%1$sへのアップロード` | `Uploaded to this %1$s` |  |
| featured_image | `アイキャッチ画像` | `Featured image` |  |
| set_featured_image | `アイキャッチ画像を設定` | `Set featured image` |  |
| remove_featured_image | `アイキャッチ画像を削除` | `Remove featured image` |  |
| use_featured_image | `アイキャッチ画像として使用` | `Use as featured image` |  |
| filter_items_list | `%2$s一覧を絞り込む` | `Filter %2$s list` |  |
| filter_by_date | `日付で絞り込む` | `Filter by date` |  |
| items_list_navigation | `%2$s一覧ナビゲーション` | `%2$s list navigation` |  |
| items_list | `%2$s一覧` | `%2$s list` |  |
| item_published | `%1$sを公開しました` | `%1$s published.` |  |
| item_published_privately | `%1$sを非公開で公開しました` | `%1$s published privately.` |  |
| item_reverted_to_draft | `%1$sを下書きに戻しました` | `%1$s reverted to draft.` |  |
| item_trashed | `%1$sをゴミ箱に移動しました` | `%1$s trashed.` |  |
| item_scheduled | `%1$sの公開を予約しました` | `%1$s scheduled.` |  |
| item_updated | `%1$sを更新しました` | `%1$s updated.` |  |
| item_link | `%1$sリンク` | `%1$s Link` |  |
| item_link_description | `%1$sへのリンク` | `A link to %1$s.` |  |
| menu_name | `%2$s` | `%2$s` |  |
| name_admin_bar | `%1$s` | `%1$s` |  |
| template_name | `%1$sの個別ページ` | `Single item: %1$s` |  |

**タクソノミー用テンプレート(全キー確定)**:

| キー | ja | en | 階層の有無 |
|---|---|---|---|
| name | `%2$s` | `%2$s` |  |
| singular_name | `%1$s` | `%1$s` |  |
| search_items | `%2$sを検索` | `Search %2$s` |  |
| popular_items | `よく使われている%2$s` | `Popular %2$s` | 階層では null |
| all_items | `%2$s一覧` | `All %2$s` |  |
| parent_item | `親%1$s` | `Parent %1$s` | 非階層では null |
| parent_item_colon | `親%1$s:` | `Parent %1$s:` | 非階層では null |
| name_field_description | `サイト上に表示される名前です。` | `The name is how it appears on your site.` |  |
| slug_field_description | `「スラッグ」はURLに適した形式の名前です。通常はすべて小文字で、半角英数字とハイフンのみを含みます。` | `The &#8220;slug&#8221; is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.` |  |
| parent_field_description | `親タームを指定して階層を作ります。たとえば「ジャズ」というタームは「ビバップ」や「ビッグバンド」の親になります。` | `Assign a parent term to create a hierarchy. The term Jazz, for example, would be the parent of Bebop and Big Band.` | 非階層では null |
| desc_field_description | `説明は標準では目立ちませんが、テーマによっては表示されます。` | `The description is not prominent by default; however, some themes may show it.` |  |
| edit_item | `%1$sを編集` | `Edit %1$s` |  |
| view_item | `%1$sを表示` | `View %1$s` |  |
| update_item | `%1$sを更新` | `Update %1$s` |  |
| add_new_item | `新規%1$sを追加` | `Add %1$s` |  |
| new_item_name | `新規%1$s名` | `New %1$s Name` |  |
| separate_items_with_commas | `複数の%2$sはコンマで区切ってください` | `Separate %2$s with commas` | 階層では null |
| add_or_remove_items | `%2$sの追加または削除` | `Add or remove %2$s` | 階層では null |
| choose_from_most_used | `よく使われている%2$sから選択` | `Choose from the most used %2$s` | 階層では null |
| not_found | `%2$sが見つかりませんでした` | `No %2$s found.` |  |
| no_terms | `%2$sなし` | `No %2$s` |  |
| filter_by_item | `%1$sで絞り込む` | `Filter by %1$s` | 非階層では null |
| items_list_navigation | `%2$s一覧ナビゲーション` | `%2$s list navigation` |  |
| items_list | `%2$s一覧` | `%2$s list` |  |
| most_used | `よく使うもの` | `Most Used` |  |
| back_to_items | `← %2$s一覧へ戻る` | `← Go to %2$s` |  |
| item_link | `%1$sリンク` | `%1$s Link` |  |
| item_link_description | `%1$sへのリンク` | `A link to %1$s.` |  |
| menu_name | `%2$s` | `%2$s` |  |
| name_admin_bar | `%1$s` | `%1$s` |  |
| archives | `%2$s一覧` | `All %2$s` |  |
| template_name | `%1$sアーカイブ` | `%1$s Archives` |  |

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
     *
     * @throws \TMSWP\Core\Exception\InvalidArgumentException total < 0、perPage < 1、currentPage < 1 の場合
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
    );

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

`totalPages()` は `paginate_links(['total' => $page->totalPages(), 'current' => $page->currentPage])` にそのまま渡せる値とする。コンストラクタは `total` が負、`perPage`・`currentPage` が1未満の値を `InvalidArgumentException` で拒否する(`totalPages()` のゼロ除算を防ぐ)。

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
    /** null は label() 未指定 */
    private ?string $singularLabel = null;

    /** null は複数形の省略(LabelLanguage::Ja でのみ許す) */
    private ?string $pluralLabel = null;

    /** @var array<string, string> labels()による部分上書き */
    private array $labelOverrides = [];

    /** null は labelLanguage() 未指定(Kernel の既定 KernelConfig::$labelLanguage を使う) */
    private ?LabelLanguage $labelLanguage = null;

    /** @var RegisterPostTypeArgs セッターが構築する register_post_type 引数(stubs の register_post_type() の $args と同じ配列の形。1.8節) */
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
    /** 単数形と複数形。複数形は LabelLanguage::En では必須、LabelLanguage::Ja では省略可(省略時は単数形を使う) */
    public function label(string $singular, ?string $plural = null): self;
    /** @param array<string, string> $labels 自動生成結果へのキー単位上書き */
    public function labels(array $labels): self;
    /** 指定しなければ Kernel の既定(KernelConfig の labelLanguage)を使う */
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
    /**
     * エスケープハッチ: 専用のセッターが無いregister_post_type引数を設定する(キー単位で上書き)
     * @param RegisterPostTypeArgs $args
     */
    public function args(array $args): self;

    // ---- 読み取り(FW内部・PostType::register()用) ----
    /** @return lowercase-string&non-empty-string register_post_type() の $post_type と同じ型 */
    public function slug(): string;
    public function isBuiltin(): bool;
    public function blockEditorPreference(): ?bool;
    /** @return list<string> supports未指定時は [] */
    public function supportsList(): array;
    /**
     * @param LabelLanguage $defaultLabelLanguage labelLanguage() 未指定時のラベル言語(Kernel の既定)
     * @return RegisterPostTypeArgs register_post_type の $args(builtin時はEntityDefinitionException)
     */
    public function toArgs(LabelLanguage $defaultLabelLanguage): array;
}
```

### 4.3 振る舞い仕様

**`create(string $slug)`**:

- スラッグ検証: `/^[a-z0-9_\-]{1,20}$/` に一致しない場合 `EntityDefinitionException`(WPの20文字制限を事前検査)。
- FW既定値として `public => true` を `$args` に設定する(WP既定のfalseから変更。受託サイトCPTの実態に合わせた唯一の既定値変更であり、rules/に明記する)。それ以外の引数はWP既定に委ね、**呼ばれなかったセッターのキーは `toArgs()` に含めない**。

**`builtin(string $slug)`**:

- スラッグ検証は `create()` と同一(ただし長さ上限は適用しない。既存タイプ名をそのまま受ける)。
- builtinモードで呼び出し可能なメソッドは `supports()` と `blockEditor()` のみ。それ以外のセッターを呼んだ時点で `EntityDefinitionException` をthrow(設定が無視される事故を防ぐ。旧iwf2bはbuiltin時にargsが黙って捨てられていた)。
- アダプタ先の投稿タイプは、登録(`init` 優先度10)の時点で登録済みでなければならない。無ければ `register()` が `EntityDefinitionException` を投げる(5.3節)。

**各セッター**: snake_caseのWP引数キーへ1:1で書き込む(`excludeFromSearch` → `exclude_from_search` 等)。同一セッターの再呼び出しは上書き。`capabilityType()` は `capability_type` と `map_meta_cap` の2キーを設定する。`args()` は渡した配列のキーを同じ `$args` へキー単位で上書きする。呼び出し順で上書きし合うのは、セッターが `$args` に直接書くキー(`public` 等。`public` の既定値も上書きできる)だけである。`labels`・`label`・`supports`・`taxonomies` は、`label()`/`labels()`・`supports()`・`taxonomies()` が別に持つ値から `toArgs()` が組み立てる(下記の手順2〜4)。そのため、`args()` で渡しても、`labels`・`label` は常に、`supports` は `supports()` を呼んだとき、`taxonomies` は `taxonomies()` で1つ以上指定したとき、`toArgs()` の組み立てた値が優先する(呼び出し順に関係しない)。

**`toArgs()`** の構築手順(決定的):

1. builtinなら `EntityDefinitionException`。
2. labels決定(言語 `$labelLanguage` は、`labelLanguage()` の指定があればそれ、無ければ引数 `$defaultLabelLanguage`):
   - `label()` が呼ばれておらず(単数形が null)、かつ `labelOverrides` に `name` がない → `EntityDefinitionException`(「labelまたはlabels(name)が必須」)。
   - `label()` が呼ばれている → 単数形・複数形とも、空文字は言語・`labels()` の有無に関係なく `EntityDefinitionException` にする(複数形の省略は null、つまり第2引数を渡さないことで表すため、空文字は常に設定の誤り。メッセージにスラッグを含め、複数形では省略するなら第2引数を渡さない旨も含める)。そのうえで複数形を決める。複数形が渡されていればそれを使う。省略されていれば、`LabelLanguage::Ja` では単数形を使い(日本語に複数形が無いための規則)、`LabelLanguage::En` では `EntityDefinitionException` を投げる(設定の誤りのため debug に関係なく常に投げる。メッセージにスラッグと `label('<単数形>', '<plural>')` の書き方、および `labelLanguage()` か `KernelConfig` の `labelLanguage` で Ja を選べることを含める)。
   - そのうえで `LabelGenerator::forPostType($singular, $plural, $labelLanguage, $hierarchical)` で生成し(`$hierarchical` は `$args['hierarchical']` の真偽。未指定は false)、`labelOverrides` をキー単位で上書きマージ(`array_merge(生成, 上書き)`)。
   - `label()` が呼ばれておらず `labelOverrides` のみ → そのまま `labels` に採用。
   - 結果から値が null のキー(LabelGeneratorがコアと同じく階層の有無でnullにするキー。2.2節)を除いて `$args['labels']` に設定し、`$args['label']` には `labels['name']` を設定。nullのキーを除くのは、stubsの `labels` が `string[]` のため(1.8節)。WordPressは無いキーを、同じ階層の有無に応じたコアの既定(同じnull)で補う(`_get_custom_object_labels()`)ため、登録される labels は変わらない。
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

    /** @internal Kernelがinit(優先度10)で、このクラスを探索した Kernel を渡して正確に1回呼ぶ(1.2節契約3)。重複防止は持たない。 */
    final public static function register(\TMSWP\Core\Kernel\Kernel $kernel): void;

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
    /**
     * @param array{ID?: int, post_author?: int, post_title?: string, ..., meta_input?: array<string, mixed>, page_template?: string} $postarr
     *        stubs の wp_insert_post() の $postarr と同じ配列の形(1.8節。全キーは実装の PHPDoc)。post_type は static::slug() に、ID は除く。
     *        成功時は投稿ID。失敗時EntityPersistenceException
     */
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
2. **builtinの場合**: `post_type_exists($def->slug())` がfalseなら `EntityDefinitionException`(`builtin post type does not exist: {slug}`)。存在しないアダプタ先(スラッグの誤り、`init` 優先度10より後に登録される投稿タイプ等)は設定の誤りであり、debugに関係なく投げる(00-overview §5.6。UserRoleの存在しないbuiltinロール(12.2節)・Taxonomyの存在しないbuiltinタクソノミー(9.2節)と同じ扱い)。存在すれば、`supportsList()` が非空なら各featureを `add_post_type_support($def->slug(), $feature)`。手順4へ。`register_post_type` は呼ばない。
3. **非builtinの場合**:
   - `supportsList()` に `'thumbnail'` を含む場合、テーマサポートをマージ登録する:
     - `get_theme_support('post-thumbnails')` が `true`(全タイプ有効) → 何もしない
     - 配列(対象タイプ限定) → 既存配列とこのスラッグの和集合で `add_theme_support('post-thumbnails', $merged)`
     - 未登録 → `add_theme_support('post-thumbnails', [$def->slug()])`
   - `register_post_type($def->slug(), $def->toArgs($kernel->config()->labelLanguage))` を実行。戻り値が `WP_Error` なら `EntityDefinitionException`(エラーメッセージを含める)。
4. `blockEditorPreference()` が非null → `add_filter('use_block_editor_for_post_type', $callback, 10, 2)`。callbackは「`$postType === $def->slug()` なら希望値を返し、それ以外は受け取った値を素通しする」クロージャ。他のプラグインのフィルタがbool以外(null等)を返すことがあるため、callbackの引数は型で縛らない(`mixed`)。対象外の投稿タイプの値は受け取ったまま返し、その扱いはWordPress(戻り値の真偽で判定する)に任せる。

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

**`meta()`**: `static::get($post)` でID正規化し(null時は対象ID=0のMetaBagを返すのではなく、呼び出しの誤りとして `InvalidArgumentException` をthrowする。存在しない投稿へのメタ書き込み事故防止。1.7節)、04文書のMetaBagファクトリへ `ObjectType::Post`・投稿ID・`static::class` を渡して生成する。生成APIの正確な形は04文書の定義に従う。

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
   - フォールバック(本文画像の抽出等)が必要な場合は app 側がこのフィルタに差し込む(rules/post-type.md)。コアは正規表現抽出・`getimagesize` による同期HTTP I/Oを一切行わない。

**`thumbnailUrl()`**: `static::thumbnail($post, $size)?->url`。ダミー画像は呼び出し側の `?? $dummyUrl` で表現する(旧 `dummy_image` 引数は廃止)。

**`terms()`**: `get($post)` → null時 `[]`。taxonomyを1.5節規約で解決し `get_the_terms($post, $taxonomy)`(WPのオブジェクトタームキャッシュを内包するため旧FWの手動キャッシュ管理は廃止)。`false`(タームが付いていない)は `[]`。`WP_Error`(存在しないタクソノミー等)は1.7節のエラー方針に従う(debugは `QueryException`、本番はKernelのロガーに `error` で記録して `[]`)。戻りは `array_values()` で `list<\WP_Term>` に正規化。

**`firstTerm()`**: `terms()` の先頭、空ならnull。

**`parents()`**:

- `get($post)` → null時 `[]`。
- `post_parent` を `static::get()` でたどる(タイプ不一致・不存在で打ち切り)。無限ループ防止に深さ上限100を設ける。
- 深さ100の超過(`post_parent` の循環等)は実行時のデータの不整合であり、実行時のデータ取得の失敗(00-overview §5.6)として扱う。debugでは `EntityDataException` を投げ、本番ではこのクラスのKernel(`Kernel::forClass(static::class)`)のロガーに `error` で記録して空配列を返す(1.7節)。
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

    /**
     * width/height属性付きimgタグ用の補助。width/heightがnullの場合は属性を出力しない。
     *
     * @param array<string, mixed> $attributes 追加の属性(Support\Html::attributes() の値の規則に従う)
     *
     * @throws \TMSWP\Core\Exception\InvalidArgumentException 属性名が不正、またはURLのプロトコルが許可されない場合
     */
    public function img(array $attributes = []): string;
}
```

- `url` は常に非空(空URLのThumbnailは生成禁止。コンストラクタで空文字なら `\InvalidArgumentException`)。
- `img()` は `Support\Html::attributes()`(07-support §4)で `<img src="..." alt="..." ... width="..." height="...">` を生成し、エスケープ済み文字列を返す。独自のエスケープは持たない。`$attributes` で `class` 等を追加でき、値の扱いは `Html::attributes()` の規則に従う(boolのtrueは値なしの属性、falseとnullは出力しない。`class` は配列・条件マップも受ける)。`src` / `alt` / `width` / `height` を `$attributes` で上書きすることは不可(属性名の大文字小文字に関係なく無視する)。
- `src` はURL属性として `esc_url()` を通る。許可されないプロトコル(`javascript:` 等)のURLは `Html::attributes()` が `InvalidArgumentException` にする。フォールバックのフィルタ(5.3節)が返した `Thumbnail` も同じく `img()` で検査される。
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

use TMSWP\Core\Query\CastType;
use TMSWP\Core\Query\Compare;
use TMSWP\Core\Query\PageResult;
use TMSWP\Core\Query\SortOrder;
use TMSWP\Core\Query\TermField;
use TMSWP\Core\Query\TermOperator;

final class PostQuery
{
    /** @var array<string, mixed> ビルダが構築するWP_Query引数 */
    private array $args = [];

    /** @var list<array<string, mixed>> meta_query句(relation AND) */
    private array $metaClauses = [];

    /** @var list<array<int|string, string|array<string, string>>> orderByMeta()の並び替えだけに使う句のグループ(7.7節) */
    private array $orderMetaGroups = [];

    /** @var list<array<string, mixed>> tax_query句(relation AND) */
    private array $taxClauses = [];

    /** @var array<string, string> orderby指定(field => ASC|DESC) */
    private array $orderBy = [];

    /** @var array<string, mixed> withArgs()による生引数(最後にマージ) */
    private array $rawArgs = [];

    /** @var array<string, true> 空のIN指定を受けた条件。1つでもあれば結果は0件と確定する(7.6節) */
    private array $emptyIn = [];

    /** @var list<int>|null idIn()のID(nullはidIn()を呼んでいない)。idNotIn()のIDは差し引いて渡す(7.6節) */
    private ?array $idIn = null;
    /** @var list<int> */
    private array $idNotIn = [];

    private ?int $limit = null;
    private ?int $offset = null;
    private bool $noPaging = false;

    private function __construct(
        private readonly string $postType,
    ) {
    }

    /**
     * クエリは必ずエンティティクラスから作る(スラッグを直接受けるファクトリは持たない。3クエリで揃え、WP_Errorの記録先の
     * Kernelをエンティティクラスで決めるため。1.7節)。PostTypeクラスを定義していない
     * 投稿タイプ(コアの post・他プラグインの投稿タイプ)は、PostTypeDefinition::builtin() のアダプタ(4.2節)を定義して使う。
     *
     * @param class-string<PostType> $postTypeClass
     */
    public static function of(string $postTypeClass): self;

    // ---- スコープ ----
    public function status(string ...$status): self;
    public function anyStatus(): self;
    public function search(string $keyword): self;
    public function author(int|\WP_User $author): self;
    public function parent(int|\WP_Post $parent): self;
    public function slugIn(string ...$slugs): self;
    public function idIn(int ...$ids): self;
    public function idNotIn(int ...$ids): self;

    public function whereMeta(string $key, mixed $value, Compare $compare = Compare::Eq, CastType $type = CastType::Char): self;
    public function whereMetaExists(string $key): self;
    public function whereMetaNotExists(string $key): self;

    /**
     * @param string                       $taxonomy スラッグまたはTaxonomyサブクラスFQCN
     * @param int|string|list<int|string>  $terms
     */
    public function whereTerm(string $taxonomy, int|string|array $terms, TermField $field = TermField::Slug, TermOperator $operator = TermOperator::In): self;

    /** 固定ページテンプレートで絞り込む(_wp_page_template疑似キー。旧FWの'template'キー後継)。 */
    public function template(string $templateFile): self;

    /** アイキャッチ有無で絞り込む(_thumbnail_id疑似キー)。 */
    public function hasThumbnail(bool $has = true): self;

    // ---- 並び順 ----
    public function orderBy(string $field, SortOrder $direction = SortOrder::Asc): self;
    public function orderByDesc(string $field): self;
    public function orderByMeta(string $metaKey, SortOrder $direction = SortOrder::Asc, CastType $type = CastType::Char): self;

    // ---- 件数・ページング(7.5節) ----
    public function limit(int $limit): self;
    public function offset(int $offset): self;
    public function noPaging(): self;
    public function ignoreSticky(bool $ignore = true): self;

    /**
     * エスケープハッチ。キー単位で上書き。post_typeのみ上書き不可(常に強制再注入)。
     * @param array<string, mixed> $args WP_Query::__construct() の $query(stubs の型は string|array で配列の形を持たない。1.8節)
     */
    public function withArgs(array $args): self;

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

`whereTerm()` のfieldとoperatorは、`Query\` のenumで受け取る(未知の値は型の段階で渡せない。WP_Tax_Queryは未知のoperatorの句を無視して全件を返すため、文字列では受けない)。

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

/** tax_query の句の field(terms の値をタームのどの列と照合するか) */
enum TermField: string
{
    case Slug = 'slug';
    case TermId = 'term_id';
    case Name = 'name';
    case TermTaxonomyId = 'term_taxonomy_id';
}

/** tax_query の句の operator。EXISTS/NOT EXISTS は含まない */
enum TermOperator: string
{
    case In = 'IN';
    case NotIn = 'NOT IN';
    case And = 'AND';
}
```

### 7.3 引数変換仕様(決定的マッピング)

| メソッド | WP_Query引数 |
|---|---|
| (常時) | `post_type` = スコープのスラッグ(最終段で強制注入。`withArgs` でも上書き不可) |
| (既定) | `post_status` = `['publish']`、`ignore_sticky_posts` = `true`、`posts_per_page` = `-1`(件数を指定しない場合は全件。サイトの設定 `posts_per_page` に依存させない。7.5節) |
| `status(...$s)` | `post_status` = `$s`(空は0件。7.6節) |
| `anyStatus()` | `post_status` = `'any'` |
| `search($k)` | `s` = `$k` |
| `author($a)` | `author` = ID(WP_Userは `->ID`) |
| `parent($p)` | `post_parent` = ID |
| `slugIn(...$s)` | `post_name__in` = `$s`(空は0件) |
| `idIn(...$ids)` | `post__in` = `$ids` から `idNotIn()` のIDを差し引いたID(空は0件。7.6節) |
| `idNotIn(...$ids)` | `idIn()` が無ければ `post__not_in` = `$ids`、あれば `post__in` から差し引いて `post__not_in` は渡さない(空は条件なし) |
| `whereMeta($key, $value, $compare, $type)` | `meta_query` 句 `['key' => $key, 'value' => $value, 'compare' => $compare->value, 'type' => $type->value]` を追加(relation AND)。`Compare::In` と空の配列は0件、`Compare::NotIn` と空の配列は句を足さない |
| `whereMetaExists($key)` / `whereMetaNotExists($key)` | `meta_query` 句 `['key' => $key, 'compare' => 'EXISTS'\|'NOT EXISTS']` |
| `whereTerm($tax, $terms, $field, $operator)` | `tax_query` 句 `['taxonomy' => 解決済スラッグ, 'field' => $field->value, 'terms' => (array) $terms, 'operator' => $operator->value]`(relation AND)。空の `$terms` は、`TermOperator::In`・`And` では0件、`NotIn` では句を足さない |
| `template($file)` | `meta_query` 句 `['key' => '_wp_page_template', 'value' => $file, 'compare' => '=', 'type' => 'CHAR']` |
| `hasThumbnail($has)` | `meta_query` 句 `['key' => '_thumbnail_id', 'compare' => $has ? 'EXISTS' : 'NOT EXISTS']` |
| `orderBy($f, $d)` | `orderby[$f] = $d->value`(複数呼び出しで累積。同一fieldは上書き) |
| `orderByMeta($key, $d, $type)` | 並び替え専用の句のグループ(7.7節)を `meta_query` に追加し、`orderby['tms_order_{n}'] = $d->value`。`{n}` は呼び出し順の0始まり連番 |
| `limit($n)` | `posts_per_page` = `$n`(`$n < 1` は `InvalidArgumentException`) |
| `offset($n)` | `offset` = `$n`(負値は `InvalidArgumentException`) |
| `noPaging()` | `nopaging` = `true`(`posts_per_page` は既定の `-1`) |
| `ignoreSticky($b)` | `ignore_sticky_posts` = `$b` |

**値の検証**: 比較演算子・キャスト型・並び順は06文書の `Compare`・`CastType`・`SortOrder`、`whereTerm()` のfield・operatorは `TermField`・`TermOperator` で受けるため、未知の値は型の段階で渡せない(EXISTS/NOT EXISTSは `whereMetaExists()`/`whereMetaNotExists()` に一本化し、`Compare` に含めない)。件数の値と指定どうしの矛盾は7.5節で検証する。

**`toArgs()` のマージ順(決定的)**: (1)既定値(`posts_per_page` は `limit()` の値、無ければ `-1`) → (2)セッター由来 `$args` → (3)`offset`・`nopaging`・`post__in`/`post__not_in`(7.6節の差し引きの後) → (4)`meta_query`(無名句+並び替え専用の句のグループ、`relation => 'AND'`。句が1つでもrelationを付ける) → (5)`tax_query`(同上) → (6)`orderby` / 対応する `order` 削除(連想配列形式を使う) → (7)`rawArgs`(`withArgs`) → (8)`post_type` 強制再注入 → (9)結果が0件と確定している場合(7.6節)は `post__in` = `[0]`・`ignore_sticky_posts` = `true` を強制注入。`limit()` の無い `offset()` は `toArgs()` が `InvalidQueryException` を投げる(7.5節)。

### 7.4 終端メソッド仕様

| メソッド | 追加引数 | 振る舞い |
|---|---|---|
| `get()` | `no_found_rows=true` | `(new \WP_Query($args))->posts` を `list<\WP_Post>` で返す(`fields` 指定があっても無視し、必ずWP_Postで返すため `fields` キーは強制unset)。件数を指定しなければ全件 |
| `first()` | `posts_per_page=1`, `no_found_rows=true`、`nopaging` を除く | 先頭1件 or null。`limit()`・`noPaging()` に関係なく常に1件だけ取得し、全件を読み込まない。`offset()` は効く |
| `ids()` | `fields='ids'`, `no_found_rows=true` | `list<int>` |
| `count()` | `fields='ids'`, `posts_per_page=1`, `paged=1`, `no_found_rows=false`、`offset`・`nopaging` を除く | `(int) $query->found_posts`。条件に合う全体の件数で、`limit()`・`offset()`・`noPaging()` は無視する |
| `exists()` | — | `first() !== null` と等価 |
| `url()` | — | `first()` のパーマリンク。null連鎖 |
| `paginate($perPage, $page)` | `posts_per_page=$perPage`, `paged=$page`, `no_found_rows=false` | `$perPage < 1`・`$page < 1` は `InvalidArgumentException`。`noPaging()`・`offset()`・`limit()` との併用は `InvalidQueryException`。`$page` がnullなら `max(1, (int) get_query_var('paged') ?: (int) get_query_var('page') ?: 1)` で解決。`PageResult<\WP_Post>` を返す(`total` = `found_posts`、`items` = `posts`) |
| `wpQuery()` | — | `new \WP_Query(toArgs())` をそのまま返す(`no_found_rows` を設定しない=WP標準ページネーション可能) |

終端メソッドが追加する引数はビルダ状態を変更しない(`toArgs()` の結果をローカルに複製して使う)。

結果が0件と確定している場合(7.6節)、すべての終端メソッドは `WP_Query` を実行せずに空の結果を返す。`get()`・`first()`・`ids()`・`count()`・`exists()`・`url()` は `[]`・null・`[]`・0・false・null、`paginate()` は `new PageResult([], 0, $perPage, $page ?? 1)`(`$page` を省略しても現在のページを解決せず(`get_query_var()` を呼ばず)、1ページ目にする)、`wpQuery()` は検索を実行していない空の `WP_Query` を返す。引数の検証(`paginate()` の値と矛盾する指定、`limit()` の無い `offset()`)は、この場合も先に行う。

`wpQuery()` が0件と確定した場合に返す `WP_Query` は、引数なしで作る(`WP_Query::__construct()` は引数が空なら検索しない)。そのうえで `posts` = `[]`・`post_count` = 0・`found_posts` = 0・`max_num_pages` = 0 を設定し(`have_posts()` はfalse)、`query`・`query_vars` に `toArgs()`((9)の `post__in = [0]` を含む)を入れる。`get_posts()` で検索をやり直しても0件になる。

### 7.5 件数・ページングの規則(3クエリ共通)

PostQuery・TermQuery・UserQueryに共通する件数・ページングの規則。WordPressの既定(サイトの設定による件数の上限、件数の上限が無いときの `offset` の無視等)に暗黙に依存させない。

- **件数の既定**: 件数を指定しない `get()`・`ids()` は全件を返す。PostQueryは `posts_per_page = -1` を明示し(WP_Queryは指定が無いとサイトの設定 `posts_per_page`(既定10件)で切る)、TermQuery・UserQueryは `number` を渡さない(`get_terms()`・`WP_User_Query` の既定は全件)。3クエリとも同じく全件になる。
- **値の検証**: `limit($n)` は `$n < 1`、`offset($n)` は `$n < 0`、`paginate($perPage, $page)` は `$perPage < 1`・`$page < 1` を `InvalidArgumentException` にする(UserQueryの負のoffsetによるSQLエラー、`PageResult` のゼロ除算を防ぐ)。全件は `limit()` を呼ばないこと(PostQueryは `noPaging()` でも明示できる)で表し、`limit(-1)`・`limit(0)` は受け付けない。`PageResult` のコンストラクタも同じ値を拒否する(3節)。
- **矛盾する指定**: 次は `InvalidQueryException` にする。
  - `noPaging()` と `limit()`・`offset()`・`paginate()` の併用(PostQuery。呼ぶ順に関係なく)
  - `offset()` と `paginate()` の併用(PostQuery・UserQuery。WP_Queryは `offset` があると `paged` を無視する)
  - `limit()` と `paginate()` の併用(PostQuery・UserQuery。どちらも件数の指定であり、片方を黙って置き換えない)
  - `limit()` の無い `offset()`(3クエリ。WordPressは件数の上限が無いと `offset` を無視する)。`toArgs()` と、それを使う `get()`・`ids()`・`wp*Query()` で投げる。06文書の `QueryBuilder` の「offset単独指定」と同じ扱い
  - TermQueryの2組目の並び替え(10.2節)
- **終端メソッドとの関係**: `first()` は `limit()`・`noPaging()` に関係なく1件だけ取得し、`offset()` は効く(`limit()` の無い `offset()` でも使える)。`count()` は条件に合う全体の件数を返し、`limit()`・`offset()`・`noPaging()` を無視する。`paginate()` は件数・位置を自分で決めるため、`limit()`・`offset()`・`noPaging()` と併用できない(上記)。TermQueryは `paginate()` を持たない(10.3節)。

### 7.6 空のIN指定(3クエリ共通)

WordPressは空のIN指定(`post__in = []`・`post_name__in = []`・`include = []`・`object_ids = []`・`slug = []` 等)を「条件なし」と扱い、全件を返す。関連IDが0件のとき(`idIn(...$relatedIds)` の `$relatedIds` が空等)に全件が返る事故を防ぐため、3クエリは空のIN指定を「結果が0件と確定した状態」として持つ。

| 指定 | 空の場合 |
|---|---|
| PostQuery: `idIn()`・`slugIn()`・`status()`、`whereTerm()`(`TermOperator::In`・`And`) | 結果は0件 |
| TermQuery: `idIn()`・`slugIn()`・`forPost()` | 結果は0件 |
| UserQuery: `idIn()` | 結果は0件 |
| 3クエリ: `whereMeta($key, [], Compare::In)` | 結果は0件 |
| 3クエリ: `idNotIn()`、`whereMeta($key, [], Compare::NotIn)`、PostQueryの `whereTerm()`(`TermOperator::NotIn`) | 何もしない(条件なし) |

- 同じ引数を設定するセッター(`idIn()` 等)は後勝ちであり、空の指定の後に値のある指定をすると0件の確定は取り消される。句を足すメソッド(`whereMeta()`・`whereTerm()`)の空のIN指定は取り消せない。
- **`idIn()` と `idNotIn()` の併用**: WordPressは包含のIDを指定すると除外のIDを無視する(WP 7.0の `WP_Query` は `post__in` があると `post__not_in` を、`WP_Term_Query` は `include` があると `exclude` を、`WP_User_Query` は `include` があると `exclude` を使わない)。3クエリは終端で、`idIn()` のIDから `idNotIn()` のIDを差し引いた集合だけを包含のID(`post__in`・`include`)として渡し、除外のIDは渡さない。差し引いた結果が空なら、空のIN指定と同じく0件と確定する。呼ぶ順に関係ない。`withArgs()` で渡した `post__in`・`post__not_in`・`include`・`exclude` は差し引かず、WordPressにそのまま渡す。
- 0件と確定したクエリの終端メソッドは、`wpQuery()`・`wpTermQuery()`・`wpUserQuery()` を含めて、WordPressの検索を実行せずに空の結果を返す(7.4節・10.3節・13.2節)。WordPressのオブジェクトを返すメソッドは、検索を実行していない空のオブジェクトを返す。
- `toArgs()` は、WordPressに渡しても0件になる引数(存在しないID 0への絞り込み。PostQueryは `post__in = [0]`、TermQuery・UserQueryは `include = [0]`)を最終段で強制注入する。`toArgs()` の結果を `WP_Query` 等へ直接渡した場合や、空のオブジェクトで検索をやり直した場合も全件にならない。

### 7.7 orderByMeta の並び(3クエリ共通)

`orderByMeta($key, $direction, $type)` は並び替えだけに使い、結果を絞り込まない。メタを保存していないレコードも結果に残す。

- `meta_query` に次の句のグループを足し、名前付きの句 `tms_order_{n}` で並び替える。

  ```php
  [
      'relation' => 'OR',
      'tms_order_{n}' => ['key' => $key, 'compare' => 'NOT EXISTS', 'type' => $type->value],
      ['key' => $key, 'compare' => 'EXISTS'],
  ]
  ```

- NOT EXISTSとEXISTSをORで結ぶため、グループは常に真であり絞り込みにならない。並び替えに名前付きのNOT EXISTSの句を使うのは、WP_Meta_QueryがNOT EXISTSの句だけをメタキーを `ON` に含む `LEFT JOIN` にするためで、その句の値はメタを持つレコードではその値、持たないレコードではNULLになる(EXISTSの句の `JOIN` はメタキーを `ON` に含まず、ほかのメタの行と結び付くため、並び替えに使うとメタを持たないレコードの位置がほかのメタの値で変わる)。
- **メタの無いレコードの並び位置**: NULLとして並ぶため、`SortOrder::Asc` では先頭、`SortOrder::Desc` では末尾になる(MySQL・MariaDBのNULLの並び)。メタの無いレコードどうしの順は定めない。
- **件数**: 並び替えは件数を変えない。`count()` は3クエリとも、メタの無いレコードがほかのメタを複数行持っていても重複して数えない。PostQueryは `found_posts`(`GROUP BY` した行の数)、UserQueryは `get_total()`(グループのOR関係で付く `DISTINCT` の後の行の数)で数えるため、グループを残しても正しい。TermQueryは `fields='count'` が重複を除かないため、並び替えを除いて数える(10.3節)。
- グループのNOT EXISTSの句により、WP_Meta_Queryはクエリの `JOIN` をすべて `LEFT JOIN` にする。ほかの `whereMeta()` の句は値の条件を `WHERE` に持つため、結果は変わらない。
- 純粋な絞り込み(メタを持つレコードだけ)が必要な場合は、`whereMetaExists($key)` を併用する。

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
    /** null は label() 未指定 */
    private ?string $singularLabel = null;

    /** null は複数形の省略(LabelLanguage::Ja でのみ許す) */
    private ?string $pluralLabel = null;

    /** @var array<string, string> */
    private array $labelOverrides = [];

    /** null は labelLanguage() 未指定(Kernel の既定 KernelConfig::$labelLanguage を使う) */
    private ?LabelLanguage $labelLanguage = null;

    /** @var RegisterTaxonomyArgs stubs の register_taxonomy() の $args と同じ配列の形(1.8節) */
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
    /** 単数形と複数形。複数形は LabelLanguage::En では必須、LabelLanguage::Ja では省略可(省略時は単数形を使う) */
    public function label(string $singular, ?string $plural = null): self;
    /** @param array<string, string> $labels */
    public function labels(array $labels): self;
    /** 指定しなければ Kernel の既定(KernelConfig の labelLanguage)を使う */
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
    /** @param array{manage_terms?: string, edit_terms?: string, delete_terms?: string, assign_terms?: string} $capabilities */
    public function capabilities(array $capabilities): self;
    /** @param bool|array{slug?: string, with_front?: bool, hierarchical?: bool, ep_mask?: int} $rewrite */
    public function rewrite(bool|array $rewrite): self;
    public function rewriteSlug(string $slug, bool $withFront = true, bool $hierarchical = false): self;
    public function queryVar(bool|string $queryVar): self;
    /** @param string|array{name: string, slug?: string, description?: string} $term */
    public function defaultTerm(string|array $term): self;
    public function sort(bool $sort = true): self;
    /**
     * エスケープハッチ: 専用のセッターが無いregister_taxonomy引数を設定する(キー単位で上書き)
     * @param RegisterTaxonomyArgs $args
     */
    public function args(array $args): self;

    // ---- 読み取り(FW内部用) ----
    public function slug(): string;
    public function isBuiltin(): bool;
    /** @return list<string> 1.5節規約で解決済みの投稿タイプスラッグ */
    public function resolvedObjectTypes(): array;
    /**
     * @param LabelLanguage $defaultLabelLanguage labelLanguage() 未指定時のラベル言語(Kernel の既定)
     * @return RegisterTaxonomyArgs register_taxonomy の $args(builtin時はEntityDefinitionException)
     */
    public function toArgs(LabelLanguage $defaultLabelLanguage): array;
}
```

### 8.3 振る舞い仕様

- **`create()`**: スラッグ検証 `/^[a-z0-9_\-]{1,32}$/`(WPの32文字制限)。違反は `EntityDefinitionException`。既定値の変更はしない(投稿タイプと異なり `public` のWP既定はtrueのため)。
- **`builtin()`**: 呼び出し可能なのは `objectType()` のみ。それ以外のセッターは即 `EntityDefinitionException`。組込タクソノミーをCPTに紐付ける用途(`register_taxonomy_for_object_type`)に限定する。アダプタ先のタクソノミーは登録(`init` 優先度9)の時点で登録済みでなければならず、無ければ `objectType()` の有無に関係なく `register()` が `EntityDefinitionException` を投げる(9.2節)。
- **`objectType()`**: 複数回呼ぶと追記(重複は解決後に除去)。未指定のまま登録すると「どの投稿タイプにも紐付かないタクソノミー」として `register_taxonomy($slug, [], $args)` で登録される(後から紐付ける運用を許容)。
- **`resolvedObjectTypes()`**: 各要素を1.5節規約(期待基底=`Post\PostType`)で解決し、`array_values(array_unique(...))` で返す。
- **`toArgs()`**: labels決定はPostTypeDefinitionと同一手順(生成元は `LabelGenerator::forTaxonomy`。複数形の扱いと、nullのキーを除くことも同じ)。`label()` も `labels['name']` も無い場合は `EntityDefinitionException`。`label` キーは設定しない(stubsの register_taxonomy の `$args` に `label` は無く、`WP_Taxonomy` は登録時に `labels['name']` から `label` を設定する)。
- **`args()`**: PostTypeDefinitionの `args()` と同じ(builtinでは `EntityDefinitionException`)。`toArgs()` が組み立てる `labels` だけは、`args()` で渡しても常に `toArgs()` の値が優先する。`objectType()` の値は `$args` ではなく `register_taxonomy()` の第2引数(`resolvedObjectTypes()`)として渡すため、`args()` とは関係しない。

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

    /** @internal Kernelがinit(優先度9)・PostType群(優先度10)より先に、このクラスを探索した Kernel を渡して呼ぶ。 */
    final public static function register(\TMSWP\Core\Kernel\Kernel $kernel): void;

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
    /**
     * @param array{alias_of?: string, description?: string, parent?: int, slug?: string} $args stubs の wp_insert_term() の $args と同じ配列の形(1.8節)。
     *        成功時はterm_id
     */
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
2. **builtinの場合**: `taxonomy_exists($def->slug())` がfalseなら、`objectType()` の有無に関係なく `EntityDefinitionException`(`builtin taxonomy does not exist: {slug}`)。存在しないアダプタ先は設定の誤りであり、debugに関係なく投げる(00-overview §5.6。PostType(5.3節)・UserRole(12.2節)と同じ扱い)。存在すれば、`resolvedObjectTypes()` の各スラッグに対し `register_taxonomy_for_object_type($def->slug(), $objectType)` を実行する。
   - 対象がbuiltin投稿タイプ(post/page等。コアが `init` 優先度0で登録済み)なら初回試行で成功する(fast-path)。
   - 対象が同一 `init` 内の後続(02文書の属性スケジュール表: PostType群=優先度10)で登録されるCPTの場合、初回試行はfalseを返す(ロード順による未登録であり異常ではない)。**falseになったスラッグのみ**を `init` 優先度11で1回だけ再試行する。再試行は `$kernel->defer('init', 11, ...)`(02文書 §3.3)で予約する。通常は `add_action('init', ..., 11)` と同じであり、本番の遅延bootのキャッチアップ(`init` 発火済み)でも、同じKernelのPostType群(優先度10)の登録の後、MetaSchemaのWordPressへの登録(優先度20)の前に実行される(`add_action` で予約すると、発火済みの `init` 11 は二度と来ないため、再試行も警告も実行されない)。
   - 再試行後もfalseの場合は真の失敗(スラッグtypo・未登録投稿タイプ等)であり、渡された Kernel のログ(`$kernel->logger()->warning()`。Log文書 §5.1。TMSWP の内部のログは扱う Kernel を明示して記録する)で警告する。これによりfalseの意味は「再試行前=ロード順起因(正常)」「再試行後=真の失敗」に純化され、warningは常に対処すべき信号になる。
3. **非builtinの場合**: `register_taxonomy($def->slug(), $def->resolvedObjectTypes(), $def->toArgs($kernel->config()->labelLanguage))`。戻り値が `WP_Error` なら `EntityDefinitionException`。

(注: Kernel契約1.2によりTaxonomy群はPostType群より先に登録される。`register_taxonomy` の `$object_types` は未登録スラッグでも受理されるため、相互参照は同一init内で完結する。)

**`get()`**:

- `\WP_Term` 渡し: `taxonomy` プロパティ一致を検証。不一致null。
- int渡し: `<= 0` null。`get_term($id, static::slug())` がnull(該当なし)ならnull。`WP_Error`(タクソノミーが未登録等)は1.7節のエラー方針に従う(本番の既定値はnull)。

**`findBySlug()` / `findByName()`**: `get_term_by('slug'|'name', $value, static::slug())`。falseはnull。旧FWの「slug→name」二段フォールバックは廃止し、2つの明示メソッドに分解。

**`all()`**: `static::query()->hideEmpty($hideEmpty)->get()` と等価。

**`url()`**:

- string渡しは `findBySlug()` で解決。`get()`/解決失敗はnull。
- `get_term_link($term)` が `WP_Error` を返した場合は1.7節のエラー方針に従う(本番の既定値はnull)。

**`parents()`**: `parent` プロパティを `get()`(`get_term((int) $parent, static::slug())`)でたどる(nullで打ち切り。`WP_Error` は `get()` と同じく1.7節のエラー方針に従う)。深さ100の超過(`parent` の循環等)の扱い(debugは `EntityDataException`、本番はKernelのロガーに `error` で記録して空配列)・戻り順・`$includeSelf` の意味はPostTypeと同一(5.3節)。

**`create()`**: `wp_insert_term($name, static::slug(), $args)`。`WP_Error` は `EntityPersistenceException::fromWpError()`。成功時は戻り配列の `term_id`(int)を返す。

**`meta()`**: PostType::meta() と同一規約(`ObjectType::Term`)。

---

## 10. Tax\TermQuery

### 10.1 シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Query\CastType;
use TMSWP\Core\Query\Compare;
use TMSWP\Core\Query\SortOrder;

final class TermQuery
{
    /** @var GetTermsArgs stubs の get_terms() の $args(WP_Term_Query::__construct() の $query と同じ)と同じ配列の形(1.8節) */
    private array $args = [];

    /** @var list<array<string, mixed>> */
    private array $metaClauses = [];

    /** @var list<array<int|string, string|array<string, string>>> orderByMeta()の並び替えだけに使う句のグループ(7.7節) */
    private array $orderMetaGroups = [];

    /** @var array{0: string, 1: string}|null orderby と order(WP_Term_Queryは並び替えを1組しか受け付けない) */
    private ?array $orderBy = null;

    /** @var GetTermsArgs */
    private array $rawArgs = [];

    /** @var array<string, true> 空のIN指定を受けた条件(7.6節) */
    private array $emptyIn = [];

    /** @var list<int>|null idIn()のID(nullはidIn()を呼んでいない)。idNotIn()のIDは差し引いて渡す(7.6節) */
    private ?array $idIn = null;
    /** @var list<int> */
    private array $idNotIn = [];

    private ?int $limit = null;
    private ?int $offset = null;

    /** @param class-string<Taxonomy> $taxonomyClass WP_Errorの記録先のKernelを決める(1.7節) */
    private function __construct(
        private readonly string $taxonomyClass,
        private readonly string $taxonomy,
    ) {
    }

    /** @param class-string<Taxonomy> $taxonomyClass クラスを定義していないタクソノミーは builtin() のアダプタ(8.2節)を定義して使う */
    public static function of(string $taxonomyClass): self;

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

    public function whereMeta(string $key, mixed $value, Compare $compare = Compare::Eq, CastType $type = CastType::Char): self;
    public function whereMetaExists(string $key): self;
    public function whereMetaNotExists(string $key): self;

    // ---- 並び順 ----
    public function orderBy(string $field, SortOrder $direction = SortOrder::Asc): self;
    public function orderByDesc(string $field): self;
    public function orderByMeta(string $metaKey, SortOrder $direction = SortOrder::Asc, CastType $type = CastType::Char): self;

    // ---- 件数(7.5節) ----
    public function limit(int $limit): self;
    public function offset(int $offset): self;

    /**
     * エスケープハッチ。キー単位で上書き。taxonomyのみ上書き不可。
     * @param GetTermsArgs $args
     */
    public function withArgs(array $args): self;

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
    /** @return GetTermsArgs */
    public function toArgs(): array;
}
```

### 10.2 引数変換仕様

| メソッド | WP_Term_Query引数 |
|---|---|
| (常時) | `taxonomy` = スコープのスラッグ(最終段で強制注入) |
| (既定) | `hide_empty` = `false`。`number` は渡さない(件数を指定しない場合は全件。7.5節) |
| `hideEmpty($b)` | `hide_empty` = `$b` |
| `search($k)` | `search` = `$k` |
| `parent($p)` | `parent` = ID |
| `childrenOf($t)` | `child_of` = ID |
| `slugIn(...$s)` | `slug` = `$s`(空は0件。7.6節) |
| `idIn(...$ids)` | `include` = `$ids` から `idNotIn()` のIDを差し引いたID(空は0件。7.6節) |
| `idNotIn(...$ids)` | `idIn()` が無ければ `exclude` = `$ids`、あれば `include` から差し引いて `exclude` は渡さない(空は条件なし) |
| `forPost(...$p)` | `object_ids` = ID配列(空は0件) |
| `whereMeta*` / `orderByMeta` | PostQueryと同一方式(`meta_query`、空のIN指定、並び替え専用の句のグループ `tms_order_0`。7.6節・7.7節) |
| `orderBy($f, $d)` | `orderby` = `$f` / `order` = `$d->value`。並び替えは1組だけで、2組目の `orderBy()`・`orderByMeta()` の呼び出しは `InvalidQueryException`(WP_Term_Queryは複数orderbyの連想配列をサポートしないため。`orderByMeta` との併用も不可) |
| `orderByMeta($key, $d, $type)` | 並び替え専用の句のグループを足し、`orderby` = `'tms_order_0'` / `order` = `$d->value` |
| `limit($n)` | `number` = `$n`(`$n < 1` は `InvalidArgumentException`) |
| `offset($n)` | `offset` = `$n`(負値は `InvalidArgumentException`。`limit()` の無い `offset()` は `toArgs()` で `InvalidQueryException`) |

値の検証(enum)はPostQueryと同一。マージ順も同一(taxonomy強制注入の後、0件と確定していれば `include` = `[0]` を強制注入して終わる)。

### 10.3 終端メソッド仕様

| メソッド | 振る舞い |
|---|---|
| `get()` | `get_terms(toArgs())`。`array_values()` で `list<\WP_Term>` |
| `first()` | `number=1` を適用して先頭 or null(`limit()` に関係なく1件。`offset()` は効く) |
| `ids()` | `fields='ids'` で `list<int>` |
| `count()` | `fields='count'` で実行し `(int)` キャスト。`number`・`offset` と並び替え(`orderby`・`order`、`orderByMeta()` の並び替え専用の句のグループ)は渡さない(条件に合う全体の件数)。`whereMeta()` 等の絞り込みと `withArgs()` の引数は渡す。並び替え専用の句のグループを残すと、そのメタが無いタームがほかのメタの行と結び付き、`fields='count'` の `SELECT DISTINCT COUNT(*)` が重複を除かないため件数が多くなる |
| `exists()` | `first() !== null` 相当 |
| `url()` | `first()` → `get_term_link`。該当なしはnull(旧 `get_term_link($args)` の後継。既定hide_empty=falseのため旧FWの強制falseと同等の挙動) |
| `wpTermQuery()` | `new \WP_Term_Query(toArgs())` |

- `get_terms()`・`get_term_link()` が返す `WP_Error`(タクソノミーが未登録の `invalid_taxonomy` 等)は、1.7節のエラー方針に従う。debugでは `QueryException`、本番では `of()` に渡したタクソノミークラスのKernelのロガーに `error` で記録して既定値(`get()`・`ids()` は `[]`、`first()`・`url()` はnull、`count()` は0)を返す。
- 0件と確定している場合(7.6節)、すべての終端メソッドは `get_terms()` を呼ばずに空の結果を返す。`wpTermQuery()` は引数なしで作った `WP_Term_Query`(`__construct()` は引数が空なら検索しない)に、`terms` = `[]` と、`query_vars` = `toArgs()`(`include = [0]` を含む)を設定して返す。`get_terms()` で検索をやり直しても0件になる。

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
- capの自動削除は行わない(宣言から外したcapはDBに残る。削除はWP-CLIまたはapp側で明示的に行う規約。rules/に明記)。表示名の変更も、初回の `add_role` の後はDBに反映しない。どちらも宣言とDBの差分として、`register()` が渡されたKernelのロガーに `warning` で記録して知らせる(12.2節)。WP-CLIの同期コマンドは持たない。

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

    /** @internal Kernelがinit(優先度11)・PostType群(優先度10)の後に、このクラスを探索した Kernel を渡して呼ぶ。冪等。 */
    final public static function register(\TMSWP\Core\Kernel\Kernel $kernel): void;

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
    /**
     * @param array{ID?: int, user_pass?: string, user_login?: string, ..., locale?: string, meta_input?: array<string, mixed>} $userdata
     *        stubs の wp_insert_user() の $userdata のうち配列の形(1.8節。全キーは実装の PHPDoc)。user_login・user_pass・role は
     *        引数とこのクラスのロールに、ID は除く。成功時はユーザーID。失敗時EntityPersistenceException
     */
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
4. 非builtinで `$role` が既存 → 宣言とDBの差分を、渡されたKernelのロガー(`$kernel->logger()->warning()`。07-support §5.1)に記録する。DBは書き換えない。
   - 表示名: `wp_roles()->role_names[$def->role()]` が `$def->roleLabel()` と違う(`add_role` は初回だけのため、宣言の表示名の変更はDBに反映されない)。
   - cap: DBのロールの `capabilities` のキーのうち、`$def->capabilityList()` に無いもの(宣言から外したcap。値がfalseの拒否も含む)。
   - builtinのロールは表示名を宣言できず、宣言に無いcapを持つのが普通のため、差分を記録しない。
5. 非builtinで `$role` がnull → `$role = add_role($def->role(), $def->roleLabel())`(`add_role` はDB(`wp_user_roles` オプション)へ書き込むが、手順2のチェックにより2回目以降のリクエストでは実行されない=冪等)。`add_role` がnullを返した場合は `EntityDefinitionException`。
6. `$def->capabilityList()` の各capについて `$role->has_cap($cap)` がfalseのときのみ `$role->add_cap($cap)`(DB書き込みは欠如時のみ=冪等)。

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

**`meta()`**: PostType::meta() と同一規約(`ObjectType::User`。無い対象・ロール外の対象は `InvalidArgumentException`)。

---

## 13. User\UserQuery

### 13.1 シグネチャ

```php
<?php

declare(strict_types=1);

namespace TMSWP\Core\User;

use TMSWP\Core\Query\CastType;
use TMSWP\Core\Query\Compare;
use TMSWP\Core\Query\PageResult;
use TMSWP\Core\Query\SortOrder;

final class UserQuery
{
    /** @var WpUserQueryArgs stubs の WP_User_Query::__construct() の $query と同じ配列の形(1.8節) */
    private array $args = [];

    /** @var list<array<string, mixed>> */
    private array $metaClauses = [];

    /** @var list<array<int|string, string|array<string, string>>> orderByMeta()の並び替えだけに使う句のグループ(7.7節) */
    private array $orderMetaGroups = [];

    /** @var array<string, string> */
    private array $orderBy = [];

    /** @var WpUserQueryArgs */
    private array $rawArgs = [];

    /** @var array<string, true> 空のIN指定を受けた条件(7.6節) */
    private array $emptyIn = [];

    /** @var list<int>|null idIn()のID(nullはidIn()を呼んでいない)。idNotIn()のIDは差し引いて渡す(7.6節) */
    private ?array $idIn = null;
    /** @var list<int> */
    private array $idNotIn = [];

    private ?int $limit = null;
    private ?int $offset = null;

    private function __construct(
        private readonly string $role,
    ) {
    }

    /** @param class-string<UserRole> $userRoleClass クラスを定義していないロールは builtin() のアダプタ(11.2節)を定義して使う */
    public static function of(string $userRoleClass): self;

    // ---- スコープ ----
    /**
     * キーワード検索。$keywordに'*'が含まれない場合は前後に'*'を自動付与する。
     * @param string ...$columns user_login / user_email / user_nicename / user_url / display_name / ID
     */
    public function search(string $keyword, string ...$columns): self;
    public function idIn(int ...$ids): self;
    public function idNotIn(int ...$ids): self;

    public function whereMeta(string $key, mixed $value, Compare $compare = Compare::Eq, CastType $type = CastType::Char): self;
    public function whereMetaExists(string $key): self;
    public function whereMetaNotExists(string $key): self;

    // ---- 並び順 ----
    /** $field: login / email / registered / display_name / ID / include / meta_value 等(WP_User_Query準拠) */
    public function orderBy(string $field, SortOrder $direction = SortOrder::Asc): self;
    public function orderByDesc(string $field): self;
    public function orderByMeta(string $metaKey, SortOrder $direction = SortOrder::Asc, CastType $type = CastType::Char): self;

    // ---- 件数・ページング(7.5節) ----
    public function limit(int $limit): self;
    public function offset(int $offset): self;

    /**
     * エスケープハッチ。キー単位で上書き。roleのみ上書き不可。
     * @param WpUserQueryArgs $args
     */
    public function withArgs(array $args): self;

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
    /** @return WpUserQueryArgs */
    public function toArgs(): array;
}
```

### 13.2 引数変換・終端仕様

| メソッド | WP_User_Query引数 |
|---|---|
| (常時) | `role` = スコープのロール(最終段で強制注入)。`fields` = `'all'` 既定 |
| (既定) | `count_total` = `false`。`number` は渡さない(件数を指定しない場合は全件。7.5節) |
| `search($k, ...$c)` | `search` = ワイルドカード処理後の `$k`、`$c` 非空なら `search_columns` = `$c` |
| `idIn` / `idNotIn` | `include` / `exclude`(`idIn()` の空は0件、`idNotIn()` の空は条件なし。併用すると `include` から差し引いたIDだけを渡す。7.6節) |
| `whereMeta*` / `orderByMeta` | PostQueryと同一方式(`meta_query`、空のIN指定、並び替え専用の句のグループ `tms_order_{n}` と `orderby[tms_order_{n}]`。7.6節・7.7節) |
| `orderBy($f, $d)` | `orderby[$f] = $d->value`(WP_User_Queryは連想配列orderbyをサポート。複数累積可) |
| `limit($n)` | `number` = `$n`(`$n < 1` は `InvalidArgumentException`) |
| `offset($n)` | `offset` = `$n`(負値は `InvalidArgumentException`。`limit()` の無い `offset()` は `toArgs()` で `InvalidQueryException`) |

値の検証(enum)とマージ順はPostQueryと同一(role強制注入の後、0件と確定していれば `include` = `[0]` を強制注入して終わる)。

| 終端 | 振る舞い |
|---|---|
| `get()` | `(new \WP_User_Query($args))->get_results()` を `list<\WP_User>` で |
| `first()` | `number=1` → 先頭 or null(`limit()` に関係なく1件。`offset()` は効く) |
| `ids()` | `fields='ID'` → `list<int>`(各要素を `(int)` キャスト) |
| `count()` | `fields='ID'`, `number=1`, `count_total=true`、`offset` を除く → `(int) $query->get_total()`(条件に合う全体の件数) |
| `exists()` | `first() !== null` 相当 |
| `url()` | `first()` → `get_author_posts_url()`。該当なしnull |
| `paginate($perPage, $page)` | `number=$perPage`, `offset=($page - 1) * $perPage`, `count_total=true`。`$perPage < 1`・`$page < 1` は `InvalidArgumentException`、`offset()`・`limit()` との併用は `InvalidQueryException`。`$page` 解決規則はPostQueryと同一。`PageResult<\WP_User>`(`total` = `get_total()`) |
| `wpUserQuery()` | `new \WP_User_Query(toArgs())` |

0件と確定している場合(7.6節)、すべての終端メソッドは `WP_User_Query` を実行せずに空の結果を返す。`paginate()` の扱いはPostQueryと同一(7.4節。`$page` を省略しても現在のページを解決しない)。`wpUserQuery()` は引数なしで作った `WP_User_Query`(`__construct()` は引数が空なら検索しない)に、`query_vars` = `toArgs()`(`include = [0]` を含む)を設定し、`get_results()`・`get_total()` が返す `results` = `[]`・`total_users` = 0 を設定して返す。`results`・`total_users` はprivateであり、`WP_User_Query` が互換のために `__set()` での書き込みを許しているため、`__set()` を呼んで設定する。

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
use TMSWP\Core\Label\LabelLanguage;
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
            ->labelLanguage(LabelLanguage::Ja)
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
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;

#[AsTaxonomy]
final class BlogCategoryTax extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('blog_category')
            ->label('ブログカテゴリ')
            ->labelLanguage(LabelLanguage::Ja)
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
use TMSWP\Core\Query\SortOrder;

$page = ContractorUser::query()
    ->whereMeta('prefecture', '東京都')
    ->orderBy('registered', SortOrder::Desc)
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
| labels自動生成(英語フォーマット+iwf2bテキストドメイン依存) | en/jaテンプレート内蔵の `LabelGenerator`、gettext非依存 | 既定 en でソース文字列方針に揃え、日本語サイトは `KernelConfig` の `labelLanguage: LabelLanguage::Ja`(Kernel ごと)か定義の `labelLanguage(LabelLanguage::Ja)` で決定的に切り替える(DR-17)。2クラスに重複していた生成コードの一元化 |
| `$find_args`+`create_args()` | `query()` オーバーライド+クエリビルダ | グローバルスコープ相当の価値は維持し、配列マージの暗黙性を型付きAPIへ |
| `template`/`thumbnail` 擬似キー | `PostQuery::template()`/`hasThumbnail()` | 実利用39箇所超の高価値機能を明示メソッド化 |
| `get_thumbnail` の3段フォールバック(本文img抽出+getimagesize) | コアは主経路(WP標準API)のみ+`tmswp/post/thumbnail_fallback` フィルタ | レンダリング中の同期HTTP I/O排除。分割境界は「主経路はコア・本文抽出等のフォールバックは app 側」 |
| `$object_type` にAbstractPost FQCN | `objectType()` のFQCN受理を1.5節規約で全クエリAPIへ拡張 | 旧FWの良設計(マジックストリング排除)の踏襲・拡張 |
| 手動タームキャッシュ(`get_object_term_cache`) | `get_the_terms()` に委譲 | WP4.7以降の車輪の再発明の除去 |
| `insert_default_meta`(空文字メタ自動挿入) | 廃止 | `register_*_meta` のdefault値(04文書・DR-05)で代替。空文字レコードのDB増殖を解消 |
| `AbstractUser`(非abstract・`user_register` をadd_filterで誤登録) | abstract基底+登録フックなし(メタdefaultは04文書の責務) | バグの根治と責務縮小。ロール定義+クエリの薄さは維持し、認証フローは引き続きapp側(rules/レシピ) |
| `__callStatic`(MK_*/ON_*) | 全廃。`meta()` ファサード+明示アクセサ規約 | 本FW最大のAIアンフレンドリー箇所の除去(DR-05) |
| `Loader` の `$dependencies` 手動宣言(Tax→Post順) | Kernel固定順序(Taxonomy→PostType→UserRole、FQCN昇順) | 非決定的ロード順・依存宣言漏れの根治(DR-04) |

---

## 16. 受け入れ基準

### チェックリスト

**定義・登録**

- [ ] `PostTypeDefinition::create('product')->label('Product', 'Products')->hasArchive()` の `toArgs()` が `public=true`・en自動生成labels・`has_archive=true` を含み、未指定キーを含まない
- [ ] `labelLanguage(LabelLanguage::Ja)` で日本語labelsに切り替わり、`labels(['menu_name' => '...'])` がキー単位で上書きされる
- [ ] `labelLanguage()` を指定しない定義が Kernel の既定(`KernelConfig` の `labelLanguage`)の言語で labels を組み立て、Ja なら複数形を省略できる。定義の `labelLanguage()` は Kernel の既定より優先する(Kernel の既定が Ja の統合テストを含む)
- [ ] label/labels両方未指定の `toArgs()` が `EntityDefinitionException` を投げる
- [ ] スラッグ形式違反(`create('Invalid Slug!')`、21文字超のpost type、33文字超のtaxonomy)が `EntityDefinitionException` を投げる
- [ ] `builtin()` 定義に許可外セッターを呼ぶと即 `EntityDefinitionException`
- [ ] Kernelが収集リストの1巡走査で各エンティティクラスの `register()` を正確に1回だけ呼ぶ(統合テストで呼び出し回数を検証。`register()` 自身は重複防止を持たない)
- [ ] `supports('thumbnail')` を持つ複数のPostTypeを登録しても `post-thumbnails` テーマサポートが和集合でマージされる
- [ ] `TaxonomyDefinition::objectType(BlogPost::class)` が登録時に `'blog'` へ解決される。Taxonomy/PostType以外のクラスFQCNは `EntityDefinitionException`
- [ ] builtinタクソノミーのCPT紐付けで、初回falseになったスラッグのみが `init` 優先度11で再試行されて成功し(警告なし)、未登録投稿タイプ相手は再試行後に渡された Kernel のロガー(`$kernel->logger()`)に `warning` が1回記録される。本番の遅延bootのキャッチアップでも、再試行がPostType群の登録の後、MetaSchemaのWordPressへの登録の前に実行される(統合テスト)
- [ ] 存在しないbuiltinのアダプタ先(`PostTypeDefinition::builtin()` の投稿タイプ、`TaxonomyDefinition::builtin()` のタクソノミー(`objectType()` の有無に関係なく)、`UserRoleDefinition::builtin()` のロール)が、`register()` で `EntityDefinitionException` になる。PostTypeは例外の前に `add_post_type_support` しない
- [ ] `UserRole::register()` が2リクエスト目以降にDB書き込み(`add_role`/`add_cap`)を行わない(`get_role()`/`has_cap()` チェックによる冪等性。統合テストで `update_option` 呼び出し回数を検証)
- [ ] 既存の非builtinロールの表示名の違いと宣言に無いcapが、渡された Kernel のロガーに `warning` で記録され、DBは書き換えない。初回の `add_role`・宣言どおりのロール・builtinのロールでは記録しない
- [ ] `blockEditor()` の `use_block_editor_for_post_type` のフィルタが、bool以外(null等)の値を受けても落ちず、対象外の投稿タイプの値を受け取ったまま返す

**取得系(`?T` 統一)**

- [ ] `get()`/`findBySlug()`/`findByName()`/`findByEmail()`/`findByLogin()`/`first()`/`firstTerm()`/`current()` の戻り値型宣言がすべて `?WP_Post`/`?WP_Term`/`?WP_User` である(falseを返すメソッドが存在しない)
- [ ] `ProductPost::get($pagePost)`(タイプ不一致)・`get(0)`・`get(-1)` がnull
- [ ] `permalink()`/`archiveUrl()`/`url()`/`authorUrl()`/`thumbnailUrl()` が、WP関数の「該当なし」の `false` をnullへ正規化する
- [ ] URL取得でWP関数が返す `WP_Error` を、debugでは `QueryException` にし、本番ではそのエンティティクラスのKernelのロガーに `error` で記録してnullにする(1.3節・1.7節)
- [ ] `thumbnail()` がアイキャッチなし+フォールバック未登録でnull、`tmswp/post/thumbnail_fallback` にThumbnailを返すフィルタを登録するとそれを返す。Thumbnail以外の返却値は無視される
- [ ] `thumbnail()` のaltが `_wp_attachment_image_alt` メタのみから解決される(メタが空なら `''`。添付の `post_excerpt`/`post_title` へはフォールバックしない)
- [ ] `parents()` が「ルート祖先→直近親」順で返り、深さ100超(親の循環)はdebugで `EntityDataException`、本番でKernelのロガーへの `error` の記録と空配列になる(PostType・Taxonomy)
- [ ] `meta()` が、無い対象・スコープ外の対象で `InvalidArgumentException` を投げる
- [ ] `Thumbnail::img()` が `Support\Html::attributes()` で属性を出力し(boolのtrueは値なし、false・nullは出力しない。`src` は `esc_url()`)、`src`/`alt`/`width`/`height` の上書きを大文字小文字に関係なく無視する。フォールバックのフィルタが返した許可されないプロトコルのURLは `InvalidArgumentException` になる

**クエリ**

- [ ] `PostQuery::toArgs()` が7.3節のマッピング表どおりの引数を生成する(ユニットテストで全メソッドを網羅)
- [ ] `post_type`/`taxonomy`/`role` が `withArgs()` でも上書きできない
- [ ] 既定値: PostQueryは `post_status=['publish']`+`ignore_sticky_posts=true`+`posts_per_page=-1`、TermQueryは `hide_empty=false`、UserQueryは `count_total=false`
- [ ] 3クエリが比較演算子・キャスト型・並び順を `Compare`・`CastType`・`SortOrder`、`whereTerm()` のfield・operatorを `TermField`・`TermOperator` で受け取り、文字列と実行時の許可リストで受けるAPIが無い
- [ ] 件数を指定しない `get()` が、サイトの `posts_per_page` の設定に関係なく全件を返す(統合テスト)
- [ ] `limit(0)`・`limit(-1)`・負の `offset()`・`paginate(0)`・`paginate($n, 0)`・不正な値の `PageResult` が `InvalidArgumentException` を投げる
- [ ] `noPaging()` と `limit()`・`offset()`・`paginate()` の併用、`offset()`・`limit()` と `paginate()` の併用、`limit()` の無い `offset()` が `InvalidQueryException` を投げる
- [ ] `first()` が `noPaging()`・`limit()` に関係なく1件だけ取得する(統合テストでSQLの `LIMIT 0, 1` を確認)
- [ ] 空のIN指定(7.6節の表)と `idIn()` から `idNotIn()` を差し引いた空で、`wpQuery()`・`wpTermQuery()`・`wpUserQuery()` と `$page` を省略した `paginate()` を含むすべての終端メソッドが、WordPressの検索を実行せず(検索のフックが呼ばれない)に空の結果を返し、同じ指定をWordPressに直接渡すと全件が返る(統合テスト)。空の除外は条件なしになる
- [ ] `orderByMeta()` を付けても `count()` が変わらない(並び替えのメタが無いレコードがほかのメタを複数行持つ場合と、`whereMeta()` との組み合わせを含む3クエリの統合テスト)
- [ ] `idIn()` と `idNotIn()` の併用で、除外したIDが結果に含まれず(WordPressに直接渡すと含まれる)、すべて除外すると0件になる(3クエリの統合テスト)
- [ ] `orderByMeta()` が並び替え専用の句のグループ(7.7節)と `tms_order_{n}` の並び替えを生成し、メタを持たないレコードを結果から落とさず、ASCでは先頭、DESCでは末尾に並べる(3クエリの統合テスト)
- [ ] `TermQuery::orderBy()` の2回目呼び出し(`orderByMeta()` を含む)が `InvalidQueryException`
- [ ] `WP_Error`(存在しないタクソノミー等)が、debugでは `QueryException`、本番ではKernelのロガーへの `error` の記録と既定値になり、該当なし(タームが付いていない等)は例外にならない(統合テスト)
- [ ] `\` を含む文字列で存在しないクラスを指定すると `EntityDefinitionException` になる(統合テスト)
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
composer test:integration  # 統合層: wp-env + WordPress のテストライブラリ。register往復・クエリ実行・paginate・UserRole登録のDB書込冪等性
composer qa        # 上記一括
```
