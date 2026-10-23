<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

TMSWP\Core\Kernel\Kernel::boot(
    TMSWP\Core\Kernel\KernelConfig::theme(
        appDir: __DIR__ . '/app',
        appNamespace: 'App',
        extensions: [new TMSWP\AcfBridge\AcfBridge()],
        logChannels: static fn(): array => [
            'default' => new TMSWP\Core\Log\LogChannel(new TMSWP\Core\Log\SimpleLogger(WP_CONTENT_DIR . '/logs')),
        ],
    ),
);

TMSWP\Thumbnail\Thumbnail::register();
TMSWP\SettingsPage\SettingsPages::register();
TMSWP\JaToolkit\JaToolkit::enable([
    TMSWP\JaToolkit\Module\JapaneseSlug::class,
]);
