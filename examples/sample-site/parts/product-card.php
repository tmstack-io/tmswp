<?php

declare(strict_types=1);

/*
 * 製品のカード。Template::part('parts/product-card', ['post' => $post])で読む。
 * front-page.phpと、ブロックsample-site/latest-products(blocks/latest-products/render.php)が使う。
 */

use SampleSite\Post\ProductPost;
use TMSWP\Core\Exception\LogicException;

// Template::part()のテンプレートから見える変数は$argsだけ。型を確かめてから使う
$post = $args['post'] ?? null;

if (!$post instanceof WP_Post) {
    throw new LogicException("parts/product-card.php requires a WP_Post in \$args['post']");
}

// tmswp/thumbnailの署名付きのサムネイルのURL。アイキャッチが無ければnull(imgを出さない)
$thumb = tms_thumb((int) get_post_thumbnail_id($post), 480, 320);
$price = ProductPost::price($post);
?>
<article class="product-card">
    <a href="<?php echo esc_url((string) ProductPost::permalink($post)); ?>">
        <?php if ($thumb !== null) : ?>
            <img src="<?php echo esc_url($thumb); ?>" alt="" width="480" height="320" loading="lazy">
        <?php endif; ?>
        <span class="product-card__title"><?php echo esc_html(ProductPost::title($post)); ?></span>
        <?php if ($price > 0) : ?>
            <span class="product-card__price"><?php echo esc_html(number_format_i18n($price)); ?>円</span>
        <?php endif; ?>
    </a>
</article>
