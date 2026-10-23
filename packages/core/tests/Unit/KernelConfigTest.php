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

        $config = KernelConfig::theme($appDir, '\\Example\\Theme\\', true);

        self::assertSame(KernelContext::Theme, $config->context);
        self::assertSame(realpath($appDir), $config->appDir);
        self::assertSame('Example\\Theme', $config->appNamespace);
        self::assertNull($config->pluginFile);
        self::assertTrue($config->debug);
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
    }

    public function testDiscoveryFileIsPlacedNextToAppDirectory(): void
    {
        $root = $this->createTempDirectory('discovery-file-root');
        self::assertTrue(mkdir($root . '/app'));

        $config = KernelConfig::theme($root . '/app/', 'Example\\Theme', false);

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
        $this->expectExceptionMessage('app namespace is reserved by TMSWP core: TMSWP\\App');

        KernelConfig::theme($this->createTempDirectory('reserved'), 'TMSWP\\App');
    }

    public function testItRejectsMissingAppDirectory(): void
    {
        $appDir = sys_get_temp_dir() . '/tmswp-missing-app-dir';

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage("app directory not found: {$appDir}");

        KernelConfig::theme($appDir, 'Example\\Missing');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidNamespaces(): iterable
    {
        yield '空' => ['', 'app namespace must not be empty'];
        yield '区切り文字だけ' => ['\\', 'app namespace must not be empty'];
        yield '数字で始まる' => ['1App', 'invalid app namespace: 1App'];
        yield '識別子に使えない文字' => ['Example\\My-App', 'invalid app namespace: Example\\My-App'];
        yield '空の区切り' => ['Example\\\\App', 'invalid app namespace: Example\\\\App'];
    }

    #[DataProvider('invalidNamespaces')]
    public function testThemeRejectsInvalidNamespace(string $namespace, string $message): void
    {
        $this->expectException(KernelException::class);
        $this->expectExceptionMessage($message);

        KernelConfig::theme($this->createTempDirectory('invalid-namespace'), $namespace);
    }

    #[DataProvider('invalidNamespaces')]
    public function testPluginRejectsInvalidNamespace(string $namespace, string $message): void
    {
        $pluginFile = $this->writeFile($this->createTempDirectory('invalid-namespace-plugin') . '/plugin.php', "<?php\n");

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage($message);

        KernelConfig::plugin($pluginFile, $this->createTempDirectory('invalid-namespace-plugin-app'), $namespace);
    }

    /** @return iterable<string, array{string}> */
    public static function missingPluginFiles(): iterable
    {
        yield '存在しないファイル' => ['/missing.php'];
        yield 'ディレクトリ' => [''];
    }

    #[DataProvider('missingPluginFiles')]
    public function testPluginRejectsMissingPluginFile(string $suffix): void
    {
        $file = $this->createTempDirectory('missing-plugin-file') . $suffix;

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage("plugin file not found: {$file}");

        KernelConfig::plugin($file, $this->createTempDirectory('missing-plugin-file-app'), 'Example\\MissingPluginFile');
    }

    public function testPluginRejectsMissingAppDirectory(): void
    {
        $pluginFile = $this->writeFile($this->createTempDirectory('missing-plugin-app-root') . '/plugin.php', "<?php\n");
        $appDir = \dirname($pluginFile) . '/app';

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage("app directory not found: {$appDir}");

        KernelConfig::plugin($pluginFile, $appDir, 'Example\\MissingPluginApp');
    }

    public function testPluginRejectsReservedNamespace(): void
    {
        $pluginFile = $this->writeFile($this->createTempDirectory('reserved-plugin') . '/plugin.php', "<?php\n");

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage('app namespace is reserved by TMSWP core: TMSWP');

        KernelConfig::plugin($pluginFile, $this->createTempDirectory('reserved-plugin-app'), '\\TMSWP\\');
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
