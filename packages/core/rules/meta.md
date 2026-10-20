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

1. 対象エンティティクラスに `META_` 定数を追加する。
2. 定数へ `#[MetaSchema]` を付ける。
3. 読み書きは `::meta($object)` の `MetaBag` 経由で行う。

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

## 書き込みの規則

- `set()` は書き込んだら `true`、書き込まなかったら `false` を返す。`false` は失敗ではなく、同じ値だった場合と、ほかのプラグインのフィルタが書き込みを止めた場合で、保存されている値は変わっていない。データベースのエラーで書き込めなかったら `RuntimeException` が投げられる。
- 宣言済みのキーには、スキーマの型に合う値だけを渡す(`MetaValueType::Integer` なら int)。合わない値は `InvalidArgumentException` になる。フォームの文字列は呼び出し側で型を変換してから渡す。
- `single: false` のキーには list を渡す。`type` は1行ごとの型を書く。
- 値を消すときは `delete()` を使う。`null` を `set()` すると `InvalidArgumentException` になる。
- unslash済みの元の値をそのまま渡す。バックスラッシュを含む文字列(JSON・Windowsのパス・正規表現)も、FWが保存時に `wp_slash()` するので往復する。

## してはいけないこと

- メタキー文字列をテンプレートに直書きしない。
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
