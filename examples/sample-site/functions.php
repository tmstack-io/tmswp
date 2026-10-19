<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

TMSWP\Core\Log\Log::set(
    'default',
    new TMSWP\Core\Log\SimpleLogger(WP_CONTENT_DIR . '/logs'),
);

TMSWP\Core\Kernel\Kernel::boot(
    TMSWP\Core\Kernel\KernelConfig::theme(appDir: __DIR__ . '/app', appNamespace: 'App'),
);

TMSWP\Thumbnail\Thumbnail::register();
TMSWP\SettingsPage\SettingsPages::register();
TMSWP\AcfBridge\AcfBridge::boot();
TMSWP\JaToolkit\JaToolkit::enable([
    TMSWP\JaToolkit\Module\JapaneseSlug::class,
]);
