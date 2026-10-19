<?php

declare(strict_types=1);

if (!class_exists('WP_Query', false)) {
    final class WP_Query
    {
        public bool $is404 = false;

        public function set_404(): void
        {
            $this->is404 = true;
        }
    }
}

if (!function_exists('wp_trigger_error')) {
    function wp_trigger_error(string $functionName, string $message, int $errorLevel = E_USER_NOTICE): void
    {
        $records = $GLOBALS['tmswp_wp_trigger_errors'] ?? [];

        if (!is_array($records)) {
            $records = [];
        }

        $records[] = [$functionName, $message, $errorLevel];
        $GLOBALS['tmswp_wp_trigger_errors'] = $records;
    }
}
