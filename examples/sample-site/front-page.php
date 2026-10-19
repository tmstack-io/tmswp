<?php

declare(strict_types=1);

use App\Option\SiteOption;
use App\Post\ProductPost;
use TMSWP\Core\View\Template;

$products = ProductPost::query()->status('publish')->orderByDesc('date')->limit(6)->get();

get_header();
?>
<main class="site-main">
    <section class="hero">
        <p class="hero__eyebrow">TMStack WordPress Framework</p>
        <h1>Sample Site</h1>
        <p><?php echo esc_html(SiteOption::leadText()); ?></p>
        <a class="button" href="<?php echo esc_url((string) ProductPost::archiveUrl()); ?>">製品を見る</a>
    </section>

    <section class="section">
        <div class="section__header">
            <h2>製品一覧</h2>
            <a href="<?php echo esc_url((string) ProductPost::archiveUrl()); ?>">すべて見る</a>
        </div>
        <?php if ($products !== []) : ?>
            <div class="product-grid">
                <?php foreach ($products as $product) : ?>
                    <?php Template::part('parts/product-card', ['post' => $product]); ?>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p>製品がありません。</p>
        <?php endif; ?>
    </section>
</main>
<?php
get_footer();
