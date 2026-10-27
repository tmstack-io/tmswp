<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Module;

use TMSWP\JaToolkit\ModuleInterface;
use WP_Query;

/**
 * 検索語の全角スペースを半角にし、連続するスペースを1つにまとめる。空白だけの検索語は、0件の検索にする。
 */
final class FullwidthSearchNormalizer implements ModuleInterface
{
    public function register(): void
    {
        add_action('pre_get_posts', [$this, 'normalize'], 10, 1);
    }

    /**
     * 検索のクエリ(メインとサブを問わない)の`s`を正規化する。
     *
     * 正規化すると空になる(全角・半角のスペースだけの)検索語は、`s`を空文字にし、`post__in`を`[0]`にして0件にする。
     * `s`が空文字の検索はWordPressが検索の条件を付けず、全件になるためである。is_search()は真のまま残る。
     */
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

        if ($parts === []) {
            $query->set('post__in', [0]);
        }
    }
}
