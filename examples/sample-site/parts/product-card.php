<?php

declare(strict_types=1);

use App\Post\ProductPost;

/** @var array{post: WP_Post} $args */
$post = $args['post'];
$url = ProductPost::permalink($post);
?>
<article class="product-card">
    <a class="product-card__link" href="<?php echo esc_url((string) $url); ?>">
        <span class="product-card__title"><?php echo esc_html(ProductPost::title($post)); ?></span>
        <span class="product-card__meta">
            <?php echo esc_html(number_format(ProductPost::price($post))); ?>円 /
            SKU: <?php echo esc_html(ProductPost::sku($post)); ?>
        </span>
    </a>
</article>
