<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Closure;
use Mockery;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\Tests\Support\WpdbTestDouble;
use TMSWP\Core\View\ActionRouter;

final class KernelTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = WpdbTestDouble::create();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Kernel::reset();
        parent::tearDown();
    }

    public function testThemeBootRegistersLifecycleHooksAndActionRouter(): void
    {
        $appDir = $this->createTempDirectory('kernel-app');
        $namespace = 'Example\\Tests\\KernelApp' . uniqid();

        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 99, 0);
        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 1, 0);
        Actions\expectAdded('after_switch_theme')->once()->with(Mockery::type(Closure::class), 10, 0);
        Actions\expectAdded('switch_theme')->once()->with(Mockery::type(Closure::class), 10, 0);
        Actions\expectAdded('tmswp/activated')->once()->with(Mockery::type(Closure::class), 10, 1);
        Actions\expectAdded('admin_init')->once()->with(Mockery::type(Closure::class), 5, 0);
        Filters\expectAdded('template_include')->once()->with(
            Mockery::on(static fn(mixed $callback): bool => self::isActionRouterCallback($callback)),
            ActionRouter::PRIORITY,
            1,
        );

        $kernel = Kernel::boot(KernelConfig::theme($appDir, $namespace, true, true));

        self::assertSame($kernel, Kernel::get($namespace));
        self::assertSame([], $kernel->components());
    }

    public function testThemeBootCanDisableActionRouter(): void
    {
        $appDir = $this->createTempDirectory('kernel-no-actions-app');
        $namespace = 'Example\\Tests\\KernelNoActionsApp' . uniqid();

        Filters\expectAdded('template_include')->never();
        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 1, 0);

        Kernel::boot(KernelConfig::theme($appDir, $namespace, true, false));
    }

    public function testTextdomainLoaderLoadsCoreMoFileForDeterminedLocale(): void
    {
        $appDir = $this->createTempDirectory('kernel-textdomain-app');
        $namespace = 'Example\\Tests\\KernelTextdomainApp' . uniqid();
        $loader = null;

        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 1, 0)
            ->whenHappen(static function (Closure $callback) use (&$loader): void {
                $loader = $callback;
            });

        Kernel::boot(KernelConfig::theme($appDir, $namespace, true, false));

        self::assertInstanceOf(Closure::class, $loader);

        // ローダーは .mo のパスを渡し、同名の .l10n.php の優先読み込みは WordPress の load_textdomain() が行う
        $moFile = \dirname(__DIR__, 2) . '/languages/tmswp-ja.mo';
        self::assertFileExists($moFile);
        Functions\expect('determine_locale')->once()->withNoArgs()->andReturn('ja');
        Functions\expect('load_textdomain')->once()->with('tmswp', $moFile)->andReturn(true);

        $loader();
    }

    public function testBootLoadsDiscoveryFileWithoutScanningWhenDebugIsDisabled(): void
    {
        $appDir = $this->createTempDirectory('discovery-file-kernel-app');
        $namespace = 'Example\\Tests\\DiscoveryFileKernelApp' . uniqid();
        $fileOnlyClass = $namespace . '\\Hooks\\FileOnlyHook';
        $config = KernelConfig::theme($appDir, $namespace, false, false);

        // 走査すれば見つかる app/ のクラスと、探索結果ファイルにだけ書いたクラスを用意し、ファイルの方が使われることを示す
        $this->writeFile($appDir . '/Hooks/ScannedHook.php', $this->hookClass($namespace, 'ScannedHook', 'tmswp_scanned_hook'));
        (new DiscoveryFile($config))->write(new DiscoveryResult(
            [],
            [
                new DiscoveredHookMethod($fileOnlyClass, 'handle', 'tmswp_file_only_hook', 7, 1),
            ],
        ));

        Filters\expectAdded('tmswp_file_only_hook')->once()->with(Mockery::type(Closure::class), 7, 1);
        Filters\expectAdded('tmswp_scanned_hook')->never();
        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 1, 0);

        Kernel::boot($config);

        self::assertFalse(class_exists($fileOnlyClass, false));
        self::assertFalse(class_exists($namespace . '\\Hooks\\ScannedHook', false));
    }

    public function testBootFailsWhenDiscoveryFileIsMissingAndDebugIsDisabled(): void
    {
        $appDir = $this->createTempDirectory('missing-discovery-file-app');
        $namespace = 'Example\\Tests\\MissingDiscoveryFileApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false, false);

        try {
            Kernel::boot($config);
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringContainsString($config->discoveryFile(), $exception->getMessage());
            self::assertStringContainsString('wp tmswp discover', $exception->getMessage());
        }

        self::assertSame([], Kernel::all());
    }

    public function testDebugBootScansAppDirectoryAndIgnoresDiscoveryFile(): void
    {
        $appDir = $this->createTempDirectory('debug-scan-kernel-app');
        $namespace = 'Example\\Tests\\DebugScanKernelApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, true, false);
        $this->writeFile($appDir . '/Hooks/ScannedHook.php', $this->hookClass($namespace, 'ScannedHook', 'tmswp_debug_scanned_hook'));
        (new DiscoveryFile($config))->write(new DiscoveryResult(
            [],
            [
                new DiscoveredHookMethod($namespace . '\\Hooks\\FileOnlyHook', 'handle', 'tmswp_debug_file_only_hook', 10, 1),
            ],
        ));
        $autoload = $this->registerAppAutoloader($namespace, $appDir);

        Filters\expectAdded('tmswp_debug_scanned_hook')->once()->with(Mockery::type(Closure::class), 10, 1);
        Filters\expectAdded('tmswp_debug_file_only_hook')->never();

        try {
            Kernel::boot($config);
        } finally {
            spl_autoload_unregister($autoload);
        }
    }

    public function testFailedScanLeavesNoRegistrations(): void
    {
        $appDir = $this->createTempDirectory('failed-scan-kernel-app');
        $namespace = 'Example\\Tests\\FailedScanKernelApp' . uniqid();

        // 先に走査される AlphaHook は正しく、後の ZuluHook が誤り(#[AsHook] なしのメソッド属性)
        $this->writeFile($appDir . '/Hooks/AlphaHook.php', $this->hookClass($namespace, 'AlphaHook', 'tmswp_alpha_hook'));
        $this->writeFile($appDir . '/Hooks/ZuluHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;

final class ZuluHook
{
    #[Action('tmswp_zulu_hook')]
    public function handle(): void
    {
    }
}
PHP);
        $autoload = $this->registerAppAutoloader($namespace, $appDir);

        Filters\expectAdded('tmswp_alpha_hook')->never();
        Actions\expectAdded('init')->never();

        try {
            Kernel::boot(KernelConfig::theme($appDir, $namespace, true, false));
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringContainsString("{$namespace}\\Hooks\\ZuluHook", $exception->getMessage());
        } finally {
            spl_autoload_unregister($autoload);
        }

        self::assertSame([], Kernel::all());
    }

    public function testBootRejectsKernelSharingDiscoveryFile(): void
    {
        $root = $this->createTempDirectory('shared-discovery-file-root');
        $this->writeFile($root . '/app-a/.keep', '');
        $this->writeFile($root . '/app-b/.keep', '');
        $first = KernelConfig::theme($root . '/app-a', 'Example\\Tests\\SharedDiscoveryA' . uniqid(), false, false);
        $second = KernelConfig::theme($root . '/app-b', 'Example\\Tests\\SharedDiscoveryB' . uniqid(), false, false);
        $firstFile = new DiscoveryFile($first);
        $firstFile->write(new DiscoveryResult([], []));
        $contents = file_get_contents($first->discoveryFile());

        Kernel::boot($first);

        try {
            Kernel::boot($second);
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringContainsString("share the discovery file {$first->discoveryFile()}", $exception->getMessage());
        }

        // 衝突した Kernel は登録されないため、探索コマンドが同じファイルを2つの Kernel の内容で上書きすることもない
        self::assertSame([$first->appNamespace], array_keys(Kernel::all()));
        self::assertSame($contents, file_get_contents($first->discoveryFile()));
        self::assertTrue($firstFile->isUpToDate(new DiscoveryResult([], [])));
    }

    #[RunInSeparateProcess]
    public function testPluginActivationSandboxRegistersLifecycleHooksWithoutRuntimeScheduling(): void
    {
        \define('WP_SANDBOX_SCRAPING', true);

        $appDir = $this->createTempDirectory('kernel-plugin-activation-app');
        $pluginFile = $this->writeFile($this->createTempDirectory('kernel-plugin-activation-root') . '/plugin.php', "<?php\n");
        $namespace = 'Example\\Tests\\PluginActivationApp' . uniqid();
        $hookFile = $appDir . '/Hook/ActivationHook.php';

        $this->writeFile($hookFile, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class ActivationHook extends Hook
{
    public function register(): void
    {
    }
}
PHP);

        $autoload = static function (string $class) use ($namespace, $appDir): void {
            $prefix = $namespace . '\\';

            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, \strlen($prefix));
            $file = $appDir . '/' . str_replace('\\', '/', $relative) . '.php';

            if (!is_file($file)) {
                throw new RuntimeException("autoload target file not found: {$file}");
            }

            require $file;
        };

        spl_autoload_register($autoload);

        Functions\expect('TMSWP\Core\Kernel\register_activation_hook')->once()->with(Mockery::type('string'), Mockery::type(Closure::class));
        Functions\expect('TMSWP\Core\Kernel\register_deactivation_hook')->once()->with(Mockery::type('string'), Mockery::type(Closure::class));
        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 99, 0);
        Actions\expectAdded('tmswp/activated')->once()->with(Mockery::type(Closure::class), 10, 1);
        Actions\expectAdded('admin_init')->once()->with(Mockery::type(Closure::class), 5, 0);
        Functions\expect('did_action')->never();

        try {
            $kernel = Kernel::boot(KernelConfig::plugin($pluginFile, $appDir, $namespace, true));
        } finally {
            spl_autoload_unregister($autoload);
        }

        self::assertSame($kernel, Kernel::get($namespace));
        self::assertCount(1, $kernel->components());
    }

    private static function isActionRouterCallback(mixed $callback): bool
    {
        if (!\is_array($callback) || \count($callback) !== 2) {
            return false;
        }

        if (!$callback[0] instanceof ActionRouter) {
            return false;
        }

        return $callback[1] === 'dispatch';
    }

    private function hookClass(string $namespace, string $class, string $hook): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class {$class} extends Hook
{
    #[Action('{$hook}')]
    public function handle(mixed \$value): void
    {
    }
}
PHP;
    }

    /** @return Closure(string): void */
    private function registerAppAutoloader(string $namespace, string $appDir): Closure
    {
        $autoload = static function (string $class) use ($namespace, $appDir): void {
            $prefix = $namespace . '\\';

            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $file = $appDir . '/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

            if (is_file($file)) {
                require $file;
            }
        };

        spl_autoload_register($autoload);

        return $autoload;
    }

    private function writeFile(string $path, string $contents): string
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("ファイルを書き込めません: {$path}");
        }

        return $path;
    }
}
