<?php

declare(strict_types=1);

use SampleSite\Page\FrontPage;
use SampleSite\Post\ProductPost;
use TMSWP\Core\View\Template;

// 出力の前(get_header()より前)に1回だけ呼ぶ
$vm = FrontPage::handle();

get_header();
?>
<main>
    <section class="hero">
        <h1><?php echo esc_html(get_bloginfo('name')); ?></h1>
        <?php if ($vm->leadText !== '') : ?>
            <p><?php echo esc_html($vm->leadText); ?></p>
        <?php endif; ?>
    </section>

    <section>
        <h2><?php esc_html_e('New products', 'sample-site'); ?></h2>
        <?php if ($vm->products !== []) : ?>
            <div class="product-grid">
                <?php foreach ($vm->products as $product) : ?>
                    <?php Template::part('parts/product-card', ['post' => $product]); ?>
                <?php endforeach; ?>
            </div>
            <p><a href="<?php echo esc_url((string) ProductPost::archiveUrl()); ?>"><?php esc_html_e('All products', 'sample-site'); ?></a></p>
        <?php else : ?>
            <p><?php esc_html_e('No products yet.', 'sample-site'); ?></p>
        <?php endif; ?>
    </section>
</main>
<?php
get_footer();
