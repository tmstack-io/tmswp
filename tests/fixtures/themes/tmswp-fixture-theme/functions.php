<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Tests\Support\RecordingExtension;
use TMSWP\Core\Tests\Support\RecordingLogger;

// 実際のテーマと同じく、functions.php の直下(after_setup_theme より前)で boot する
(static function (): void {
    $loader = new ClassLoader();
    $loader->addPsr4('TmswpFixtureTheme\\', __DIR__ . '/app');
    $loader->register();

    // 拡張とログのチャネルは、プラグインの fixture と同じクラスを Kernel ごとに登録する(統合テストが記録を確かめる)
    Kernel::boot(KernelConfig::theme(
        __DIR__ . '/app',
        'TmswpFixtureTheme',
        extensions: [new RecordingExtension()],
        logChannels: static fn(): array => ['test' => new LogChannel(new RecordingLogger())],
    ));
})();
