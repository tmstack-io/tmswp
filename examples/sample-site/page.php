<?php

declare(strict_types=1);

/*
 * 固定ページ。本文のブロック(sample-site/latest-products等)を描画する。
 */

get_header();
?>
<main>
    <?php while (have_posts()) : ?>
        <?php the_post(); ?>
        <article>
            <h1><?php the_title(); ?></h1>
            <div class="entry-content"><?php the_content(); ?></div>
        </article>
    <?php endwhile; ?>
</main>
<?php
get_footer();
