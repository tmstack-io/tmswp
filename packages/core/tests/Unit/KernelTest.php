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
use TMSWP\Core\Kernel\ComponentCache;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
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
        $cacheDir = $this->createTempDirectory('kernel-cache');
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

        $kernel = Kernel::boot(KernelConfig::theme($appDir, $namespace, $cacheDir, true, true));

        self::assertSame($kernel, Kernel::get($namespace));
        self::assertSame([], $kernel->components());
    }

    public function testThemeBootCanDisableActionRouter(): void
    {
        $appDir = $this->createTempDirectory('kernel-no-actions-app');
        $cacheDir = $this->createTempDirectory('kernel-no-actions-cache');
        $namespace = 'Example\\Tests\\KernelNoActionsApp' . uniqid();

        Filters\expectAdded('template_include')->never();
        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 1, 0);

        Kernel::boot(KernelConfig::theme($appDir, $namespace, $cacheDir, true, false));
    }

    public function testBootUsesWarmCacheWithoutLoadingAppClasses(): void
    {
        $appDir = $this->createTempDirectory('warm-kernel-app');
        $cacheDir = $this->createTempDirectory('warm-kernel-cache');
        $namespace = 'Example\\Tests\\WarmKernelApp' . uniqid();
        $cachedClass = $namespace . '\\Hooks\\CachedHook';
        $config = KernelConfig::theme($appDir, $namespace, $cacheDir, false, false);
        $cache = new ComponentCache($cacheDir . '/components-' . $config->instanceId() . '.php', false);

        self::assertTrue($cache->store(new DiscoveryResult(
            [],
            [
                new DiscoveredHookMethod($cachedClass, 'handle', 'tmswp_cached_hook', 7, 1),
            ],
            [],
            [$appDir => $this->mtime($appDir)],
        )));
        self::assertFalse(class_exists($cachedClass, false));

        Filters\expectAdded('tmswp_cached_hook')->once()->with(Mockery::type(Closure::class), 7, 1);
        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 1, 0);

        Kernel::boot($config);

        self::assertFalse(class_exists($cachedClass, false));
    }

    #[RunInSeparateProcess]
    public function testPluginActivationSandboxRegistersLifecycleHooksWithoutRuntimeScheduling(): void
    {
        \define('WP_SANDBOX_SCRAPING', true);

        $appDir = $this->createTempDirectory('kernel-plugin-activation-app');
        $cacheDir = $this->createTempDirectory('kernel-plugin-activation-cache');
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
            $kernel = Kernel::boot(KernelConfig::plugin($pluginFile, $appDir, $namespace, $cacheDir, true));
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

    private function mtime(string $path): int
    {
        $mtime = filemtime($path);

        if ($mtime === false) {
            self::fail("mtime を取得できません: {$path}");
        }

        return $mtime;
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
