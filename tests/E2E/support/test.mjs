// E2Eのテストの共通の土台。各specは@playwright/testではなく、このファイルのtestとexpectを使う。
//
// すべてのテストで、ページ(page)が受け取ったHTMLの文書にPHPのエラーの表示が無いことと、ページのJavaScriptの
// 未捕捉の例外(pageerror)が0件であることを、テストの終わりに確かめる。

import { test as base, expect } from '@playwright/test';

export { expect };

/** PHPのエラーの表示(html_errorsの`<b>Warning</b>:`はタグを除くと`Warning:`になる)と、WordPressの致命的なエラーの画面 */
const PHP_ERROR = /\b(?:Fatal error|Parse error|Warning|Notice|Deprecated)\s*:|There has been a critical error on this website/;

/**
 * HTMLから、scriptとstyleの中身とタグを除いた文字列を作り、PHPのエラーの表示を探す。
 *
 * @param {string} html
 * @returns {string|null} 見つけた表示の前後の文字列。無ければnull
 */
export function findPhpError(html) {
    const text = html
        .replace(/<script\b[\s\S]*?<\/script>/gi, ' ')
        .replace(/<style\b[\s\S]*?<\/style>/gi, ' ')
        .replace(/<[^>]+>/g, '');
    const match = PHP_ERROR.exec(text);

    if (match === null) {
        return null;
    }

    return text.slice(Math.max(0, match.index - 40), match.index + 200).replace(/\s+/g, ' ').trim();
}

export const test = base.extend({
    // auto: すべてのテストに付く。文書の応答(リダイレクトを除く)の本文と、pageerrorを集めて、テストの終わりに確かめる
    pageProblems: [
        async ({ page }, use) => {
            /** @type {string[]} */
            const problems = [];
            /** @type {Promise<void>[]} */
            const reads = [];

            page.on('pageerror', (error) => {
                problems.push(`pageerror on ${page.url()}: ${error.message}`);
            });
            page.on('response', (response) => {
                const status = response.status();

                // 対象はページ本体の文書だけ(エディタの中のiframe等の文書は、本文を読めないことがある)
                if (response.request().resourceType() !== 'document' || response.frame() !== page.mainFrame() || (status >= 300 && status < 400)) {
                    return;
                }

                reads.push(
                    response.text().then(
                        (html) => {
                            const found = findPhpError(html);

                            if (found !== null) {
                                problems.push(`PHP error in ${response.url()}: ${found}`);
                            }
                        },
                        (error) => {
                            problems.push(`cannot read ${response.url()}: ${error.message}`);
                        },
                    ),
                );
            });

            await use(problems);
            await Promise.all(reads);
            expect(problems, 'PHP errors in documents and uncaught page errors').toEqual([]);
        },
        { auto: true },
    ],
});

/**
 * wp-login.phpへのPOSTでログインする(画面の操作を経ない)。クッキーはpageのコンテキストに入る。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [username]
 * @param {string} [password]
 */
export async function login(page, username = 'admin', password = 'password') {
    // wordpress_test_cookieを受け取る(無いとログインを拒まれる)
    await page.request.get('/wp-login.php');
    const response = await page.request.post('/wp-login.php', {
        form: { log: username, pwd: password, 'wp-submit': 'Log In', testcookie: '1', redirect_to: '/wp-admin/' },
    });

    expect(response.ok(), `log in as ${username}`).toBe(true);
    expect(new URL(response.url()).pathname, `log in as ${username}`).toMatch(/^\/wp-admin\//);
}

/**
 * ブロックエディタを開き、データの層が読める状態まで待ち、歓迎のガイドを閉じる。
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} url `/wp-admin/post-new.php?post_type=page`等
 */
export async function openBlockEditor(page, url) {
    await page.goto(url);
    await page.waitForFunction(() => window.wp?.data?.select('core/editor')?.getCurrentPostId() !== undefined);
    await page.evaluate(() => window.wp.data.dispatch('core/preferences').set('core/edit-post', 'welcomeGuide', false));
    await expect(page.getByRole('dialog', { name: /welcome/i })).toHaveCount(0);
}

/**
 * ブロックエディタで開いている投稿のID。
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<number>}
 */
export async function currentPostId(page) {
    return page.evaluate(() => window.wp.data.select('core/editor').getCurrentPostId());
}
