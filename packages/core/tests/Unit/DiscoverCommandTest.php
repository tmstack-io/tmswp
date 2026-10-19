<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use TMSWP\Core\Kernel\DiscoverCommand;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\Tests\Support\WpdbTestDouble;

/**
 * `wp tmswp discover` の生成と照合。WP_CLI の代役クラスをテスト用の一時ファイルに書いて読み込むため、
 * 他のテストに WP_CLI クラスを残さないよう別プロセスで動かす。代役は呼び出しを
 * $GLOBALS['tmswp_test_wp_cli'] に記録し、WP_CLI::error() は終了の代わりに例外を投げる。
 */
final class DiscoverCommandTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = WpdbTestDouble::create();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        $GLOBALS['tmswp_test_wp_cli'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['wp_version'], $GLOBALS['wp_actions'], $GLOBALS['tmswp_test_wp_cli']);
        Kernel::reset();
        parent::tearDown();
    }

    #[RunInSeparateProcess]
    public function testItGeneratesAndChecksDiscoveryFilesOfAllKernels(): void
    {
        $this->loadWpCliDouble();
        $alpha = $this->bootKernel('Alpha');
        $beta = $this->bootKernel('Beta');
        $command = new DiscoverCommand();

        // ファイルが無い状態の照合は、両方の Kernel を列挙して失敗する
        $error = $this->runFailing($command, ['check' => true]);
        self::assertStringContainsString("{$alpha->appNamespace}: {$alpha->discoveryFile()} is missing", $error);
        self::assertStringContainsString("{$beta->appNamespace}: {$beta->discoveryFile()} is missing", $error);

        $command([], []);
        self::assertFileExists($alpha->discoveryFile());
        self::assertFileExists($beta->discoveryFile());
        self::assertSame('success', $this->lastCall()[0]);

        $command([], ['check' => true]);
        self::assertSame(['success', 'discovery files are up to date for 2 kernel(s)'], $this->lastCall());

        // 探索対象だけを変えてファイルを更新しないと、変えた Kernel だけが古いと報告される
        $this->writeHookClass($alpha, 'AddedHook', 'tmswp_added_hook');
        $error = $this->runFailing($command, ['check' => true]);
        self::assertStringContainsString("{$alpha->appNamespace}: {$alpha->discoveryFile()} is out of date", $error);
        self::assertStringNotContainsString($beta->appNamespace, $error);

        $command([], []);
        $command([], ['check' => true]);
        self::assertSame(['success', 'discovery files are up to date for 2 kernel(s)'], $this->lastCall());
    }

    #[RunInSeparateProcess]
    public function testItFailsWithoutBootedKernels(): void
    {
        $this->loadWpCliDouble();

        self::assertStringContainsString('no TMSWP kernel is booted', $this->runFailing(new DiscoverCommand(), ['check' => true]));
    }

    #[RunInSeparateProcess]
    public function testItRejectsUnknownArguments(): void
    {
        $this->loadWpCliDouble();

        self::assertSame('Usage: wp tmswp discover [--check]', $this->runFailing(new DiscoverCommand(), ['force' => true]));
    }

    #[RunInSeparateProcess]
    public function testItReportsScanFailureWithoutWritingFiles(): void
    {
        $this->loadWpCliDouble();
        $alpha = $this->bootKernel('Alpha');
        $beta = $this->bootKernel('Beta');
        $this->writeFile($beta->appDir . '/Broken.php', "<?php\n\nnamespace {$beta->appNamespace};\n\nfinal class Other\n{\n}\n");

        $error = $this->runFailing(new DiscoverCommand(), []);

        self::assertStringContainsString("is expected to define class {$beta->appNamespace}\\Broken", $error);
        self::assertFileDoesNotExist($alpha->discoveryFile());
        self::assertFileDoesNotExist($beta->discoveryFile());
    }

    private function loadWpCliDouble(): void
    {
        $file = $this->createTempDirectory('wp-cli-double') . '/WP_CLI.php';
        $this->writeFile($file, <<<'PHP'
<?php

declare(strict_types=1);

final class WP_CLI
{
    public static function line(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['line', $message];
    }

    public static function success(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['success', $message];
    }

    public static function error(string $message): never
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['error', $message];

        throw new \RuntimeException($message);
    }
}
PHP);

        require $file;
    }

    private function bootKernel(string $suffix): KernelConfig
    {
        $root = $this->createTempDirectory('discover-command-' . strtolower($suffix));
        self::assertTrue(mkdir($root . '/app'));
        $namespace = 'Example\\Tests\\DiscoverCommand' . $suffix . uniqid();
        $config = KernelConfig::theme($root . '/app', $namespace, true, false);
        $this->writeHookClass($config, 'ProbeHook', 'tmswp_probe_hook_' . strtolower($suffix));

        spl_autoload_register(static function (string $class) use ($namespace, $root): void {
            $prefix = $namespace . '\\';

            if (str_starts_with($class, $prefix) && is_file($file = $root . '/app/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php')) {
                require $file;
            }
        });

        Kernel::boot($config);

        return $config;
    }

    private function writeHookClass(KernelConfig $config, string $class, string $hook): void
    {
        $this->writeFile($config->appDir . "/Hooks/{$class}.php", <<<PHP
<?php

declare(strict_types=1);

namespace {$config->appNamespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class {$class} extends Hook
{
    #[Action('{$hook}')]
    public function handle(): void
    {
    }
}
PHP);
    }

    /** @param array<string, mixed> $assocArgs */
    private function runFailing(DiscoverCommand $command, array $assocArgs): string
    {
        try {
            $command([], $assocArgs);
        } catch (RuntimeException $exception) {
            self::assertSame(['error', $exception->getMessage()], $this->lastCall());

            return $exception->getMessage();
        }

        self::fail('WP_CLI::error() was not called');
    }

    /** @return array{string, string} */
    private function lastCall(): array
    {
        $calls = $GLOBALS['tmswp_test_wp_cli'];
        self::assertIsArray($calls);
        $last = end($calls);
        self::assertIsArray($last);
        self::assertCount(2, $last);
        [$type, $message] = $last;
        self::assertIsString($type);
        self::assertIsString($message);

        return [$type, $message];
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("ファイルを書き込めません: {$path}");
        }
    }
}
