<?php

declare(strict_types=1);

/*
 * スターターから作ったテーマとプラグインを、このプロセスの中だけ有効にする(StarterProjectsIntegrationTestが使う)。
 *
 * 環境変数TMSWP_STARTER_SITE(JSON。themeRoot:テーマの置き場の絶対パス、theme:テーマのディレクトリ名、
 * plugins:プラグインの本体ファイルの絶対パスの並び)を読み、WordPressの読み込みの前に$wp_filterにフィルタを置く。
 * 有効化はオプションの読み出しの上書きで、DBには書かない。WP-CLIの--require(wp-cli.php)と、PHPの組み込みサーバの
 * ルーター(router.php)が読み込み、追加のフィルタを置く関数を返す。ルーターはグローバルのスコープで読み込むため、
 * WordPressのグローバル変数と混ざらないよう、変数は関数の中に閉じる。
 */

return (static function (): Closure {
    $site = json_decode((string) getenv('TMSWP_STARTER_SITE'), true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($site) || !is_string($site['themeRoot'] ?? null) || !is_string($site['theme'] ?? null) || !is_array($site['plugins'] ?? null)) {
        throw new RuntimeException('TMSWP_STARTER_SITE must be a JSON object with themeRoot, theme and plugins');
    }

    $themeRoot = $site['themeRoot'];
    $theme = $site['theme'];
    $plugins = $site['plugins'];

    // WordPressのplugin.phpは、読み込みの前に置かれた$wp_filterの配列をWP_Hookに組み立てる(tests_add_filter()と同じ方法)
    $addFilter = static function (string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
        $GLOBALS['wp_filter'][$hook][$priority][] = ['function' => $callback, 'accepted_args' => $acceptedArgs];
    };

    $addFilter('pre_option_active_plugins', static fn(): array => array_map(plugin_basename(...), $plugins));
    $addFilter('muplugins_loaded', static function () use ($themeRoot): void {
        register_theme_directory($themeRoot);
    });
    $addFilter('pre_option_template', static fn(): string => $theme);
    $addFilter('pre_option_stylesheet', static fn(): string => $theme);
    // テーマの置き場は、サイトのオプションやtheme_rootsのサイトのtransient(開発用サイトの状態)によらず、ここで決める
    $addFilter('pre_option_template_root', static fn(): string => $themeRoot);
    $addFilter('pre_option_stylesheet_root', static fn(): string => $themeRoot);
    // テーマの一覧を作ると、このプロセスのテーマの置き場でtheme_rootsが保存される。ほかのプロセスが作り直すよう、終わりに消す
    $addFilter('shutdown', static function (): void {
        delete_site_transient('theme_roots');
    });

    return $addFilter;
})();
