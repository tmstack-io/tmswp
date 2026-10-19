<?php

declare(strict_types=1);

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
</header>
