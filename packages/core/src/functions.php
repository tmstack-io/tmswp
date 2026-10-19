<?php

declare(strict_types=1);

use TMSWP\Core\Log\Log;
use TMSWP\Core\Log\LoggerRegistry;
use TMSWP\Core\View\View;

if (!function_exists('tms_var')) {
    function tms_var(string $key, mixed $default = null): mixed
    {
        return View::get($key, $default);
    }
}

if (!function_exists('tms_log')) {
    function tms_log(): LoggerRegistry
    {
        return Log::registry();
    }
}
