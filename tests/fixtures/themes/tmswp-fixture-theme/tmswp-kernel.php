<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Tests\Support\RecordingExtension;
use TMSWP\Core\Tests\Support\RecordingLogger;

// テーマのKernelの構成。functions.phpのブートと、WordPressを起動しないtms-acfが読む(D-48)。
// 拡張とログのチャネルは、プラグインのfixtureと同じクラスをKernelごとに登録する(統合テストが記録を確かめる)
return KernelConfig::theme(
    __DIR__ . '/app',
    'TmswpFixtureTheme',
    extensions: [new RecordingExtension(), new AcfBridge()],
    logChannels: static fn(): array => ['test' => new LogChannel(new RecordingLogger())],
);
