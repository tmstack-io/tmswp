<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// WordPressが起動時に定義するWP_CONTENT_DIRを、ユニットテストではシステムの一時ディレクトリの下の、プロジェクトごとの
// ディレクトリにする。tmswp-kernel.phpのSimpleLoggerは、出力先を省くとWP_CONTENT_DIRの下(tmswp-logs/)に書くため、
// この定数が無いと、ユニットテストでlogChannelsのクロージャを呼べない。app/のコードからこの定数を直接読まない
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/tmswp-unit-tests/' . md5(dirname(__DIR__)) . '/wp-content');
