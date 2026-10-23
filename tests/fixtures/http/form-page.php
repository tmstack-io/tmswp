<?php

declare(strict_types=1);

/*
 * フォームのあるページ。FormIntegrationTest が wp-env の開発用サイトへ HTTP で取得し、レスポンスのヘッダを確かめる
 * (PHP の CLI はヘッダを送らないため、PHPUnit の中ではヘッダを確かめられない)。
 * 開発用サイトの WordPress を読み込み、handle() してからフォームを描画する。DONOTCACHEPAGE の値はヘッダで返す。
 */

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;

require dirname(__DIR__, 6) . '/wp-load.php';
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$form = new Form(FormDefinition::create('nocache_probe')->text('name', 'Name'));
$form->handle();

header('X-Tmswp-Donotcachepage: ' . (defined('DONOTCACHEPAGE') ? var_export(constant('DONOTCACHEPAGE'), true) : 'undefined'));

echo $form->open();
echo $form->field('name')->input();
echo $form->close();
