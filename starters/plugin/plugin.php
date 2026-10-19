<?php

/**
 * Plugin Name: {{project_name}}
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Text Domain: {{project_slug}}
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

TMSWP\Core\Log\Log::set(
    'default',
    new TMSWP\Core\Log\SimpleLogger(WP_CONTENT_DIR . '/logs'),
);

TMSWP\Core\Kernel\Kernel::boot(
    TMSWP\Core\Kernel\KernelConfig::plugin(file: __FILE__, appDir: __DIR__ . '/app', appNamespace: 'PluginApp'),
);
