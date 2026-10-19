<?php

declare(strict_types=1);

use TMSWP\Core\View\Template;

get_header();
?>
<main class="site-main">
    <h1><?php single_term_title(); ?></h1>
    <?php if (have_posts()) : ?>
        <div class="product-grid">
            <?php while (have_posts()) : ?>
                <?php the_post(); ?>
                <?php Template::part('parts/product-card', ['post' => get_post()]); ?>
            <?php endwhile; ?>
        </div>
        <?php the_posts_pagination(); ?>
    <?php else : ?>
        <p>製品がありません。</p>
    <?php endif; ?>
</main>
<?php
get_footer();
