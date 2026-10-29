<?php

declare(strict_types=1);

use SampleSite\Page\ProductSinglePage;

// 出力の前(get_header()より前)に1回だけ呼ぶ
$vm = ProductSinglePage::handle();

get_header();
?>
<main>
    <article class="product">
        <h1><?php echo esc_html(get_the_title($vm->product)); ?></h1>
        <?php echo get_the_post_thumbnail($vm->product, 'large'); ?>
        <dl class="product__facts">
            <?php if ($vm->price > 0) : ?>
                <dt><?php esc_html_e('Price', 'sample-site'); ?></dt>
                <dd><?php echo esc_html(number_format_i18n($vm->price)); ?>円</dd>
            <?php endif; ?>
            <?php if ($vm->spec !== null) : ?>
                <dt><?php esc_html_e('Material', 'sample-site'); ?></dt>
                <dd><?php echo esc_html($vm->spec['material']); ?></dd>
                <dt><?php esc_html_e('Size', 'sample-site'); ?></dt>
                <dd><?php echo esc_html($vm->spec['size']); ?></dd>
            <?php endif; ?>
        </dl>
        <?php while (have_posts()) : ?>
            <?php the_post(); ?>
            <div class="entry-content"><?php the_content(); ?></div>
        <?php endwhile; ?>
    </article>
</main>
<?php
get_footer();
