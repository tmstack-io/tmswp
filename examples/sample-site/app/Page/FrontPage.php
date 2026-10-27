<?php

declare(strict_types=1);

namespace SampleSite\Page;

use SampleSite\Option\SiteOption;
use SampleSite\Post\ProductPost;
use WP_Post;

/** front-page.phpの前処理。設定ページで入力した説明文と、新しい製品を用意する */
final class FrontPage
{
    /** @param list<WP_Post> $products */
    private function __construct(
        public readonly string $leadText,
        public readonly array $products,
    ) {}

    public static function handle(): self
    {
        $products = ProductPost::query()->status('publish')->orderByDesc('date')->limit(6)->get();

        return new self(SiteOption::leadText(), $products);
    }
}
