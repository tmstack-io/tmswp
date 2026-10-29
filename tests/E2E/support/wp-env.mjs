// sample-siteのwp-env(examples/sample-site/.wp-env.json)のcliコンテナを呼ぶ補助。
// ルートのnode_modulesの@wordpress/envをパスで呼ぶ(npxやコマンド名でwp-envを解決しない。AGENTS.md)。
// sample-siteのcomposerのスクリプトと同じく、sample-siteのディレクトリをcwdにして呼ぶ。

import { execFileSync } from 'node:child_process';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '../../..');
const WP_ENV = path.join(ROOT, 'node_modules/@wordpress/env/bin/wp-env');
const SAMPLE_SITE = path.join(ROOT, 'examples/sample-site');

/**
 * cliコンテナでコマンドを実行し、標準出力(前後の空白を除く)を返す。失敗(終了コードが0以外)は例外になる。
 *
 * 引数はwp-envの引数の解析(yargs)を通るため、空の文字列や`-`で始まる値は、引数で渡さずにphp()のparamsで渡す。
 *
 * @param {...string} args `wp`から始まるコマンドと引数
 * @returns {string}
 */
export function cli(...args) {
    return execFileSync(process.execPath, [WP_ENV, 'run', 'cli', ...args], {
        cwd: SAMPLE_SITE,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
}

/**
 * WordPressを読み込んだ状態でPHPの式を評価し、その値をJSONで受け取る。値はJSONに直せるものに限る。
 *
 * @param {string} expression PHPの式(文ではない)
 * @param {Record<string, unknown>} [params] 式から`$params['名前']`で読む値。base64のJSONで渡す(引用符や空の値を壊さない)
 * @returns {unknown}
 */
export function php(expression, params = {}) {
    const encoded = Buffer.from(JSON.stringify(params)).toString('base64');
    const code = `$params = json_decode(base64_decode('${encoded}'), true, 512, JSON_THROW_ON_ERROR); echo json_encode(${expression}, JSON_THROW_ON_ERROR);`;

    return JSON.parse(cli('wp', 'eval', code));
}

/**
 * オプションの値。無ければnull。
 *
 * @param {string} name
 * @returns {unknown}
 */
export function getOption(name) {
    return php("get_option($params['name'], null)", { name });
}

/**
 * オプションを書く。nullなら消す(getOption()で読んだ値を戻すときに使う)。
 *
 * @param {string} name
 * @param {unknown} value
 */
export function restoreOption(name, value) {
    php("$params['value'] === null ? delete_option($params['name']) : update_option($params['name'], $params['value'])", { name, value });
}

/**
 * 投稿のメタの値(単一)。無ければ空の文字列。
 *
 * @param {number} postId
 * @param {string} key
 * @returns {unknown}
 */
export function getPostMeta(postId, key) {
    return php("get_post_meta($params['id'], $params['key'], true)", { id: postId, key });
}

/**
 * 投稿のメタを書く(getPostMeta()で読んだ値を戻すときに使う)。
 *
 * @param {number} postId
 * @param {string} key
 * @param {unknown} value
 */
export function restorePostMeta(postId, key, value) {
    php("update_post_meta($params['id'], $params['key'], $params['value'])", { id: postId, key, value });
}

/**
 * dev/seed.phpが作った製品(公開済みで、IDのいちばん小さいもの)のID。
 *
 * @returns {number}
 */
export function seededProductId() {
    const id = php("(int) (get_posts(['post_type' => 'product', 'post_status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC', 'numberposts' => 1, 'fields' => 'ids'])[0] ?? 0)");

    if (typeof id !== 'number' || id <= 0) {
        throw new Error('no published product; run `composer wp:setup` in examples/sample-site');
    }

    return id;
}

/**
 * 記録したメール(dev/mu-plugins/sample-site-mail-capture.phpが`sample_site_captured_mails`に残す。直近の10通)。
 *
 * @returns {Array<{to: string, subject: string, message: string, headers: unknown, captured_at: string}>}
 */
export function capturedMails() {
    const mails = getOption('sample_site_captured_mails');

    return Array.isArray(mails) ? mails : [];
}
