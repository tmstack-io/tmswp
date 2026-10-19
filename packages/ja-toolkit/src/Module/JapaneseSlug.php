<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Module;

use TMSWP\JaToolkit\ModuleInterface;

final class JapaneseSlug implements ModuleInterface
{
    public function __construct(private readonly string $format = '{post_type}-{post_id}') {}

    public function register(): void
    {
        add_filter('wp_unique_post_slug', [$this, 'filterSlug'], 10, 4);
    }

    public function filterSlug(string $slug, int $postId, string $postStatus, string $postType): string
    {
        if (preg_match('/(%[0-9a-f]{2})+/i', $slug) !== 1 || $postId === 0) {
            return $slug;
        }

        return str_replace(['{post_type}', '{post_id}'], [$postType, (string) $postId], $this->format);
    }
}
