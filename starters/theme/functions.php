<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

TMSWP\Core\Log\Log::set(
    'default',
    new TMSWP\Core\Log\SimpleLogger(),
);

TMSWP\Core\Kernel\Kernel::boot(
    TMSWP\Core\Kernel\KernelConfig::theme(appDir: __DIR__ . '/app', appNamespace: 'App'),
);
