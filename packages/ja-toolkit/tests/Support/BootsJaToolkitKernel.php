<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Tests\Support;

use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\JaToolkit\JaToolkit;

/**
 * ユニットテストで、一時ディレクトリのアプリ(空のapp/)にJaToolkitを登録したKernel(テーマ形態、debug)をbootする。
 *
 * 使うテストはUnitTestCaseを継承し、setUpで`$GLOBALS['wp_version']`(7.0以上)と`$GLOBALS['wp_actions']`を置き、
 * tearDownでKernel::reset()を呼ぶ。
 */
trait BootsJaToolkitKernel
{
    private function bootJaToolkitKernel(string $suffix, JaToolkit $jaToolkit): Kernel
    {
        $root = $this->createTempDirectory('ja-toolkit-' . strtolower($suffix));
        self::assertTrue(mkdir($root . '/app'));

        return Kernel::boot(KernelConfig::theme($root . '/app', 'Example\Tests\JaToolkit' . $suffix . uniqid(), true, [$jaToolkit]));
    }
}
