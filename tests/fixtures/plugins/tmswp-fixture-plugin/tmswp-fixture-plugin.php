<?php

/**
 * Plugin Name: TMSWP Fixture Plugin
 * Description: TMSWP の統合テスト用 fixture プラグイン。tests/fixtures/_boot.php が有効化する。
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;

// 実際のプラグインと同じく、本体ファイルの直下(plugins_loaded より前)で boot する
(static function (): void {
    $loader = new ClassLoader();
    $loader->addPsr4('TmswpFixturePlugin\\', __DIR__ . '/app');
    $loader->register();

    Kernel::boot(KernelConfig::plugin(__FILE__, __DIR__ . '/app', 'TmswpFixturePlugin', TMSWP_TESTS_CACHE_DIR));
})();
