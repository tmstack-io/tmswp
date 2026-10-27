<?php

declare(strict_types=1);

/*
 * wp-envのサイトに、sample-siteの機能を確かめるためのページと製品を作る(`composer wp:setup`が`wp eval`でrequireする。`wp eval-file`はファイルをevalで読むため、declare(strict_types=1)の宣言で止まる)。
 * テーマを有効にした後に実行する。作り済みのものは作り直さないため、何回実行してもよい。テーマには含めない開発用のファイル。
 *
 * - 固定ページ: お問い合わせ(/contact/)と完了画面(/contact/thanks/)、ブロックsample-site/latest-productsを置いたページ(/blocks/)
 * - 製品: アイキャッチ・価格(MetaBag)・仕様(ACFの生成アクセサ)付きの1件。日本語のタイトルのスラッグはja-toolkitが置き換える
 * - サイト設定: トップページの説明文(SiteOption)
 */

use SampleSite\Acf\ProductFields;
use SampleSite\Option\SiteOption;
use SampleSite\Post\ProductPost;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

/**
 * スラッグ(親のパスを含む)の固定ページが無ければ作り、IDを返す。
 *
 * @throws RuntimeException 作れなかった場合
 */
function sample_site_seed_page(string $path, string $title, string $content, int $parent = 0): int
{
    $page = get_page_by_path($path);

    if ($page instanceof WP_Post) {
        return $page->ID;
    }

    $id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => basename($path),
        'post_content' => $content,
        'post_parent' => $parent,
    ], true);

    if ($id instanceof WP_Error) {
        throw new RuntimeException("cannot create the page {$path}: " . $id->get_error_message());
    }

    echo "created page: /{$path}/\n";

    return $id;
}

/**
 * dev/sample-product.jpgをメディアに取り込み、添付ファイルのIDを返す。
 *
 * @throws RuntimeException 取り込めなかった場合
 */
function sample_site_seed_image(int $postId): int
{
    // media_handle_sideload()は渡したファイルを移動するため、写しを渡す
    $tmp = wp_tempnam('sample-product.jpg');

    if (!copy(__DIR__ . '/sample-product.jpg', $tmp)) {
        throw new RuntimeException('cannot copy dev/sample-product.jpg');
    }

    $id = media_handle_sideload(['name' => 'sample-product.jpg', 'tmp_name' => $tmp], $postId);

    if ($id instanceof WP_Error) {
        throw new RuntimeException('cannot import dev/sample-product.jpg: ' . $id->get_error_message());
    }

    return $id;
}

// パーマリンクを投稿名にする(/contact/等のURLで開くため)
if (get_option('permalink_structure') !== '/%postname%/') {
    update_option('permalink_structure', '/%postname%/');
    echo "set permalink structure: /%postname%/\n";
}

flush_rewrite_rules(false);

$contact = sample_site_seed_page('contact', 'お問い合わせ', '');
sample_site_seed_page('contact/thanks', '送信しました', '<!-- wp:paragraph --><p>お問い合わせを受け付けました。</p><!-- /wp:paragraph -->', $contact);
sample_site_seed_page('blocks', 'ブロックの例', '<!-- wp:sample-site/latest-products {"count":3} /-->');

if (SiteOption::leadText() === '') {
    SiteOption::LeadText->set('TMStack WordPress Frameworkの主な機能を動かすサンプルのサイトです。');
    echo "set lead text\n";
}

if (ProductPost::query()->status('publish')->limit(1)->get() === []) {
    $productId = ProductPost::create([
        // 下書きで作ってから公開する。ja-toolkitのJapaneseSlugは、IDの決まった投稿の公開の時点でスラッグを置き換える
        // (1回のwp_insert_post()で公開すると、IDが決まる前にスラッグが決まり、日本語のスラッグのまま残る)
        'post_status' => 'draft',
        'post_title' => 'サンプル製品',
        'post_excerpt' => 'アイキャッチ・価格・仕様を持つ製品の例です。',
        'post_content' => '<!-- wp:paragraph --><p>製品の説明です。</p><!-- /wp:paragraph -->',
    ]);
    wp_update_post(['ID' => $productId, 'post_status' => 'publish']);
    set_post_thumbnail($productId, sample_site_seed_image($productId));
    // 単純な値はMetaBagで、複合型(group)はACFの生成アクセサで書く(vendor/tmswp/core/rules/acf.md)
    ProductPost::meta($productId)->set(ProductPost::META_PRICE, 12800);
    ProductFields::of($productId)->setSpec(['material' => 'ステンレス', 'size' => 'W120 × H80 mm']);
    echo 'created product: ' . get_permalink($productId) . "\n";
}
