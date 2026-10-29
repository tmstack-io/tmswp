// ACF(tmswp/acf-bridge)。製品の詳細に、価格(#[MetaSchema]とAcfMetaStorageでMetaBagから)と仕様(生成アクセサ
// ProductFieldsから)が出る。製品の編集画面のACFのフィールドで値を変えると、表示が変わる。CIは無料版のACF。
// 変えた値はテストの終わりに元に戻す。

import { expect, login, openBlockEditor, test } from './support/test.mjs';
import { getPostMeta, restorePostMeta, seededProductId } from './support/wp-env.mjs';

const PRICE = 'input[name="acf[field_sample_site_product_price]"]';
const MATERIAL = 'input[name="acf[field_sample_site_product_spec][field_sample_site_product_spec_material]"]';

/**
 * 製品の詳細の、見出し(dt)に続く値(dd)。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} term
 */
function fact(page, term) {
    return page.locator('.product__facts dt', { hasText: term }).locator('xpath=following-sibling::dd[1]');
}

test.describe('ACF', () => {
    test('shows the ACF price and spec on the product page', async ({ page }) => {
        const productId = seededProductId();
        await page.goto(`/product/product-${productId}/`);

        await expect(fact(page, 'Price')).toHaveText('12,800円');
        await expect(fact(page, 'Material')).toHaveText('ステンレス');
        await expect(fact(page, 'Size')).toHaveText('W120 × H80 mm');
    });

    test('reflects the values changed on the product edit screen', async ({ page }) => {
        const productId = seededProductId();
        const originalPrice = getPostMeta(productId, 'price');
        const originalMaterial = getPostMeta(productId, 'spec_material');

        try {
            await login(page);
            await openBlockEditor(page, `/wp-admin/post.php?post=${productId}&action=edit`);

            // メタボックスの欄が畳まれていれば開く(開閉のボタンの上には、欄の高さを変える取っ手が重なっているため、
            // マウスではなくキーボードで押す)
            const metaBoxes = page.getByRole('button', { name: 'Meta Boxes' });
            if ((await metaBoxes.getAttribute('aria-expanded')) === 'false') {
                await metaBoxes.focus();
                await page.keyboard.press('Enter');
                await expect(metaBoxes).toHaveAttribute('aria-expanded', 'true');
            }

            await page.locator(PRICE).fill('9800');
            await page.locator(MATERIAL).fill('E2Eの素材');

            // 投稿の保存(REST)の後に、ACFのメタボックスの値を別の要求(post.phpのmeta-box-loader)で送る
            const metaBoxSaved = page.waitForResponse((response) => response.url().includes('meta-box-loader=1') && response.request().method() === 'POST');
            await page.getByRole('region', { name: 'Editor top bar' }).getByRole('button', { name: 'Save', exact: true }).click();
            await metaBoxSaved;
            await page.waitForFunction(() => !window.wp.data.select('core/edit-post').isSavingMetaBoxes() && !window.wp.data.select('core/editor').isSavingPost());

            await page.goto(`/product/product-${productId}/`);
            await expect(fact(page, 'Price')).toHaveText('9,800円');
            await expect(fact(page, 'Material')).toHaveText('E2Eの素材');
            await expect(fact(page, 'Size')).toHaveText('W120 × H80 mm');
        } finally {
            restorePostMeta(productId, 'price', originalPrice);
            restorePostMeta(productId, 'spec_material', originalMaterial);
        }
    });
});
