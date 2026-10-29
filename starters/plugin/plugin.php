<?php

/**
 * Plugin Name: {{project_name}}
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Text Domain: {{project_slug}}
 * Domain Path: /languages
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

TMSWP\Core\Kernel\Kernel::boot(TMSWP\Core\Kernel\KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
