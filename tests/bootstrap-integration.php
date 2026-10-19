<?php

declare(strict_types=1);

$rootLoader = require dirname(__DIR__) . '/vendor/autoload.php';
$wpTestsLoader = require dirname(__DIR__) . '/vendor-bin/wp-tests/vendor/autoload.php';

$rootLoader->unregister();
$wpTestsLoader->unregister();
$rootLoader->register(false);
$wpTestsLoader->register(true);

$wpPhpunitDir = dirname(__DIR__) . '/vendor-bin/wp-tests/vendor/wp-phpunit/wp-phpunit';

putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php');
define(
    'WP_TESTS_PHPUNIT_POLYFILLS_PATH',
    dirname(__DIR__) . '/vendor-bin/wp-tests/vendor/yoast/phpunit-polyfills',
);

require $wpPhpunitDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    $bootFiles = glob(dirname(__DIR__) . '/packages/*/tests/Integration/_boot.php');

    foreach ($bootFiles === false ? [] : $bootFiles as $bootFile) {
        require $bootFile;
    }
});

require $wpPhpunitDir . '/includes/bootstrap.php';
