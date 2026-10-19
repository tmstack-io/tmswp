# カスタム投稿タイプ(CPT)を追加する

投稿タイプ1つにつきクラス1つを `app/Post/` に置く。`register_post_type()` は直接呼ばない。
名前空間はスターター既定の `App\`。変更している場合は読み替える。

## 手順

1. `app/Post/<Name>Post.php` を作る。
2. `#[AsPostType]` を付け、`TMSWP\Core\Post\PostType` を継承する。
3. `definition()` で `PostTypeDefinition` を返す。
4. `composer qa` を通す。

## 完全な実装例

`app/Post/NewsPost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class NewsPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('news')
            ->label('お知らせ')
            ->labelLanguage(LabelLanguage::Ja)
            ->menuIcon('dashicons-megaphone')
            ->supports('title', 'editor', 'thumbnail')
            ->hasArchive();
    }
}
```

## テンプレートでの使い方

```php
<?php foreach (\App\Post\NewsPost::query()->limit(5)->get() as $news) : ?>
    <a href="<?php echo esc_url((string) \App\Post\NewsPost::permalink($news)); ?>">
        <?php echo esc_html(get_the_title($news)); ?>
    </a>
<?php endforeach; ?>
```

## アイキャッチ未設定時のフォールバック画像

`PostType::thumbnail()` はアイキャッチが未設定のときに `tmswp/post/thumbnail_fallback` フィルタを呼ぶ。フィルタは `?TMSWP\Core\Post\Thumbnail $thumbnail, WP_Post $post, string|array $size` を受け取り、代替画像がある場合だけ `TMSWP\Core\Post\Thumbnail` DTO を返す。それ以外の戻り値は無視される。

本文先頭の `<img src>` を使う場合は、抽出規則を app 側の要件に合わせて実装する。遅延読み込み属性や `srcset` を優先したい場合も、このクラス内で拡張する。

`app/Hook/ThumbnailFallbackHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;
use TMSWP\Core\Post\Thumbnail;
use WP_Post;

#[AsHook]
final class ThumbnailFallbackHook extends Hook
{
    /**
     * @param string|array<int> $size
     */
    #[Filter('tmswp/post/thumbnail_fallback')]
    public function firstContentImage(?Thumbnail $thumbnail, WP_Post $post, string|array $size): ?Thumbnail
    {
        if ($thumbnail instanceof Thumbnail) {
            return $thumbnail;
        }

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $matches) !== 1) {
            return null;
        }

        $url = esc_url_raw($matches[1], ['http', 'https']);

        if ($url === '') {
            return null;
        }

        $dimensions = $this->dimensions($size);

        return new Thumbnail($url, $dimensions['width'] ?? null, $dimensions['height'] ?? null, '');
    }

    /**
     * @param string|array<int> $size
     *
     * @return array{width: int, height: int}|null
     */
    private function dimensions(string|array $size): ?array
    {
        if (!is_array($size) || !isset($size[0], $size[1]) || !is_int($size[0]) || !is_int($size[1])) {
            return null;
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }
}
```

## してはいけないこと

- `register_post_type()` や `add_action('init', ...)` を自分で書かない。
- `functions.php` に投稿タイプ関連のロジックを書かない。
- 1クラスで複数投稿タイプを定義しない。

## 確認方法

1. `composer qa` が通ること。
2. 管理画面に投稿タイプが表示されること。
3. 表示されない場合は `wp tmswp cache clear` を実行して再確認する。
