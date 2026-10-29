<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

TMSWP\Core\Kernel\Kernel::boot(TMSWP\Core\Kernel\KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
