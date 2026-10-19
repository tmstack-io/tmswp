<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Module;

use TMSWP\JaToolkit\ModuleInterface;
use WP_Query;

final class FullwidthSearchNormalizer implements ModuleInterface
{
    public function register(): void
    {
        add_action('pre_get_posts', [$this, 'normalize'], 10, 1);
    }

    public function normalize(WP_Query $query): void
    {
        if (!$query->is_search()) {
            return;
        }

        $search = $query->get('s');

        if (!\is_string($search) || $search === '') {
            return;
        }

        $normalized = mb_convert_kana($search, 's', 'UTF-8');
        $parts = array_filter(explode(' ', $normalized), static fn(string $part): bool => $part !== '');

        $query->set('s', implode(' ', $parts));
    }
}
