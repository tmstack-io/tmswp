<?php

declare(strict_types=1);

/*
 * WP-CLIの--require。スターターから作ったテーマとプラグインを、このWP-CLIのプロセスの中だけ有効にする(site.php)。
 *
 * Composerのオートローダは、テーマとプラグインが自身のvendor/autoload.phpで読み込む(プラグインの読み込みの時点)。
 * WP-CLIのpharは--requireの後に自身の依存(古いpsr/containerを含む)のオートローダを先頭に登録するため、
 * ここでは読み込まない(tests/fixtures/_wp-cli.phpと同じ理由)。
 */

require __DIR__ . '/site.php';
