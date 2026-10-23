<?php

declare(strict_types=1);

use TMSWP\Core\Tests\Support\IntegrationEnvironmentGuard;

$rootLoader = require dirname(__DIR__) . '/vendor/autoload.php';
$wpTestsLoader = require dirname(__DIR__) . '/vendor-bin/wp-tests/vendor/autoload.php';

$rootLoader->unregister();
$wpTestsLoader->unregister();
$rootLoader->register(false);
$wpTestsLoader->register(true);

/*
 * WordPress とテストライブラリを読み込む前に、テストのテーブルが tests/wp-tests-config.php の wptests_ になる
 * 状態かを検査し、そうでなければ止める(IntegrationEnvironmentGuard)。テストライブラリは、wp-env が core と
 * 同じタグの wordpress-develop から取得して WP_TESTS_DIR に置くものを使う。WP_TESTS_DIR の直下に wp-env が
 * 置く wp-tests-config.php は開発用サイトと同じ接頭辞 wp_ のため、設定ファイルは必ず自前のものを指定する。
 */
$wpTestsDir = IntegrationEnvironmentGuard::assertSafe(
    getenv('WP_TESTS_DIR'),
    get_defined_constants(true)['user'] ?? [],
    $GLOBALS,
);

define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');
define(
    'WP_TESTS_PHPUNIT_POLYFILLS_PATH',
    dirname(__DIR__) . '/vendor-bin/wp-tests/vendor/yoast/phpunit-polyfills',
);

require $wpTestsDir . '/includes/functions.php';

// fixture のテーマ・プラグインと無料版 ACF を有効化する(boot は WordPress が fixture を読み込むときに行われる)
require __DIR__ . '/fixtures/_boot.php';

/*
 * 非推奨は失敗として扱う(テスト中は phpunit.integration.xml.dist の convertDeprecationsToExceptions)。
 * fixture の boot と init での登録は WordPress の読み込み中、つまり PHPUnit のエラーハンドラーが働く前に
 * 動くため、読み込みの間も非推奨を例外にする。
 */
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): never {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}, E_DEPRECATED | E_USER_DEPRECATED);

require $wpTestsDir . '/includes/bootstrap.php';

restore_error_handler();
