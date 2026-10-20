<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Attribute;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Closure;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\HookMethodKind;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\Tests\Support\WpdbTestDouble;
use TMSWP\Core\View\ActionRouter;

final class KernelTest extends UnitTestCase
{
    /** @var list<Closure(string): void> tearDown で外す app/ の autoload */
    private array $autoloaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = WpdbTestDouble::create();
        // WordPress が wp-includes/version.php と plugin.php で用意するグローバル。boot はこれで版と発火済みのフックを知る
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        KernelTestRecordingInitializer::$initialized = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        unset($GLOBALS['wpdb'], $GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        Kernel::reset();
        parent::tearDown();
    }

    public function testThemeBootRegistersLifecycleHooksAndActionRouter(): void
    {
        $appDir = $this->createTempDirectory('kernel-app');
        $namespace = 'Example\\Tests\\KernelApp' . uniqid();

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
                new DiscoveredHookMethod($fileOnlyClass, 'handle', 'tmswp_file_only_hook', 7, 1, HookMethodKind::Action),
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
                new DiscoveredHookMethod($namespace . '\\Hooks\\FileOnlyHook', 'handle', 'tmswp_debug_file_only_hook', 10, 1, HookMethodKind::Action),
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
        Actions\expectAdded('init')->never();
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

    /** @return iterable<string, array{string}> */
    public static function unsupportedWordPressVersions(): iterable
    {
        yield '6.9' => ['6.9'];
        yield '6.9.4' => ['6.9.4'];
        yield '6.9 の RC' => ['6.9-RC1'];
        yield '6.0' => ['6.0'];
    }

    #[DataProvider('unsupportedWordPressVersions')]
    public function testBootRejectsWordPressOlderThanSevenRegardlessOfDebug(string $version): void
    {
        $GLOBALS['wp_version'] = $version;

        foreach ([true, false] as $debug) {
            $config = KernelConfig::theme(
                $this->createTempDirectory('old-wp-app'),
                'Example\Tests\OldWordPressApp' . uniqid(),
                $debug,
                false,
            );

            try {
                Kernel::boot($config);
                self::fail('KernelException was not thrown');
            } catch (KernelException $exception) {
                self::assertSame("TMSWP requires WordPress 7.0 or later (running {$version})", $exception->getMessage());
            }
        }

        self::assertSame([], Kernel::all());
    }

    /** @return iterable<string, array{string}> */
    public static function supportedWordPressVersions(): iterable
    {
        yield '7.0' => ['7.0'];
        // WordPress 自身の「Requires at least」の判定と同じく、接尾辞を除いて比べる
        yield '7.0 の RC' => ['7.0-RC1'];
        yield '7.0 のベータ' => ['7.0-beta2'];
        yield '7.0 の開発版' => ['7.0-alpha-60000-src'];
        yield '7.0.1' => ['7.0.1'];
        yield '7.1' => ['7.1'];
        yield '8.0' => ['8.0'];
    }

    #[DataProvider('supportedWordPressVersions')]
    public function testBootAcceptsWordPressSevenAndLaterIncludingPrereleases(string $version): void
    {
        $GLOBALS['wp_version'] = $version;
        $namespace = 'Example\Tests\SupportedWordPressApp' . uniqid();

        Kernel::boot(KernelConfig::theme($this->createTempDirectory('supported-wp-app'), $namespace, true, false));

        self::assertArrayHasKey($namespace, Kernel::all());
    }

    public function testBootFailsWhenWordPressVersionIsUnknown(): void
    {
        unset($GLOBALS['wp_version']);

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage('cannot determine the WordPress version');

        Kernel::boot(KernelConfig::theme($this->createTempDirectory('unknown-wp-app'), 'Example\Tests\UnknownWordPressApp' . uniqid(), true, false));
    }

    public function testThemeActivationAndDeactivationOnlyDeleteRewriteRules(): void
    {
        $namespace = 'Example\Tests\ThemeLifecycleApp' . uniqid();
        $activate = null;
        $deactivate = null;

        Actions\expectAdded('after_switch_theme')->once()->with(Mockery::type(Closure::class), 10, 0)
            ->whenHappen(static function (Closure $callback) use (&$activate): void {
                $activate = $callback;
            });
        Actions\expectAdded('switch_theme')->once()->with(Mockery::type(Closure::class), 10, 0)
            ->whenHappen(static function (Closure $callback) use (&$deactivate): void {
                $deactivate = $callback;
            });

        $kernel = Kernel::boot(KernelConfig::theme($this->createTempDirectory('theme-lifecycle-app'), $namespace, true, false));

        $this->assertLifecycleCallbacksOnlyDeleteRewriteRules($kernel, $activate, $deactivate);
    }

    public function testPluginActivationAndDeactivationOnlyDeleteRewriteRules(): void
    {
        $pluginFile = $this->writeFile($this->createTempDirectory('plugin-lifecycle-root') . '/plugin.php', "<?php\n");
        $namespace = 'Example\Tests\PluginLifecycleApp' . uniqid();
        $activate = null;
        $deactivate = null;

        Functions\expect('TMSWP\Core\Kernel\register_activation_hook')->once()
            ->andReturnUsing(static function (string $file, Closure $callback) use (&$activate): void {
                $activate = $callback;
            });
        Functions\expect('TMSWP\Core\Kernel\register_deactivation_hook')->once()
            ->andReturnUsing(static function (string $file, Closure $callback) use (&$deactivate): void {
                $deactivate = $callback;
            });

        $kernel = Kernel::boot(KernelConfig::plugin($pluginFile, $this->createTempDirectory('plugin-lifecycle-app'), $namespace, true));

        $this->assertLifecycleCallbacksOnlyDeleteRewriteRules($kernel, $activate, $deactivate);
    }

    public function testBootRegistersNoPerRequestRewriteCheck(): void
    {
        $priorities = [];

        // 有効化のフラグを毎リクエストの init で確かめる処理は無い(TMSWP が登録する init は翻訳の読み込みと MetaSchema だけ)
        Actions\expectAdded('init')->twice()
            ->whenHappen(static function (Closure $callback, int $priority) use (&$priorities): void {
                $priorities[] = $priority;
            });
        Functions\expect('get_option')->never();
        Functions\expect('add_option')->never();

        Kernel::boot(KernelConfig::theme($this->createTempDirectory('no-rewrite-check-app'), 'Example\Tests\NoRewriteCheckApp' . uniqid(), true, false));

        sort($priorities);
        self::assertSame([1, 20], $priorities);
    }

    public function testHookMethodIsCalledWithWordPressWeakTypeCoercion(): void
    {
        $filter = $this->bootWithFileOnlyHookMethod('tmswp_coercion_filter', 'addOne', 1, HookMethodKind::Filter, 1);

        // strict_types のファイルから直接呼ぶと TypeError になる数値文字列を、WordPress と同じく int に変換して渡す
        self::assertSame(42, $filter('41'));
    }

    public function testFailingHookMethodIsLoggedAndSkippedInProduction(): void
    {
        $logger = new KernelTestLogger();
        $filter = $this->bootWithFileOnlyHookMethod('tmswp_failing_filter', 'fail', 2, HookMethodKind::Filter, 2, $logger);

        // filter は第1引数をそのまま返し、値を変えない
        self::assertSame('original', $filter('original', 'extra'));

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('tmswp.kernel: hook method {class}::{method} failed on {hook}', $logger->records[0]['message']);
        self::assertSame(KernelTestHookProbe::class, $logger->records[0]['context']['class'] ?? null);
        self::assertSame('fail', $logger->records[0]['context']['method'] ?? null);
        self::assertSame('tmswp_failing_filter', $logger->records[0]['context']['hook'] ?? null);
        self::assertInstanceOf(RuntimeException::class, $logger->records[0]['context']['exception'] ?? null);
    }

    public function testFailingActionWithoutArgumentsIsSkippedInProduction(): void
    {
        $logger = new KernelTestLogger();
        // Action は宣言した引数の数(0)で登録する。戻り値は WordPress が使わない
        $action = $this->bootWithFileOnlyHookMethod('tmswp_failing_action', 'failWithoutArguments', 0, HookMethodKind::Action, 0, $logger);

        self::assertNull($action());
        self::assertCount(1, $logger->records);
    }

    public function testFailingFilterWithoutArgumentsReturnsOriginalValueInProduction(): void
    {
        $logger = new KernelTestLogger();
        // 引数を宣言しない Filter も、元の値を返せるよう第1引数を受け取って登録する
        $filter = $this->bootWithFileOnlyHookMethod('tmswp_failing_zero_filter', 'failWithoutArguments', 0, HookMethodKind::Filter, 1, $logger);

        self::assertSame('original', $filter('original'));
        self::assertCount(1, $logger->records);
    }

    public function testFilterWithoutArgumentsReturnsHandlerValueAndReceivesNoArguments(): void
    {
        $filter = $this->bootWithFileOnlyHookMethod('tmswp_zero_filter', 'countArguments', 0, HookMethodKind::Filter, 1);

        // 登録は第1引数を受け取るが、メソッドには宣言した引数の数(0)に切り詰めて渡す
        self::assertSame(0, $filter('original'));
    }

    public function testFailingHookMethodThrowsInDebug(): void
    {
        $logger = new KernelTestLogger();
        $appDir = $this->createTempDirectory('failing-debug-hook-app');
        $namespace = 'Example\Tests\FailingDebugHookApp' . uniqid();
        $this->writeFile($appDir . '/Hooks/FailingHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class FailingHook extends Hook
{
    #[Filter('tmswp_failing_debug_filter')]
    public function fail(string \$value): string
    {
        throw new \\RuntimeException('hook method failed in debug');
    }
}
PHP);
        $autoload = $this->registerAppAutoloader($namespace, $appDir);

        try {
            $filter = $this->bootAndCaptureHookCallback(KernelConfig::theme($appDir, $namespace, true, false, logChannels: self::recordTo($logger)), 'tmswp_failing_debug_filter', 1);

            try {
                $filter('original');
                self::fail('RuntimeException was not thrown');
            } catch (RuntimeException $exception) {
                self::assertSame('hook method failed in debug', $exception->getMessage());
            }
        } finally {
            spl_autoload_unregister($autoload);
        }

        self::assertSame([], $logger->records);
    }

    public function testUnresolvableHookHandlerIsLoggedAndSkippedInProduction(): void
    {
        $logger = new KernelTestLogger();
        $appDir = $this->createTempDirectory('stale-hook-app');
        $namespace = 'Example\Tests\StaleHookApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false, false, logChannels: self::recordTo($logger));

        // 探索結果ファイルが古く、削除済みのクラスを指している
        (new DiscoveryFile($config))->write(new DiscoveryResult([], [
            new DiscoveredHookMethod($namespace . '\Hook\RemovedHook', 'handle', 'tmswp_stale_filter', 10, 1, HookMethodKind::Filter),
        ]));
        $filter = $this->bootAndCaptureHookCallback($config, 'tmswp_stale_filter', 1);

        self::assertSame('value', $filter('value'));
        self::assertCount(1, $logger->records);
    }

    public function testLateBootCatchesUpFiredHooksInWordPressFiringOrder(): void
    {
        $logger = new KernelTestLogger();
        $appDir = $this->createTempDirectory('catch-up-app');
        $namespace = 'Example\Tests\CatchUpApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false, false, logChannels: self::recordTo($logger));
        $attribute = KernelTestCatchUpAttribute::class;

        // コンポーネントは FQCN の順に並ぶ。フック名の辞書順(init < plugins_loaded)とも priority の順とも違う順にしておく
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\A', $attribute, ['hook' => 'init', 'priority' => 10]),
            new DiscoveredComponent($namespace . '\B', $attribute, ['hook' => 'plugins_loaded', 'priority' => 5]),
            new DiscoveredComponent($namespace . '\C', $attribute, ['hook' => 'wp_loaded', 'priority' => 10]),
            new DiscoveredComponent($namespace . '\D', $attribute, ['hook' => 'init', 'priority' => 9]),
            new DiscoveredComponent($namespace . '\E', $attribute, ['hook' => 'plugins_loaded', 'priority' => 0]),
        ], []));

        // WordPress の読み込みどおりの発火順。値は発火回数で、順序には関係しない
        $GLOBALS['wp_actions'] = [
            'muplugins_loaded' => 1,
            'plugins_loaded' => 1,
            'setup_theme' => 1,
            'after_setup_theme' => 1,
            'init' => 3,
        ];

        // 未発火のフックは従来どおり add_action する
        Actions\expectAdded('wp_loaded')->once()->with(Mockery::type(Closure::class), 10, 0);

        Kernel::boot($config);

        self::assertSame(
            [$namespace . '\E', $namespace . '\B', $namespace . '\D', $namespace . '\A'],
            KernelTestRecordingInitializer::$initialized,
        );
        self::assertSame(
            [
                "Kernel::boot() was called after the 'plugins_loaded' hook had fired. Boot from the top level of functions.php or the plugin main file.",
                "Kernel::boot() was called after the 'plugins_loaded' hook had fired. Boot from the top level of functions.php or the plugin main file.",
                "Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file.",
                "Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file.",
                // MetaSchema の登録(init 20)も同じ規則で、init のコンポーネントの後に動く
                "Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file.",
            ],
            array_column($logger->records, 'message'),
        );
    }

    public function testLateBootInDebugThrowsForEarliestFiredHookWithoutRegisteringOrRunningAnything(): void
    {
        $appDir = $this->createTempDirectory('late-debug-app');
        $namespace = 'Example\Tests\LateDebugApp' . uniqid();
        $attribute = KernelTestCatchUpAttribute::class;
        $this->writeFile($appDir . '/Late/A.php', $this->catchUpComponentClass($namespace, 'A', 'init', 10));
        $this->writeFile($appDir . '/Late/B.php', $this->catchUpComponentClass($namespace, 'B', 'plugins_loaded', 5));
        $this->writeFile($appDir . '/Hooks/LateHook.php', $this->hookClass($namespace, 'LateHook', 'tmswp_late_debug_hook'));
        $autoload = $this->registerAppAutoloader($namespace, $appDir);
        $GLOBALS['wp_actions'] = ['plugins_loaded' => 1, 'init' => 1];

        // 遅延 boot の検査はどのフックの登録よりも先で、フックメソッドもグループも登録されない
        Filters\expectAdded('tmswp_late_debug_hook')->never();
        Actions\expectAdded('init')->never();
        Actions\expectAdded('plugins_loaded')->never();

        try {
            Kernel::boot(KernelConfig::theme($appDir, $namespace, true, false));
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringContainsString("after the 'plugins_loaded' hook had fired", $exception->getMessage());
        } finally {
            spl_autoload_unregister($autoload);
        }

        self::assertSame([], KernelTestRecordingInitializer::$initialized);
        self::assertSame([], Kernel::all());
        self::assertTrue(class_exists($attribute));
    }

    public function testInvalidPostTypeComponentThrowsInProduction(): void
    {
        $appDir = $this->createTempDirectory('invalid-post-type-app');
        $namespace = 'Example\Tests\InvalidPostTypeApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false, false);
        // PostType を継承しないクラスに #[AsPostType] が付いている(探索結果ファイルが古い等の設定の誤り)
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent(KernelTestHookProbe::class, AsPostType::class, []),
        ], []));
        $runner = null;
        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 10, 0)
            ->whenHappen(static function (Closure $callback) use (&$runner): void {
                $runner = $callback;
            });

        Kernel::boot($config);

        self::assertInstanceOf(Closure::class, $runner);
        $this->expectException(DiscoveryException::class);
        $this->expectExceptionMessage('post type component must extend PostType: ' . KernelTestHookProbe::class);

        $runner();
    }

    public function testInitializerWithoutInterfaceThrowsInProduction(): void
    {
        $appDir = $this->createTempDirectory('invalid-initializer-app');
        $namespace = 'Example\Tests\InvalidInitializerApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false, false);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\Component', KernelTestCatchUpAttribute::class, [
                'hook' => 'tmswp_invalid_initializer',
                'priority' => 10,
                'initializer' => KernelTestHookProbe::class,
            ]),
        ], []));
        $runner = null;
        Actions\expectAdded('tmswp_invalid_initializer')->once()->with(Mockery::type(Closure::class), 10, 0)
            ->whenHappen(static function (Closure $callback) use (&$runner): void {
                $runner = $callback;
            });

        Kernel::boot($config);

        self::assertInstanceOf(Closure::class, $runner);
        $this->expectException(DiscoveryException::class);
        $this->expectExceptionMessage("initializer '" . KernelTestHookProbe::class . "' must implement ComponentInitializerInterface");

        $runner();
    }

    public function testFailingCatchUpInitializationFailsBootInProduction(): void
    {
        $logger = new KernelTestLogger();
        $appDir = $this->createTempDirectory('failing-catch-up-app');
        $namespace = 'Example\Tests\FailingCatchUpApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false, false, logChannels: self::recordTo($logger));
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent(KernelTestHookProbe::class, AsPostType::class, []),
        ], []));
        $GLOBALS['wp_actions'] = ['init' => 1];

        try {
            Kernel::boot($config);
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringContainsString('post type component must extend PostType', $exception->getMessage());
        }

        self::assertSame([], Kernel::all());
    }

    public function testProductionCatchUpRegistersKernelSoThatAppCodeCanLogToIt(): void
    {
        $logger = new KernelTestLogger();
        $root = $this->createTempDirectory('catch-up-log-root');
        $appDir = $root . '/app';
        $namespace = 'Example\Tests\CatchUpLogApp' . uniqid();
        // register() を上書きする #[AsHook] は、テーマ形態では after_setup_theme(0)で初期化される
        $this->writeFile($appDir . '/Hook/LateLogHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;
use TMSWP\\Core\\Log\\Log;

#[AsHook]
final class LateLogHook extends Hook
{
    public function register(): void
    {
        Log::error('logged during catch-up');
    }
}
PHP);
        $config = KernelConfig::theme($appDir, $namespace, false, false, logChannels: self::recordTo($logger));
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\Hook\LateLogHook', AsHook::class, []),
        ], []));
        $GLOBALS['wp_actions'] = ['after_setup_theme' => 1];
        // テーマ形態の Kernel は、子テーマのディレクトリも forFile() の判定に使う(ここでは子テーマ無し)
        Functions\when('get_template_directory')->justReturn($config->rootDir());
        Functions\when('get_stylesheet_directory')->justReturn($config->rootDir());
        $autoload = $this->registerAppAutoloader($namespace, $appDir);

        try {
            $kernel = Kernel::boot($config);
        } finally {
            spl_autoload_unregister($autoload);
        }

        // キャッチアップの中のアプリのコードの Log::error() が、この Kernel に記録される
        self::assertContains('logged during catch-up', array_column($logger->records, 'message'));
        self::assertSame($kernel, Kernel::get($namespace));
    }

    public function testFailedCatchUpRemovesKernelThatWasRegisteredForIt(): void
    {
        $appDir = $this->createTempDirectory('failed-catch-up-registration-app');
        $namespace = 'Example\Tests\FailedCatchUpRegistrationApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false, false, logChannels: self::recordTo(new KernelTestLogger()));
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\Failing', KernelTestCatchUpAttribute::class, ['hook' => 'init', 'priority' => 10, 'initializer' => KernelTestRegisteredThenFailingInitializer::class]),
        ], []));
        $GLOBALS['wp_actions'] = ['init' => 1];
        KernelTestRegisteredThenFailingInitializer::$registeredDuringCatchUp = null;

        try {
            Kernel::boot($config);
            self::fail('RuntimeException was not thrown');
        } catch (RuntimeException $exception) {
            self::assertSame('catch-up initialization failed', $exception->getMessage());
        }

        // キャッチアップの間は登録されていたが、失敗した boot の後には残らない
        self::assertTrue(KernelTestRegisteredThenFailingInitializer::$registeredDuringCatchUp);
        self::assertArrayNotHasKey($namespace, Kernel::all());
    }

    public function testInitializerCanUseServiceRegisteredByExtensionInNormalBoot(): void
    {
        $config = $this->extensionServiceConfig('NormalBootService', debug: true);
        $runner = null;
        // init は未発火。init 10 のグループは add_action され、発火のときに初期化する
        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 10, 0)
            ->whenHappen(static function (Closure $callback) use (&$runner): void {
                $runner = $callback;
            });

        $kernel = Kernel::boot($config);
        self::assertInstanceOf(Closure::class, $runner);
        $runner();

        self::assertSame([$kernel->container()->get(KernelTestExtensionService::class)], KernelTestServiceUsingInitializer::$resolved);
    }

    public function testInitializerCanUseServiceRegisteredByExtensionInProductionCatchUp(): void
    {
        $config = $this->extensionServiceConfig('CatchUpService', debug: false);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($config->appNamespace . '\Uses', KernelTestCatchUpAttribute::class, ['hook' => 'init', 'priority' => 10, 'initializer' => KernelTestServiceUsingInitializer::class]),
        ], []));
        // init は発火済み。キャッチアップの初期化より先に拡張が登録され、サービスを使える
        $GLOBALS['wp_actions'] = ['init' => 1];

        $kernel = Kernel::boot($config);

        self::assertSame([$kernel->container()->get(KernelTestExtensionService::class)], KernelTestServiceUsingInitializer::$resolved);
    }

    /**
     * 拡張が Container に KernelTestExtensionService を入れ、init 10 のコンポーネントの初期化がそれを使う構成を作る。
     * 本番の構成のコンポーネントは、呼び出し側が探索結果ファイルに書く。
     */
    private function extensionServiceConfig(string $name, bool $debug): KernelConfig
    {
        KernelTestServiceUsingInitializer::$resolved = [];
        $root = $this->createTempDirectory('extension-service-' . strtolower($name));
        $appDir = $root . '/app';
        $namespace = 'Example\\Tests\\' . $name . uniqid();

        if ($debug) {
            // debug の boot は appDir を走査するため、init 10 のコンポーネントを app/ に置く
            $attribute = KernelTestCatchUpAttribute::class;
            $initializer = KernelTestServiceUsingInitializer::class;
            $this->writeFile($appDir . '/Uses.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

#[\\{$attribute}(hook: 'init', priority: 10, initializer: \\{$initializer}::class)]
final class Uses
{
}
PHP);
            $this->autoloaders[] = $this->registerAppAutoloader($namespace, $appDir);
        } elseif (!mkdir($appDir, 0775, true)) {
            self::fail("ディレクトリを作成できません: {$appDir}");
        }

        return KernelConfig::theme($appDir, $namespace, $debug, false, extensions: [new KernelTestServiceExtension()], logChannels: self::recordTo(new KernelTestLogger()));
    }

    private function assertLifecycleCallbacksOnlyDeleteRewriteRules(Kernel $kernel, mixed $activate, mixed $deactivate): void
    {
        self::assertInstanceOf(Closure::class, $activate);
        self::assertInstanceOf(Closure::class, $deactivate);

        Functions\expect('delete_option')->twice()->with('rewrite_rules')->andReturn(true);
        Functions\expect('flush_rewrite_rules')->never();
        Functions\expect('add_option')->never();
        Functions\expect('update_option')->never();
        Actions\expectDone('tmswp/activated')->once()->with($kernel);
        Actions\expectDone('tmswp/deactivated')->once()->with($kernel);

        $activate();
        $deactivate();
    }

    /**
     * 探索結果ファイルにだけ KernelTestHookProbe のメソッドを書いて本番相当(debug=false)で boot し、登録されたコールバックを返す。
     *
     * @return Closure(mixed...): mixed
     */
    private function bootWithFileOnlyHookMethod(string $hook, string $method, int $acceptedArgs, HookMethodKind $kind, int $registeredArgs, KernelTestLogger $logger = new KernelTestLogger()): Closure
    {
        $config = KernelConfig::theme($this->createTempDirectory('hook-method-app'), 'Example\Tests\HookMethodApp' . uniqid(), false, false, logChannels: self::recordTo($logger));
        (new DiscoveryFile($config))->write(new DiscoveryResult([], [
            new DiscoveredHookMethod(KernelTestHookProbe::class, $method, $hook, 10, $acceptedArgs, $kind),
        ]));

        return $this->bootAndCaptureHookCallback($config, $hook, $registeredArgs);
    }

    /** @return Closure(mixed...): mixed */
    private function bootAndCaptureHookCallback(KernelConfig $config, string $hook, int $registeredArgs): Closure
    {
        $callback = null;

        Filters\expectAdded($hook)->once()->with(Mockery::type(Closure::class), 10, $registeredArgs)
            ->whenHappen(static function (Closure $registered) use (&$callback): void {
                $callback = $registered;
            });

        Kernel::boot($config);

        self::assertInstanceOf(Closure::class, $callback);

        return $callback;
    }

    /**
     * Kernel のログを、記録を残すだけのロガーへ送る logChannels を返す。
     *
     * @return Closure(): array<string, LogChannel>
     */
    private static function recordTo(KernelTestLogger $logger): Closure
    {
        return static fn(): array => ['test' => new LogChannel($logger)];
    }

    private function catchUpComponentClass(string $namespace, string $class, string $hook, int $priority): string
    {
        $attribute = KernelTestCatchUpAttribute::class;

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Late;

#[\\{$attribute}(hook: '{$hook}', priority: {$priority})]
final class {$class}
{
}
PHP;
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

/**
 * 探索結果ファイルから呼ばれるフックメソッドの代役。
 */
final class KernelTestHookProbe
{
    public function addOne(int $value): int
    {
        return $value + 1;
    }

    public function fail(string $value, string $extra): string
    {
        throw new RuntimeException('hook method failed');
    }

    public function failWithoutArguments(): void
    {
        throw new RuntimeException('hook method failed');
    }

    public function countArguments(): int
    {
        return \func_num_args();
    }
}

/**
 * フック名と priority を引数で受け取るコンポーネント属性。キャッチアップの順序を確かめるために使う。
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class KernelTestCatchUpAttribute implements ComponentAttributeInterface
{
    /**
     * @param class-string<ComponentInitializerInterface> $initializer
     */
    public function __construct(
        private string $hook,
        private int $priority,
        private string $initializer = KernelTestRecordingInitializer::class,
    ) {}

    public function hookName(KernelContext $context): string
    {
        return $this->hook;
    }

    public function hookPriority(): int
    {
        return $this->priority;
    }

    public function initializerClass(): string
    {
        return $this->initializer;
    }
}

/** 拡張が Container に登録するサービスの id(interface のため autowire では解決できない) */
interface KernelTestExtensionService {}

final class KernelTestExtensionServiceImplementation implements KernelTestExtensionService {}

/**
 * Container に KernelTestExtensionService を登録する拡張。
 */
final class KernelTestServiceExtension implements ExtensionInterface
{
    public function register(Kernel $kernel): void
    {
        $kernel->container()->set(KernelTestExtensionService::class, new KernelTestExtensionServiceImplementation());
    }
}

/**
 * 初期化で、拡張が登録した KernelTestExtensionService を Container から取る。
 */
final class KernelTestServiceUsingInitializer implements ComponentInitializerInterface
{
    /** @var list<KernelTestExtensionService> */
    public static array $resolved = [];

    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        self::$resolved[] = $kernel->container()->get(KernelTestExtensionService::class);
    }
}

/**
 * キャッチアップの初期化の時点で、そのコンポーネントの Kernel がレジストリにあったかを記録してから失敗する。
 */
final class KernelTestRegisteredThenFailingInitializer implements ComponentInitializerInterface
{
    public static ?bool $registeredDuringCatchUp = null;

    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        self::$registeredDuringCatchUp = \array_key_exists($kernel->config()->appNamespace, Kernel::all());

        throw new RuntimeException('catch-up initialization failed');
    }
}

/**
 * 初期化されたコンポーネントのクラス名を、初期化された順に記録する。
 */
final class KernelTestRecordingInitializer implements ComponentInitializerInterface
{
    /** @var list<string> */
    public static array $initialized = [];

    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        self::$initialized[] = $component->class;
    }
}

/**
 * 記録されたログを、出力せずに保持する。
 */
final class KernelTestLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
