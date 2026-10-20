# 非ACFカスタムフィールドを定義する

投稿・ターム・ユーザーのメタはエンティティクラスの `#[MetaSchema]` 付き定数で宣言する。
`get_post_meta()` / `update_post_meta()` は直接呼ばない。

## 使い分け

値の種類ごとに経路を1つに決める。同じ値を複数の経路で宣言しない。

- 単純な値(文字列・数値・真偽・日付・単一画像ID等)は、必ず `#[MetaSchema]` で宣言し、`MetaBag`(`::meta($object)`)だけで読み書きする。
- 単純な値をACFの画面で編集する場合も、宣言と `MetaBag` はそのままにし、エンティティに `#[UseMetaStorage(AcfMetaStorage::class)]` を付けて保存先だけを切り替える(rules/acf.md の「MetaBagをACFへ委譲する場合」)。ただし、これはv0.6.0でacf-bridge側を直してから使える。v0.6.0までは下の「v0.6.0までの境目」に従う。
- ACF固有の複合型(repeater・flexible_content・group・gallery・relationship)は、acf-bridgeの生成アクセサ(`XxxFields`)で扱う(rules/acf.md)。
- サイト全体の設定はメタではなく設定で扱う(rules/options.md の「使い分け」)。

### v0.6.0までの境目

今のacf-bridgeは、ACFのフィールドグループで管理するキーを外部管理として登録する。そのため、同じキーを `#[MetaSchema]` で宣言すると、起動時に `DuplicateMetaKeyException` になる。v0.6.0までは次のとおりにする。

- ACFのフィールドグループで管理するキーは `#[MetaSchema]` で宣言せず、生成アクセサ(`XxxFields`)で読み書きする。
- `#[UseMetaStorage(AcfMetaStorage::class)]` は、ACFのフィールドグループで管理しないキーをACFの保存規約(`update_field`)で読み書きする場合にだけ使う。

## 手順

1. 対象エンティティクラスに public の文字列定数を追加する。定数名は `META_` で始めるのを推奨する(宣言の根拠は属性だけで、名前は検査しない)。
2. 定数へ `#[MetaSchema]` を付ける。
3. 読み書きは `::meta($object)` の `MetaBag` 経由で行う。
4. テンプレート等から頻繁に読むフィールドは、エンティティに明示アクセサ(下の例の `price()`)を書く。

## 完全な実装例

```php
<?php

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

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')
            ->label('製品')
            ->labelLanguage(LabelLanguage::Ja)
            ->supports('title', 'editor');
    }

    public static function price(WP_Post|int $post): int
    {
        return self::meta($post)->int(self::META_PRICE);
    }
}
```

## キーの渡し方

- メタキーは、そのキーを宣言したエンティティの定数で渡す(`ProductPost::meta($post)->int(ProductPost::META_PRICE)`)。文字列リテラルで渡すと未宣言のキーとして扱われ、宣言済みのキーと同じ文字列なら PHPStan が `tmswp.metaKeyLiteral` で報告する。ほかのエンティティの定数を渡すと `tmswp.metaKeyOwnership` で報告する。
- 頻繁に読むフィールドは、テンプレートから `meta()` を直接呼ばず、エンティティの明示アクセサを呼ぶ。
- スキーマになるのは、そのクラス自身が宣言した定数だけ。親クラスの定数は子クラスのスキーマにならない。

## 未宣言のキーを読み書きする

`#[MetaSchema]` で宣言していないキー(WordPress の `_thumbnail_id`、ほかのプラグインのキー等)も、`MetaBag` に文字列で渡せば読み書きできる。エラーにはならないが、スキーマの既定値・`single: false`・REST への公開・型の検査・PHPStan の型の絞り込みは効かない。

## 使える時点

- メタスキーマは Kernel の boot の中で登録されるため、`::meta($object)` の読み取りと `delete()` は boot の後ならいつでも使える(init の前、app の `#[Action('init')]` の中を含む)。`sanitize` を宣言していないキーの `set()` も同じ。
- WordPress への `register_*_meta` は `init` の priority 20 で行われる。WordPress が `sanitize` を適用するのはその後なので、`sanitize` を宣言したキーの `set()` は `init` の priority 20 より後で呼ぶ。それより前に呼ぶと `LogicException` になる。
- REST への公開のような、WordPress への登録に頼るほかの動作も `init` の priority 20 より後で効く。

## 書き込みの規則

- `set()` は書き込んだら `true`、書き込まなかったら `false` を返す。`false` は失敗ではなく、同じ値だった場合と、ほかのプラグインのフィルタが書き込みを止めた場合で、保存されている値は変わっていない。データベースのエラーで書き込めなかったら `RuntimeException` が投げられる。
- 宣言済みのキーには、スキーマの型に合う値だけを渡す(`MetaValueType::Integer` なら int)。合わない値は `InvalidArgumentException` になる。フォームの文字列は呼び出し側で型を変換してから渡す。
- `MetaValueType::String` のキーに `Stringable` のオブジェクトを渡すと `InvalidArgumentException` になる。`(string)` にしてから渡す。
- WordPress のメタは `false` を空文字 `''` で保存する。`false` を保存したキーの `get()` は `''` を返す。`MetaValueType::Boolean` のキーは `bool()` で読む(`''` は `false` に戻る)。
- `single: false` のキーには list を渡す。`type` は1行ごとの型を書く。
- 値を消すときは `delete()` を使う。`null` を `set()` すると `InvalidArgumentException` になる。
- unslash済みの元の値をそのまま渡す。バックスラッシュを含む文字列(JSON・Windowsのパス・正規表現)も、FWが保存時に `wp_slash()` するので往復する。エンティティの `create()` の `meta_input` も同じ(`null`・object は `InvalidArgumentException`)。
- `#[WithMetaRevisions]` を付けた投稿タイプのプレビューでは、`has()`・`get()`・型付きの getter・`all()` のどれもオートセーブの値を返す(値が `0`・`''` でも行がある扱いになる)。

## 未保存の投稿も検索の対象にする

`MetaBag` の既定値はスキーマの `default` で補われ、データベースに行を作らない。そのため、メタを保存していない投稿は `whereMeta()`・`whereMetaExists()` の条件に合わない(`orderByMeta()` はメタの無い投稿も並べる)。メタの無い投稿も検索の対象にしたいときは、保存のフックで既定値を明示的に保存する。

`app/Hook/ProductMetaDefaultsHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use App\Post\ProductPost;
use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;
use WP_Post;

#[AsHook]
final class ProductMetaDefaultsHook extends Hook
{
    /** 価格を保存していない製品に、既定値の行を保存する(whereMeta() の条件に合わせるため) */
    #[Action('save_post_product')]
    public function insertDefaults(int $postId, WP_Post $post): void
    {
        $meta = ProductPost::meta($post);

        if (!$meta->has(ProductPost::META_PRICE)) {
            $meta->set(ProductPost::META_PRICE, 0);
        }
    }
}
```

- フック名は `save_post_<投稿タイプのスラッグ>` にする。リビジョン(公開済みの投稿や他人の下書きの自動保存が作るオートセーブのリビジョンを含む)は投稿タイプが `revision` なので、このフックを通らない。一方、自分が作成者でロックの無い下書き(`draft`・`auto-draft`)の自動保存は投稿そのものを更新するので、このフックを通る(既定値の行の保存はそのときに行われても問題ない)。自動保存のときに処理を除きたい場合は、フック名に頼らず `if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }` のように明示的に除く。
- この方法は、フックを入れた後に保存した投稿にだけ効く。既存の投稿には、一度だけの処理(WP-CLI 等)で同じように保存する。

## テスト

- 既定の保存先 `WpMetaStorage` は、テストのために別の実装へ差し替えられない(final のクラスで、Container は同じ id に別の型を登録できない)。メタを読み書きするコードは、wp-env の統合テストで、本物の WordPress の保存先に対して確かめる。
- `MetaBag` を直接 `new` しない。テストでも `::meta($object)` で取得する。

## してはいけないこと

- メタキー文字列をテンプレートに直書きしない。宣言済みのキーは定数で渡す。
- v0.6.0まで、ACFのフィールドグループで管理するキーを `#[MetaSchema]` で宣言しない(「v0.6.0までの境目」)。
- 未保存判定に falsy 判定を使わない。必要なら `has()` を使う。
- `#[MetaSchema]` を `protected` / `private` の定数に付けない(起動時に `InvalidSchemaException` になる)。
- `set()` に渡す値を自分で `wp_slash()` しない(バックスラッシュが残る)。
- `set()` の戻り値 `false` を失敗として扱わない(保存値は変わっていない)。
- `type: MetaValueType::Array` / `Object` のメタを `showInRest: true` だけでRESTに出さない。`showInRest: ['schema' => ['items' => [...]]]`(Objectは `properties` 等)を書く。

## 確認方法

1. `composer qa` が通ること。
2. `showInRest: true` のメタがRESTレスポンスに含まれること。
3. `set()` した値が再取得できること。
