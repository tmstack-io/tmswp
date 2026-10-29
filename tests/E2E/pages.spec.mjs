// sample-siteの主なページが200を返し、PHPのエラーの表示とpageerrorが無いこと(後者はsupport/test.mjsが確かめる)。

import { expect, findPhpError, login, test } from './support/test.mjs';
import { seededProductId } from './support/wp-env.mjs';

test.describe('Pages', () => {
    test('front-end pages respond with 200 without PHP errors or page errors', async ({ page }) => {
        const productId = seededProductId();

        for (const path of ['/', '/product/', `/product/product-${productId}/`, '/contact/', '/contact/thanks/', '/blocks/']) {
            const response = await page.goto(path);

            expect(response?.status(), path).toBe(200);
            expect(findPhpError((await response?.text()) ?? ''), path).toBeNull();
        }
    });

    test('admin pages respond with 200 without PHP errors or page errors', async ({ page }) => {
        const productId = seededProductId();
        await login(page);

        // ダッシュボード(/wp-admin/)は、開くたびにクイックドラフトの下書きを作るため含めない
        for (const path of [
            '/wp-admin/options-general.php?page=sample-site-settings',
            '/wp-admin/edit.php?post_type=product',
            `/wp-admin/post.php?post=${productId}&action=edit`,
        ]) {
            const response = await page.goto(path);

            expect(response?.status(), path).toBe(200);
            expect(findPhpError((await response?.text()) ?? ''), path).toBeNull();
        }
    });
});
