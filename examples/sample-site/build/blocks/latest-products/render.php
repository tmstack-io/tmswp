<?php

declare(strict_types=1);

/*
 * ブロックsample-site/latest-productsの動的描画。WordPressがblock.jsonのrenderから読み込み、$attributes・$content・$blockを渡す。
 */

use SampleSite\Post\ProductPost;
use TMSWP\Core\View\Template;

$count = is_int($attributes['count'] ?? null) ? max(1, min(12, $attributes['count'])) : 3;
$products = ProductPost::query()->status('publish')->orderByDesc('date')->limit($count)->get();
?>
<div <?php echo get_block_wrapper_attributes(['class' => 'product-grid']); ?>>
    <?php foreach ($products as $product) : ?>
        <?php Template::part('parts/product-card', ['post' => $product]); ?>
    <?php endforeach; ?>
</div>
