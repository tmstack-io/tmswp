// サムネイル(tmswp/thumbnail)。トップページの製品のカードのアイキャッチ(parts/product-card.phpのtms_thumb())。
// 生成の前はRESTのURL(/wp-json/tmswp/v1/thumb?...)で、302で生成したファイルの静的なURLへ移る。生成の後は、
// 静的なURL(/wp-content/uploads/tmswp-thumb/...)を直接出す。署名の誤った要求・署名の無い要求は403になる。
//
// テストの始めに`wp tmswp thumb clear`でキャッシュを消し、生成の前の状態から確かめる(何回実行しても同じ順で進む)。

import { expect, findPhpError, test } from './support/test.mjs';
import { cli } from './support/wp-env.mjs';

const REST_URL = /^http:\/\/localhost:8882\/wp-json\/tmswp\/v1\/thumb\?/;
const STATIC_URL = /^http:\/\/localhost:8882\/wp-content\/uploads\/tmswp-thumb\/[0-9a-f]{2}\/[0-9a-f]+\.jpg$/;

/**
 * トップページのHTMLを(画像を読み込まずに)取り、製品のカードの画像のURLを返す。
 *
 * ブラウザで開くと、画像の読み込みがテストの要求と同時に生成を始めるため、生成の前のURLはHTMLだけで確かめる。
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @returns {Promise<string>}
 */
async function productCardImageUrl(request) {
    const response = await request.get('/');
    const html = await response.text();

    expect(response.status()).toBe(200);
    expect(findPhpError(html)).toBeNull();

    const match = /<article class="product-card">[\s\S]*?<img src="([^"]+)"/.exec(html);
    expect(match, 'the product card has an image').not.toBeNull();

    return match[1].replaceAll('&#038;', '&').replaceAll('&amp;', '&');
}

/**
 * 画像の応答であることを確かめる。
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {string} url
 */
async function expectJpeg(request, url) {
    const response = await request.get(url, { maxRedirects: 0 });

    expect(response.status(), url).toBe(200);
    expect(response.headers()['content-type'], url).toBe('image/jpeg');
    expect((await response.body()).subarray(0, 3), url).toEqual(Buffer.from([0xff, 0xd8, 0xff]));
}

test.describe('Thumbnail', () => {
    test.beforeEach(() => {
        cli('wp', 'tmswp', 'thumb', 'clear');
    });

    test('serves the product card image through the REST URL before generation and the static URL after generation', async ({ page, request }) => {
        // 生成の前: RESTのURLが302で生成したファイルの静的なURLへ移り、移った先が画像を返す
        const restUrl = await productCardImageUrl(request);
        expect(restUrl).toMatch(REST_URL);

        const redirect = await request.get(restUrl, { maxRedirects: 0 });
        expect(redirect.status()).toBe(302);
        // 生成したファイルへの302は、短期間(ThumbnailConfigのredirectMaxAge。既定の3600秒)だけキャッシュさせる
        expect(redirect.headers()['cache-control']).toBe('public, max-age=3600');
        const location = redirect.headers().location;
        expect(location).toMatch(STATIC_URL);
        await expectJpeg(request, location);

        // 生成の後: カードは静的なURLを直接出し、ブラウザで画像が表示される
        await page.goto('/');
        const image = page.locator('.product-card img').first();
        await expect(image).toHaveAttribute('src', location);
        await expect.poll(() => image.evaluate((img) => img.complete && img.naturalWidth)).toBeGreaterThan(0);
        await expectJpeg(request, location);
    });

    test('returns 403 for a tampered or missing signature', async ({ request }) => {
        const restUrl = new URL(await productCardImageUrl(request));
        expect(restUrl.href).toMatch(REST_URL);
        const signature = restUrl.searchParams.get('s') ?? '';
        expect(signature).toMatch(/^[0-9a-f]{64}$/);

        const tamperedSignature = new URL(restUrl);
        tamperedSignature.searchParams.set('s', `${signature.slice(0, -1)}${signature.endsWith('0') ? '1' : '0'}`);

        const tamperedWidth = new URL(restUrl);
        tamperedWidth.searchParams.set('w', '481');

        const unsigned = new URL(restUrl);
        unsigned.searchParams.delete('s');

        for (const url of [tamperedSignature, tamperedWidth, unsigned]) {
            const response = await request.get(url.href, { maxRedirects: 0 });

            expect(response.status(), url.href).toBe(403);
            expect(response.headers()['content-type'] ?? '', url.href).not.toContain('image/');
        }
    });
});
