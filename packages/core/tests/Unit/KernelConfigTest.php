<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class KernelConfigTest extends UnitTestCase
{
    public function testThemeConfigNormalizesInputs(): void
    {
        $appDir = $this->createTempDirectory('theme-app');

        $config = KernelConfig::theme($appDir, '\\Example\\Theme\\', true, false);

        self::assertSame(KernelContext::Theme, $config->context);
        self::assertSame(realpath($appDir), $config->appDir);
        self::assertSame('Example\\Theme', $config->appNamespace);
        self::assertNull($config->pluginFile);
        self::assertTrue($config->debug);
        self::assertFalse($config->actions);
    }

    public function testPluginConfigRequiresExistingPluginFile(): void
    {
        $appDir = $this->createTempDirectory('plugin-app');
        $pluginFile = $this->writeFile($this->createTempDirectory('plugin-root') . '/plugin.php', "<?php\n");

        $config = KernelConfig::plugin($pluginFile, $appDir, 'Example\\Plugin', false);

        self::assertSame(KernelContext::Plugin, $config->context);
        self::assertSame(realpath($pluginFile), $config->pluginFile);
        self::assertSame(realpath($appDir), $config->appDir);
        self::assertFalse($config->debug);
        self::assertFalse($config->actions);
    }

    public function testDiscoveryFileIsPlacedNextToAppDirectory(): void
    {
        $root = $this->createTempDirectory('discovery-file-root');
        self::assertTrue(mkdir($root . '/app'));

        $config = KernelConfig::theme($root . '/app/', 'Example\\Theme', false, false);

        self::assertSame(realpath($root) . '/tmswp-discovery.php', $config->discoveryFile());
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function wpDebugValues(): iterable
    {
        yield 'true' => [true, true];
        yield '真値の 1' => [1, true];
        yield '真値の文字列' => ['true', true];
        yield 'false' => [false, false];
        yield '偽値の 0' => [0, false];
    }

    /**
     * debug を省略すると、WP_DEBUG の真偽を既定値にする(true との厳密比較ではない)。
     */
    #[DataProvider('wpDebugValues')]
    #[RunInSeparateProcess]
    public function testDebugDefaultsToTruthinessOfWpDebug(mixed $wpDebug, bool $expected): void
    {
        \define('WP_DEBUG', $wpDebug);

        self::assertSame($expected, KernelConfig::theme($this->createTempDirectory('debug-default'), 'Example\\DebugDefault')->debug);
    }

    #[RunInSeparateProcess]
    public function testDebugDefaultsToFalseWithoutWpDebug(): void
    {
        self::assertFalse(\defined('WP_DEBUG'));
        self::assertFalse(KernelConfig::theme($this->createTempDirectory('debug-undefined'), 'Example\\DebugUndefined')->debug);
    }

    public function testItRejectsReservedNamespace(): void
    {
        $this->expectException(KernelException::class);

        KernelConfig::theme($this->createTempDirectory('reserved'), 'TMSWP\\App');
    }

    public function testItRejectsMissingAppDirectory(): void
    {
        $this->expectException(KernelException::class);

        KernelConfig::theme(sys_get_temp_dir() . '/tmswp-missing-app-dir', 'Example\\Missing');
    }

    private function writeFile(string $file, string $contents): string
    {
        $bytes = file_put_contents($file, $contents);

        if ($bytes === false) {
            self::fail("テストファイルを書き込めません: {$file}");
        }

        return $file;
    }
}
