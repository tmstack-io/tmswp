<?php

declare(strict_types=1);

namespace SampleSite\Page;

use LogicException;
use SampleSite\Acf\ProductFields;
use SampleSite\Post\ProductPost;
use WP_Post;

/** single-product.phpの前処理。表示中の製品の価格(MetaBag)と仕様(ACFの生成アクセサ)を用意する */
final class ProductSinglePage
{
    /** @param array{material: string, size: string}|null $spec */
    private function __construct(
        public readonly WP_Post $product,
        public readonly int $price,
        public readonly ?array $spec,
    ) {}

    /**
     * @throws LogicException 製品の詳細ページ以外のテンプレートから呼んだ場合
     */
    public static function handle(): self
    {
        $queried = get_queried_object();
        $product = $queried instanceof WP_Post ? ProductPost::get($queried) : null;

        if ($product === null) {
            throw new LogicException('ProductSinglePage::handle() must be called from the product single template.');
        }

        return new self($product, ProductPost::price($product), ProductFields::of($product)->spec());
    }
}
