<?php

declare(strict_types=1);

/*
 * PHPの組み込みサーバ(php -S)のルーター。スターターから作ったテーマとプラグインを有効にして(site.php)、
 * すべてのリクエストをWordPressのフロント(-tで指定したWordPressのルートのindex.php)で処理する。
 *
 * 起動したKernelの名前空間と、コア以外の登録済みのブロックの名前を、応答のヘッダで返す。
 */

use TMSWP\Core\Kernel\Kernel;

$addFilter = require __DIR__ . '/site.php';

// サイトのURL(WP_HOME)と組み込みサーバのホストが違うため、正規のURLへのリダイレクトをしない
$addFilter('redirect_canonical', static fn(): bool => false);
// WP-Cronを、このリクエストから起動しない
$addFilter('pre_get_ready_cron_jobs', static fn(): array => []);
$addFilter('send_headers', static function (): void {
    $blocks = array_filter(
        array_keys(WP_Block_Type_Registry::get_instance()->get_all_registered()),
        static fn(string $name): bool => !str_starts_with($name, 'core/'),
    );

    header('X-Tmswp-Kernels: ' . implode(',', array_keys(Kernel::all())));
    header('X-Tmswp-Blocks: ' . implode(',', $blocks));
});

require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
