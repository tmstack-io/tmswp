<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Tests\Support\RecordingExtension;
use TMSWP\Core\Tests\Support\RecordingLogger;

// プラグインのKernelの構成。本体ファイルのブートと、WordPressを起動しないtms-acfが読む(D-48)。
// 本体ファイルのパスはこのファイルの位置から決めるため、どちらの経路でも同じ値になる。
// 拡張とログのチャネルは、テーマのfixtureと同じクラスをKernelごとに登録する(統合テストが記録を確かめる)
return KernelConfig::plugin(
    __DIR__ . '/tmswp-fixture-plugin.php',
    __DIR__ . '/app',
    'TmswpFixturePlugin',
    extensions: [new RecordingExtension(), new AcfBridge()],
    logChannels: static fn(): array => ['test' => new LogChannel(new RecordingLogger())],
);
