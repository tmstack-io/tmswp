# 非ACFカスタムフィールドを定義する

投稿・ターム・ユーザーのメタはエンティティクラスの `#[MetaSchema]` 付き定数で宣言する。
`get_post_meta()` / `update_post_meta()` は直接呼ばない。

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
            ->supports('title', 'editor');
    }

    public static function price(WP_Post|int $post): int
    {
        return self::meta($post)->int(self::META_PRICE);
    }
}
```

## してはいけないこと

- メタキー文字列をテンプレートに直書きしない。
- ACF管理下のキーを `#[MetaSchema]` でも宣言しない。
- 未保存判定に falsy 判定を使わない。必要なら `has()` を使う。

## 確認方法

1. `composer qa` が通ること。
2. `showInRest: true` のメタがRESTレスポンスに含まれること。
3. `set()` した値が再取得できること。
