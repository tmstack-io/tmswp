<?php

declare(strict_types=1);

if (!function_exists('tms_thumb')) {
    /** @param array<string, mixed> $options */
    function tms_thumb(int|string $src, ?int $width = null, ?int $height = null, array $options = []): string
    {
        return TMSWP\Thumbnail\Thumbnail::url($src, $width, $height, $options);
    }
}
