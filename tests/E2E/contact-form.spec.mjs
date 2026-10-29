// お問い合わせフォーム(/contact/。app/Form/ContactForm.php)。入力・確認・完了、必須の検証、メールの記録、
// 再読み込みと再送でメールが増えないこと、キャッシュさせないヘッダ。
//
// 記録したメール(sample_site_captured_mails)は直近の10通だけが残るため、件数ではなく、テストごとに本文へ入れる
// 一意の印で数える。何回実行しても、前の実行のメールと混ざらない。

import { expect, findPhpError, test } from './support/test.mjs';
import { capturedMails } from './support/wp-env.mjs';

/**
 * 本文に印を含む、記録したメール。
 *
 * @param {string} marker
 */
function mailsWith(marker) {
    return capturedMails().filter((mail) => mail.message.includes(marker));
}

/**
 * 入力画面を埋めて確認画面へ進む。
 *
 * @param {import('@playwright/test').Page} page
 * @param {{fullName: string, kana: string, email: string, message: string}} values
 */
async function fillAndConfirm(page, values) {
    await page.goto('/contact/');
    await page.getByLabel('Name', { exact: true }).fill(values.fullName);
    await page.getByLabel('Name (katakana)').fill(values.kana);
    await page.getByLabel('Email address').fill(values.email);
    await page.getByLabel('Message').fill(values.message);
    await page.getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByText('Please check your entries and press Send.')).toBeVisible();
}

test.describe('Contact form', () => {
    test('shows errors and stays on the input screen when required fields are empty', async ({ page }) => {
        await page.goto('/contact/');
        await page.getByRole('button', { name: 'Confirm' }).click();

        // 入力画面のまま(確認画面の案内と送信のボタンが無い)で、4つの必須の項目にエラーが出る
        await expect(page.getByRole('button', { name: 'Confirm' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Send' })).toHaveCount(0);
        await expect(page.getByText('Please check your entries and press Send.')).toHaveCount(0);
        await expect(page.locator('.contact-form .form-error')).toHaveCount(4);
    });

    test('submits through input, confirm and complete, then records one mail', async ({ page }) => {
        const marker = `e2e-submit-${Date.now()}`;
        await fillAndConfirm(page, {
            fullName: 'E2E 太郎',
            kana: 'イーツーイー タロウ',
            email: 'e2e@example.com',
            message: `確認のための送信です。${marker}`,
        });

        // 確認画面に入力した値が出る
        const confirm = page.locator('.contact dl');
        await expect(confirm).toContainText('E2E 太郎');
        await expect(confirm).toContainText('e2e@example.com');
        await expect(confirm).toContainText(marker);

        await page.getByRole('button', { name: 'Send' }).click();
        await expect(page).toHaveURL(/\/contact\/thanks\/$/);
        await expect(page.locator('body')).toContainText('お問い合わせを受け付けました。');

        const mails = mailsWith(marker);
        expect(mails).toHaveLength(1);
        expect(mails[0].subject).toContain('Inquiry from E2E 太郎');
        expect(mails[0].message).toContain('Name (katakana): イーツーイー タロウ');
        expect(mails[0].message).toContain('Email address: e2e@example.com');
    });

    test('does not send the mail again on reloading the thanks page or resubmitting the confirm screen', async ({ page }) => {
        const marker = `e2e-resubmit-${Date.now()}`;
        await fillAndConfirm(page, {
            fullName: 'E2E 二重',
            kana: 'ニジュウ',
            email: 'e2e@example.com',
            message: `二重送信の確認です。${marker}`,
        });

        // 確認画面の送信の内容(トークンを含む)を取っておき、送信の後にもう一度送る
        const resubmission = await page.locator('form.contact-form').evaluate((form) => {
            const data = Object.fromEntries(new FormData(form));

            return { ...data, _step: 'submit' };
        });

        await page.getByRole('button', { name: 'Send' }).click();
        await expect(page).toHaveURL(/\/contact\/thanks\/$/);
        expect(mailsWith(marker)).toHaveLength(1);

        // 完了画面の再読み込み
        const reloaded = await page.reload();
        expect(reloaded?.status()).toBe(200);
        expect(mailsWith(marker)).toHaveLength(1);

        // 同じ確認画面からの再送(同じクッキーで、同じトークンをPOSTする)は、送信済みとして拒まれる
        const response = await page.request.post('/contact/', { form: resubmission, maxRedirects: 0 });
        const html = await response.text();
        expect(response.status()).toBe(200);
        expect(findPhpError(html)).toBeNull();
        expect(html).toContain('This form has already been submitted.');
        expect(mailsWith(marker)).toHaveLength(1);
    });

    test('sends no-store cache headers on /contact/', async ({ page }) => {
        const response = await page.goto('/contact/');

        expect(response?.status()).toBe(200);
        expect(response?.headers()['cache-control']).toContain('no-store');
    });
});
