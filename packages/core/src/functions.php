<?php

declare(strict_types=1);

use TMSWP\Core\Log\Log;
use TMSWP\Core\Log\LoggerRegistry;
use TMSWP\Core\View\View;

/**
 * View 変数バッグの値を取得する。View::get() への委譲。
 *
 * テンプレートでは @var 注釈で型を明示する:
 *   $form = tms_var('form');           // actions が return ['form' => $form] で用意した値
 *   $related = tms_var('related', []); // 省略可能な変数は既定値を明示する(未定義の警告を出さない)
 *
 * @param string $key actions ファイルが返した配列のキー
 * @param mixed $default キーが無いときに返す値
 *
 * @return mixed キーの値。キーが無ければ $default
 */
function tms_var(string $key, mixed $default = null): mixed
{
    return View::get($key, $default);
}

/**
 * 呼び出したファイルの Kernel のログのレジストリを取得する。Log::registry() への委譲。
 *
 *   tms_log()->info('imported', ['count' => $n]);
 *   tms_log()->scope('mail')->error('send failed', ['exception' => $e]);
 *   tms_log()->channel('audit')->info('user updated');
 *
 *
 * @throws TMSWP\Core\Exception\KernelException 呼び出したファイルがどの Kernel のルートにも含まれない場合
 *
 * @return LoggerRegistry 呼び出したファイルを含むルートディレクトリを持つ Kernel のレジストリ(PSR-3 のロガー)
 */
function tms_log(): LoggerRegistry
{
    return Log::registry();
}
