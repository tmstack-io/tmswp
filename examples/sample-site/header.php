<?php

declare(strict_types=1);

use SampleSite\Post\ProductPost;

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="site-header">
    <a class="site-title" href="<?php echo esc_url(home_url('/')); ?>">
        <?php echo esc_html(get_bloginfo('name')); ?>
    </a>
    <nav class="site-nav">
        <a href="<?php echo esc_url((string) ProductPost::archiveUrl()); ?>"><?php esc_html_e('Products', 'sample-site'); ?></a>
        <a href="<?php echo esc_url(home_url('/contact/')); ?>"><?php esc_html_e('Contact', 'sample-site'); ?></a>
    </nav>
</header>
