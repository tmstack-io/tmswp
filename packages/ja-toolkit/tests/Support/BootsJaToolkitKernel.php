<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit\Tests\Support;

use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\JaToolkit\JaToolkit;

/**
 * ユニットテストで、一時ディレクトリのアプリ(空のapp/)にJaToolkitを登録したKernel(テーマ形態)をbootする。
 *
 * 使うテストはUnitTestCaseを継承し、setUpで`$GLOBALS['wp_version']`(7.0以上)と`$GLOBALS['wp_actions']`を置き、
 * tearDownでKernel::reset()を呼ぶ。
 */
trait BootsJaToolkitKernel
{
    /**
     * @param JaToolkit|null $jaToolkit nullなら、JaToolkitを登録しない(extensionsが空の)Kernel
     * @param bool $debug falseなら本番のboot(探索結果ファイルを先に作る)
     */
    private function bootJaToolkitKernel(string $suffix, ?JaToolkit $jaToolkit, bool $debug = true): Kernel
    {
        $root = $this->createTempDirectory('ja-toolkit-' . strtolower($suffix));
        self::assertTrue(mkdir($root . '/app'));
        $config = KernelConfig::theme($root . '/app', 'Example\Tests\JaToolkit' . $suffix . uniqid(), $debug, $jaToolkit instanceof JaToolkit ? [$jaToolkit] : []);

        if (!$debug) {
            (new DiscoveryFile($config))->write((new Discovery($config, new DiscoveryFile($config)))->scan());
        }

        return Kernel::boot($config);
    }
}
