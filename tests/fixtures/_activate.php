<?php

declare(strict_types=1);

/*
 * fixture のテーマとプラグインを、WordPress の読み込み前に「有効」にするフィルタを登録する関数を返す。
 * 統合テストの bootstrap(_boot.php)と WP-CLI(_wp-cli.php)が共有する。登録関数には tests_add_filter() か
 * WP_CLI::add_wp_hook() を渡す。フィルタはオプションの読み出しを上書きするだけで、有効化のために DB には
 * 書かない(fixture が起動した後のロール等の登録が DB に反映されるかどうかは、読み込む側のサイトで決まる)。
 */

return static function (callable $addFilter): void {
    $theme = 'tmswp-fixture-theme';
    $pluginFile = __DIR__ . '/plugins/tmswp-fixture-plugin/tmswp-fixture-plugin.php';

    // 無料版 ACF(.wp-env.json で導入)と fixture のプラグインを有効なプラグインとして WordPress に読み込ませる
    $addFilter('pre_option_active_plugins', static fn(): array => [
        'advanced-custom-fields/acf.php',
        plugin_basename($pluginFile),
    ]);

    // fixture のテーマを有効なテーマにする。テーマの置き場は muplugins_loaded(テーマの読み込みより前)で登録する
    $addFilter('muplugins_loaded', static function (): void {
        register_theme_directory(__DIR__ . '/themes');
    });
    $addFilter('pre_option_template', static fn(): string => $theme);
    $addFilter('pre_option_stylesheet', static fn(): string => $theme);
};
