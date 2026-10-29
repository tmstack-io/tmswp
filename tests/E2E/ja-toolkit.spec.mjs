// 日本語対応(tmswp/ja-toolkit)。JapaneseSlug(日本語のタイトルのスラッグを{post_type}-{post_id}にする)と、
// お問い合わせフォームのKanaTextField(フリガナ)。

import { expect, test } from './support/test.mjs';
import { php, seededProductId } from './support/wp-env.mjs';

test.describe('Japanese toolkit', () => {
    test('replaces the slug of a product with a Japanese title by product-<ID>', async ({ page }) => {
        const productId = seededProductId();
        const product = php("['title' => get_the_title($params['id']), 'slug' => get_post_field('post_name', $params['id'])]", { id: productId });

        expect(product).toEqual({ title: 'サンプル製品', slug: `product-${productId}` });

        // トップページのカードのリンクから、/product/product-<ID>/の詳細ページが開く
        await page.goto('/');
        await page.locator('.product-card a').first().click();
        await expect(page).toHaveURL(`/product/product-${productId}/`);
        await expect(page.locator('h1')).toHaveText('サンプル製品');
    });

    test('redirects /?p=<ID> to the product permalink with 301', async ({ request }) => {
        const productId = seededProductId();

        const redirect = await request.get(`/?p=${productId}`, { maxRedirects: 0 });
        expect(redirect.status()).toBe(301);
        expect(redirect.headers().location).toBe(`http://localhost:8882/product/product-${productId}/`);

        const target = await request.get(redirect.headers().location, { maxRedirects: 0 });
        expect(target.status()).toBe(200);
    });

    test('converts hiragana and half-width katakana to full-width katakana on the confirm screen', async ({ page }) => {
        await page.goto('/contact/');
        await page.getByLabel('Name', { exact: true }).fill('E2E カナ');
        await page.getByLabel('Name (katakana)').fill('やまだ ﾀﾛｳ ｶﾞｯｺｳ');
        await page.getByLabel('Email address').fill('e2e@example.com');
        await page.getByLabel('Message').fill('フリガナの変換の確認です(送信しない)。');
        await page.getByRole('button', { name: 'Confirm' }).click();

        await expect(page.getByText('Please check your entries and press Send.')).toBeVisible();
        const kana = page.locator('.contact dl dt', { hasText: 'Name (katakana)' }).locator('xpath=following-sibling::dd[1]');
        await expect(kana).toHaveText('ヤマダ タロウ ガッコウ');
    });
});
