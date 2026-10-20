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

## クエリの書き方

`query()` が返す `PostQuery` は、比較演算子・キャスト型・並び順を `TMSWP\Core\Query\` の enum(`Compare`・`CastType`・`SortOrder`)で受け取る。`whereTerm()` の field・operator も enum(`TermField`・`TermOperator`)で受け取る。タクソノミー・ロールのクエリ(`TermQuery`・`UserQuery`)も同じ。

```php
<?php

use App\Post\NewsPost;
use TMSWP\Core\Query\CastType;
use TMSWP\Core\Query\Compare;
use TMSWP\Core\Query\SortOrder;
use TMSWP\Core\Query\TermField;

$relatedIds = array_map('intval', (array) get_post_meta(get_the_ID(), 'related_news', false));

// relatedIds が空なら、WordPress を呼ばずに空の配列を返す(全件にならない)
$related = NewsPost::query()->idIn(...$relatedIds)->get();

$pickup = NewsPost::query()
    ->whereMeta('priority', 3, Compare::Gte, CastType::Numeric)
    ->whereTerm('category', [12, 15], TermField::TermId)
    ->orderByMeta('priority', SortOrder::Desc, CastType::Numeric)
    ->limit(5)
    ->get();

$page = NewsPost::query()->orderByDesc('date')->paginate(10);
```

- 件数を指定しない `get()` は全件を返す(管理画面の「1ページに表示する最大投稿数」は効かない)。件数を絞るときは `limit()` を呼ぶ。
- `idIn()`・`slugIn()` 等の IN の指定が空なら結果は0件になる。`idNotIn()` 等の除外が空なら条件なしになる。`idIn()` と `idNotIn()` を併用すると、除外した ID を差し引いた ID に絞る。
- `orderByMeta()` は並び替えだけで、メタの無い投稿も結果に残る(ASC では先頭、DESC では末尾)。メタのある投稿だけにするときは `whereMetaExists()` を併用する。
- `offset()` は `limit()` と一緒に使う。`noPaging()` と `limit()`・`offset()`・`paginate()`、`limit()`・`offset()` と `paginate()` は併用できない(例外になる)。`first()` は常に1件だけ取得する。
- タクソノミーは、スラッグの代わりに Taxonomy クラスの FQCN(`NewsCategoryTax::class`)を渡せる。存在しないクラスは例外になる。

## アイキャッチ未設定時のフォールバック画像

`PostType::thumbnail()` はアイキャッチが未設定のときに `tmswp/post/thumbnail_fallback` フィルタを呼ぶ。フィルタは `?TMSWP\Core\Post\Thumbnail $thumbnail, WP_Post $post, string|array $size` を受け取り、代替画像がある場合だけ `TMSWP\Core\Post\Thumbnail` DTO を返す。それ以外の戻り値は無視される。

本文先頭の `<img src>` を使う場合は、抽出規則を app 側の要件に合わせて実装する。`width`・`height` には画像の実寸を渡す。`$size` は要求したサイズであり、画像の実寸ではないため、そのまま渡さない。メディアライブラリの画像(ブロックエディタが付ける `wp-image-<ID>` クラスを持つ画像)なら `wp_get_attachment_image_src()` で要求したサイズの URL と実寸を取り、それ以外の画像は実寸が分からないので `null` を渡す(`img()` は `width`・`height` を出力しない)。遅延読み込み属性や `srcset` を優先したい場合も、このクラス内で拡張する。

`Thumbnail::img()` は `src` を `esc_url()` でエスケープし、許可されないプロトコル(`javascript:` 等)の URL は例外にする。フィルタで返す URL は `esc_url_raw()` 等で `http`・`https` に絞っておく。

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

        if (preg_match('/<img\s[^>]*>/i', $post->post_content, $tag) !== 1) {
            return null;
        }

        // メディアライブラリの画像は、要求したサイズの URL と実寸を使う
        if (preg_match('/\bwp-image-(\d+)\b/', $tag[0], $id) === 1) {
            $src = wp_get_attachment_image_src((int) $id[1], $size);

            if (is_array($src)) {
                return new Thumbnail($src[0], $src[1], $src[2], '');
            }
        }

        if (preg_match('/\ssrc=["\']([^"\']+)["\']/i', $tag[0], $matches) !== 1) {
            return null;
        }

        $url = esc_url_raw($matches[1], ['http', 'https']);

        if ($url === '') {
            return null;
        }

        // メディアライブラリに無い画像は実寸が分からないため、width・height を渡さない
        return new Thumbnail($url, null, null, '');
    }
}
```

## してはいけないこと

- `register_post_type()` や `add_action('init', ...)` を自分で書かない。
- 件数を絞りたいクエリで `limit()` を省かない(省くと全件を読み込む)。
- `definition()` の中で WP 関数や `__()` を呼ばない。Kernel が boot の時点(init より前)でメタスキーマの登録のために呼び、結果を使い回す。
- `functions.php` に投稿タイプ関連のロジックを書かない。
- 1クラスで複数投稿タイプを定義しない。

## 確認方法

1. `composer qa` が通ること。
2. 管理画面に投稿タイプが表示されること。
3. 本番で表示されない場合は `wp tmswp discover --check` で探索結果ファイルが最新かを確かめ、古ければ `wp tmswp discover` で作り直してコミットする。
