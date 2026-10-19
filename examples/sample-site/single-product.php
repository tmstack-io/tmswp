<?php

declare(strict_types=1);

use App\Post\ProductPost;
use App\Tax\ProductCategoryTax;

get_header();
?>
<main class="site-main">
    <?php while (have_posts()) : ?>
        <?php the_post(); ?>
        <?php $post = get_post(); ?>
        <?php if ($post instanceof WP_Post) : ?>
            <article class="product-detail">
                <h1><?php echo esc_html(ProductPost::title($post)); ?></h1>
                <dl class="product-facts">
                    <div>
                        <dt>価格</dt>
                        <dd><?php echo esc_html(number_format(ProductPost::price($post))); ?>円</dd>
                    </div>
                    <div>
                        <dt>SKU</dt>
                        <dd><?php echo esc_html(ProductPost::sku($post)); ?></dd>
                    </div>
                    <?php $term = ProductPost::firstTerm($post, ProductCategoryTax::class); ?>
                    <?php if ($term instanceof WP_Term) : ?>
                        <div>
                            <dt>カテゴリー</dt>
                            <dd><a href="<?php echo esc_url((string) ProductCategoryTax::url($term)); ?>"><?php echo esc_html($term->name); ?></a></dd>
                        </div>
                    <?php endif; ?>
                </dl>
                <div class="entry-content">
                    <?php the_content(); ?>
                </div>
            </article>
        <?php endif; ?>
    <?php endwhile; ?>
</main>
<?php
get_footer();
