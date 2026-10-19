<?php

declare(strict_types=1);

/*
 * 統合テストの fixture を、WordPress の通常の読み込み経路で有効化する。
 *
 * tests/bootstrap-integration.php が WordPress の読み込み前に require する。ここではテーマとプラグインを
 * 「有効」にするだけで、Kernel::boot() は呼ばない。boot は WordPress 自身が読み込む fixture の
 * プラグイン本体ファイル(プラグインの読み込み時)とテーマの functions.php(after_setup_theme の前)が行う。
 * どちらも init より前であり、debug 時に遅延 boot を例外にする Kernel の正規の経路を通る。
 */

use TMSWP\Core\Kernel\Kernel;

const TMSWP_TESTS_FIXTURE_THEME = 'tmswp-fixture-theme';
const TMSWP_TESTS_FIXTURE_PLUGIN_FILE = __DIR__ . '/plugins/tmswp-fixture-plugin/tmswp-fixture-plugin.php';
const TMSWP_TESTS_FIXTURE_NAMESPACES = ['TmswpFixturePlugin', 'TmswpFixtureTheme'];

/*
 * fixture の Kernel が使う探索キャッシュの置き場。実行ごとに空にして、boot が毎回 app/ を走査する
 * (前回の実行のキャッシュに依存しない)状態から始める。
 */
define('TMSWP_TESTS_CACHE_DIR', sys_get_temp_dir() . '/tmswp-integration-cache');

foreach (glob(TMSWP_TESTS_CACHE_DIR . '/*') ?: [] as $staleCacheFile) {
    if (!unlink($staleCacheFile)) {
        throw new RuntimeException("cannot remove stale discovery cache: {$staleCacheFile}");
    }
}

// 無料版 ACF(.wp-env.json で導入)と fixture のプラグインを有効なプラグインとして WordPress に読み込ませる
tests_add_filter('pre_option_active_plugins', static fn(): array => [
    'advanced-custom-fields/acf.php',
    plugin_basename(TMSWP_TESTS_FIXTURE_PLUGIN_FILE),
]);

// fixture のテーマを有効なテーマにする。テーマの置き場は muplugins_loaded(テーマの読み込みより前)で登録する
tests_add_filter('muplugins_loaded', static function (): void {
    register_theme_directory(__DIR__ . '/themes');
});
tests_add_filter('pre_option_template', static fn(): string => TMSWP_TESTS_FIXTURE_THEME);
tests_add_filter('pre_option_stylesheet', static fn(): string => TMSWP_TESTS_FIXTURE_THEME);

/*
 * テストのサイトには ja の言語パックが無く、switch_to_locale('ja') は利用可能な言語にない ja を拒否する。
 * 利用可能な言語は WordPress の読み込み時(WP_Locale_Switcher の生成時)に決まるため、ここで ja を加える。
 * FW の訳は言語パックではなく、FW のローダーが packages/<pkg>/languages/ から読む。
 */
tests_add_filter('get_available_languages', static fn(array $languages): array => [...$languages, 'ja']);

// fixture のどちらかが読み込まれなかった場合は、個々のテストの失敗ではなく起動時のエラーとして止める
tests_add_filter('init', static function (): void {
    $missing = array_diff(TMSWP_TESTS_FIXTURE_NAMESPACES, array_keys(Kernel::all()));

    if ($missing !== []) {
        throw new RuntimeException('fixture kernels were not booted before init: ' . implode(', ', $missing));
    }
}, 0);

/*
 * WordPress の読み込みを終えた時点の登録済みメタキーを控える。WP のテストライブラリは各テストの
 * tear_down で登録済みメタキーをすべて解除するため、IntegrationTestCase が各テストの前にこれを戻す。
 */
tests_add_filter('wp_loaded', static function (): void {
    $GLOBALS['tmswp_tests_registered_meta_keys'] = $GLOBALS['wp_meta_keys'];
}, PHP_INT_MAX);
