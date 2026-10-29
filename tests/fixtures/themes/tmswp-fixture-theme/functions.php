<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;

// 実際のテーマと同じく、functions.php の直下(after_setup_theme より前)で boot する
(static function (): void {
    $loader = new ClassLoader();
    $loader->addPsr4('TmswpFixtureTheme\\', __DIR__ . '/app');
    $loader->register();

    Kernel::boot(KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
})();
