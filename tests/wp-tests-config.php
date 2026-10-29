<?php

declare(strict_types=1);

define('ABSPATH', '/var/www/html/');

define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'wordpress');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: 'password');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: 'mysql');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'TMSWP Test Suite');
define('WP_PHP_BINARY', 'php');
define('WP_DEBUG', true);
