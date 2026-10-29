// ブロック。build/blocks/から探索して登録した動的ブロックsample-site/latest-products(カテゴリsample-site)と、
// 製品の編集画面で使えるブロックを限るallowedBlocks()(app/Post/ProductPost.php)。
// エディタで作った下書き(auto-draft)は、テストの終わりに消す。

import { currentPostId, expect, login, openBlockEditor, test } from './support/test.mjs';
import { cli } from './support/wp-env.mjs';

/** app/Post/ProductPost.phpのallowedBlocks()に渡したブロック */
const PRODUCT_ALLOWED_BLOCKS = ['core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/image'];

/**
 * ブロックエディタの挿入の画面(Blocksのタブ)を開く。
 *
 * @param {import('@playwright/test').Page} page
 */
async function openInserter(page) {
    await page.getByRole('region', { name: 'Editor top bar' }).getByRole('button', { name: /block inserter/i }).click();
    await expect(page.getByRole('listbox').first()).toBeVisible();
}

test.describe('Blocks', () => {
    test('renders the latest products block on /blocks/', async ({ page }) => {
        await page.goto('/blocks/');

        const block = page.locator('.wp-block-sample-site-latest-products');
        await expect(block).toHaveCount(1);
        await expect(block.locator('.product-card').first()).toContainText('サンプル製品');
    });

    test('inserts the block from the Sample Site category in the page editor', async ({ page }) => {
        await login(page);
        await openBlockEditor(page, '/wp-admin/post-new.php?post_type=page');
        const postId = await currentPostId(page);

        try {
            await openInserter(page);
            await page.getByRole('listbox', { name: 'Sample Site' }).getByRole('option', { name: 'Latest products' }).click();

            const names = await page.evaluate(() => window.wp.data.select('core/block-editor').getBlocks().map((block) => block.name));
            expect(names).toContain('sample-site/latest-products');

            // エディタの中では、render.phpの表示(製品のカード)をServerSideRenderで出す
            await expect(page.frameLocator('iframe[name="editor-canvas"]').locator('.product-card').first()).toContainText('サンプル製品');
        } finally {
            cli('wp', 'post', 'delete', String(postId), '--force');
        }
    });

    test('limits the product editor to the blocks allowed by allowedBlocks()', async ({ page }) => {
        await login(page);
        await openBlockEditor(page, '/wp-admin/post-new.php?post_type=product');
        const postId = await currentPostId(page);

        try {
            const result = await page.evaluate(() => {
                const editor = window.wp.data.select('core/block-editor');

                return {
                    allowed: editor.getSettings().allowedBlockTypes,
                    inserter: editor.getInserterItems().map((item) => item.name),
                    canInsert: Object.fromEntries(
                        ['core/paragraph', 'core/image', 'core/quote', 'core/group', 'sample-site/latest-products'].map((name) => [name, editor.canInsertBlockType(name)]),
                    ),
                };
            });

            expect([...result.allowed].sort()).toEqual([...PRODUCT_ALLOWED_BLOCKS].sort());
            expect(result.inserter.every((name) => PRODUCT_ALLOWED_BLOCKS.includes(name)), `inserter items: ${result.inserter.join(', ')}`).toBe(true);
            expect(result.canInsert).toEqual({
                'core/paragraph': true,
                'core/image': true,
                'core/quote': false,
                'core/group': false,
                'sample-site/latest-products': false,
            });

            // 挿入の画面にも、許したブロックだけが出る
            await openInserter(page);
            await expect(page.getByRole('option', { name: 'Paragraph', exact: true })).toBeVisible();
            await expect(page.getByRole('option', { name: 'Quote', exact: true })).toHaveCount(0);
            await expect(page.getByRole('listbox', { name: 'Sample Site' })).toHaveCount(0);
        } finally {
            cli('wp', 'post', 'delete', String(postId), '--force');
        }
    });
});
