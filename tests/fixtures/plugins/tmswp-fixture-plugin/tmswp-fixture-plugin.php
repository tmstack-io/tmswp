<?php

/**
 * Plugin Name: TMSWP Fixture Plugin
 * Description: TMSWP の統合テスト用 fixture プラグイン。tests/fixtures/_boot.php が有効化する。
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Tests\Support\RecordingExtension;
use TMSWP\Core\Tests\Support\RecordingLogger;
use TMSWP\JaToolkit\JaToolkit;

// 実際のプラグインと同じく、本体ファイルの直下(plugins_loaded より前)で boot する
(static function (): void {
    $loader = new ClassLoader();
    $loader->addPsr4('TmswpFixturePlugin\\', __DIR__ . '/app');
    $loader->register();

    // 拡張とログのチャネルは、テーマの fixture と同じクラスを Kernel ごとに登録する(統合テストが記録を確かめる)
    Kernel::boot(KernelConfig::plugin(
        __FILE__,
        __DIR__ . '/app',
        'TmswpFixturePlugin',
        extensions: [new RecordingExtension(), new AcfBridge()],
        logChannels: static fn(): array => ['test' => new LogChannel(new RecordingLogger())],
    ));

    // ja-toolkit の翻訳ローダー(init の priority 1)を、利用側と同じ enable() の経路で登録する。
    // 統合テストはモジュールの挙動を使わないため、モジュールは列挙しない
    JaToolkit::enable([]);
})();
