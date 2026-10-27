<?php

declare(strict_types=1);

namespace SampleSite\Hook;

use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class ThemeSetupHook extends Hook
{
    #[Action('after_setup_theme')]
    public function setupTheme(): void
    {
        add_theme_support('post-thumbnails');
        add_theme_support('title-tag');
        add_theme_support('html5', ['search-form', 'gallery', 'caption']);
    }

    #[Action('wp_enqueue_scripts')]
    public function enqueueAssets(): void
    {
        wp_enqueue_style(
            'app-main',
            get_theme_file_uri('assets/css/main.css'),
            [],
            (string) wp_get_theme()->get('Version'),
        );
    }

    #[Filter('excerpt_more')]
    public function excerptMore(string $more): string
    {
        return '...';
    }
}
