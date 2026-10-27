<?php

declare(strict_types=1);

/*
 * fixture のテーマとプラグインの探索結果ファイルを、利用者と同じ `wp tmswp discover` で生成・照合するための
 * WP-CLI の --require ファイル(composer の discover:build / discover:check が使う)。
 *
 * wp-env の開発用サイトで、このプロセスの中だけ fixture のテーマとプラグインを有効にする(_activate.php)。
 * 有効化はオプションの読み出しの上書きで、これ自体は DB に書かない。Kernel::boot() は WordPress が fixture を
 * 読み込むときに fixture 自身が行い、fixture のコンポーネントは利用者と同じく init 等で登録される。そのため
 * ロール(add_role・add_cap)等の登録は開発用サイトの wp_ のテーブルに反映されうる。統合テストのテーブル
 * (wptests_)には触れない。
 */

(require __DIR__ . '/_activate.php')(WP_CLI::add_wp_hook(...));

/*
 * 有効なテーマの置き場を、オプションの読み出しの上書きでfixtureのテーマの置き場(_activate.phpが登録する
 * ディレクトリ)に固定する。WordPressは有効なテーマの置き場をtemplate_root・stylesheet_rootのオプションで探し、
 * 無ければsite transientのtheme_rootsで探す。theme_rootsは、このファイルを読まないWP-CLIの実行や
 * 開発用サイトへの通常のリクエストで、fixtureのテーマの置き場を含まない形で作られうる。それを読むとfixtureの
 * テーマが見つからず、テーマのKernelが起動しない。
 */
$themeRoot = static fn(): string => __DIR__ . '/themes';
WP_CLI::add_wp_hook('pre_option_template_root', $themeRoot);
WP_CLI::add_wp_hook('pre_option_stylesheet_root', $themeRoot);

/*
 * Composer のオートローダは、実際のテーマ・プラグインと同じくプラグインを読み込む時点で登録する。
 * WP-CLI の phar は --require の後に自身の依存(古い psr/container 1.0 を含む)のオートローダを先頭に登録するため、
 * ここで登録すると psr/container が phar 側から読まれ、Kernel::boot() がその非互換を検出して KernelException で
 * 止まる(packages/core/README.md の利用条件)。
 */
WP_CLI::add_wp_hook('muplugins_loaded', static function (): void {
    require dirname(__DIR__, 2) . '/vendor/autoload.php';
}, 0);
