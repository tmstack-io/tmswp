// 設定ページ(tmswp/settings-page。管理画面の「設定 > Sample Site」。app/SettingsPage/SiteSettingsPage.php)。
// 保存した値はテストの終わりに元に戻す。

import { expect, login, test } from './support/test.mjs';
import { cli, getOption, php, restoreOption } from './support/wp-env.mjs';

const SETTINGS_URL = '/wp-admin/options-general.php?page=sample-site-settings';
const CONTACT_EMAIL = 'sample_site_contact_email';
const LEAD_TEXT = 'sample_site_lead_text';

/**
 * 保存のボタンを押し、フォームの送信(POST)の応答を返す。
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<import('@playwright/test').Response>}
 */
async function submitSettings(page) {
    const [response] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('page=sample-site-settings')),
        page.getByRole('button', { name: 'Save Changes' }).click(),
    ]);

    await page.waitForLoadState();

    return response;
}

test.describe('Settings page', () => {
    /** @type {unknown} */
    let originalEmail;
    /** @type {unknown} */
    let originalLead;

    test.beforeEach(() => {
        originalEmail = getOption(CONTACT_EMAIL);
        originalLead = getOption(LEAD_TEXT);
    });

    test.afterEach(() => {
        restoreOption(CONTACT_EMAIL, originalEmail);
        restoreOption(LEAD_TEXT, originalLead);
    });

    test('saves the notification email and lead text, and shows the lead text on the front page', async ({ page }) => {
        const lead = `E2Eで保存した説明文です(${Date.now()})。`;
        await login(page);
        await page.goto(SETTINGS_URL);

        await page.getByLabel('Notification email for inquiries').fill('e2e-notify@example.com');
        await page.getByLabel('Lead text on the front page').fill(lead);
        const saved = await submitSettings(page);

        // 保存の後は、tmswp-settings-saved=1のURLへリダイレクトし(PRG)、成功の通知がちょうど1つ出て、保存した値が
        // フォームに出る。WordPress本体の`updated`は使わない(親がoptions-general.phpのページで、本体も通知を出すため)
        expect(saved.status()).toBe(302);
        expect(saved.headers().location).toMatch(/[?&]tmswp-settings-saved=1(&|$)/);
        expect(saved.headers().location).not.toMatch(/[?&]updated=/);
        await expect(page.locator('.notice', { hasText: 'Settings saved.' })).toHaveCount(1);
        await expect(page.getByLabel('Notification email for inquiries')).toHaveValue('e2e-notify@example.com');
        await expect(page.getByLabel('Lead text on the front page')).toHaveValue(lead);
        expect(getOption(CONTACT_EMAIL)).toBe('e2e-notify@example.com');

        // 表示の後に、WordPressがアドレスバーのURLからクエリ変数を除く(removable_query_args)。再読み込みでは通知を出さない
        await expect(page).not.toHaveURL(/tmswp-settings-saved/);
        await page.reload();
        await expect(page.locator('.notice', { hasText: 'Settings saved.' })).toHaveCount(0);

        await page.goto('/');
        await expect(page.locator('.hero p')).toHaveText(lead);
    });

    test('rejects a notification email that is not an email address', async ({ page }) => {
        await login(page);
        await page.goto(SETTINGS_URL);

        // ブラウザの入力の検証(type="email")を外し、サーバーの検証を確かめる
        await page.locator('form:has(button:text("Save Changes"))').evaluate((form) => {
            form.noValidate = true;
        });
        await page.getByLabel('Notification email for inquiries').fill('not-an-email');
        await page.getByLabel('Lead text on the front page').fill('保存されない説明文です。');
        const rejected = await submitSettings(page);

        // リダイレクトせずに、同じ応答でエラーを出す
        expect(rejected.status()).toBe(200);
        await expect(page.getByText('The settings were not saved. Please check the errors in the fields.')).toBeVisible();
        await expect(page.locator('.form-error')).toHaveCount(1);
        // 1つでも誤りがあれば、どの値も保存しない
        expect(getOption(CONTACT_EMAIL)).toEqual(originalEmail);
        expect(getOption(LEAD_TEXT)).toEqual(originalLead);
    });

    test('denies access to a user without manage_options', async ({ page }) => {
        const subscriber = 'e2e-subscriber';
        const userId = php(
            "username_exists($params['login']) ? wp_update_user(['ID' => username_exists($params['login']), 'user_pass' => $params['pass'], 'role' => 'subscriber']) : wp_insert_user(['user_login' => $params['login'], 'user_pass' => $params['pass'], 'user_email' => $params['email'], 'role' => 'subscriber'])",
            { login: subscriber, pass: 'password', email: 'e2e-subscriber@example.com' },
        );
        expect(typeof userId, 'create the subscriber').toBe('number');

        try {
            await login(page, subscriber, 'password');
            const response = await page.goto(SETTINGS_URL);

            expect(response?.status()).toBe(403);
            await expect(page.getByText('Sorry, you are not allowed to access this page.')).toBeVisible();
            await expect(page.getByLabel('Notification email for inquiries')).toHaveCount(0);
        } finally {
            cli('wp', 'user', 'delete', subscriber, '--yes');
        }
    });
});
