<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Psr\Log\AbstractLogger;
use Stringable;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Log\Log;
use TMSWP\Core\Log\LoggerRegistry;
use TMSWP\Core\Tests\Support\IntegrationTestCase;
use TMSWP\Core\Tests\Support\UsesTempDirectories;
use WP_Hook;

final class KernelBootIntegrationTest extends IntegrationTestCase
{
    use UsesTempDirectories;

    public function tear_down(): void
    {
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    public function testFixtureKernelsAreBootedInDebugModeByWordPressLoading(): void
    {
        $theme = self::themeKernel()->config();
        $plugin = self::pluginKernel()->config();

        // debug 時の遅延 boot は例外になるため、debug=true で boot 済みであること自体が init 前の boot を示す
        self::assertSame(KernelContext::Theme, $theme->context);
        self::assertTrue($theme->debug);
        self::assertSame(get_stylesheet_directory() . '/app', $theme->appDir);

        self::assertSame(KernelContext::Plugin, $plugin->context);
        self::assertTrue($plugin->debug);
        self::assertNotNull($plugin->pluginFile);
        $activePlugins = get_option('active_plugins');
        self::assertIsArray($activePlugins);
        self::assertContains(plugin_basename($plugin->pluginFile), $activePlugins);
    }

    public function testCommittedFixtureDiscoveryFilesMatchTheScan(): void
    {
        foreach ([self::themeKernel(), self::pluginKernel()] as $kernel) {
            $file = $kernel->container()->get(DiscoveryFile::class);
            $discovery = $kernel->container()->get(Discovery::class);
            self::assertInstanceOf(DiscoveryFile::class, $file);
            self::assertInstanceOf(Discovery::class, $discovery);

            $scanned = $discovery->scan();

            self::assertSame(\dirname($kernel->config()->appDir) . '/tmswp-discovery.php', $file->path());
            self::assertEquals($scanned, $file->load());
            self::assertTrue($file->isUpToDate($scanned), "{$file->path()} is out of date; run composer discover:build");
        }

        $hookClass = self::PLUGIN_NAMESPACE . '\\Hook\\PluginProbeHook';
        self::assertEquals(
            [
                new DiscoveredHookMethod($hookClass, 'filter', 'tmswp_fixture_plugin/filter', 10, 1),
                new DiscoveredHookMethod($hookClass, 'handle', 'tmswp_fixture_plugin/action', 10, 0),
            ],
            $this->pluginDiscoveryFile()->load()->hookMethods,
        );
    }

    public function testBootFailsWithoutDiscoveryFileWhenDebugIsDisabled(): void
    {
        $config = $this->productionPluginConfig('MissingDiscoveryFile');

        try {
            Kernel::boot($config);
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringContainsString("discovery file not found: {$config->discoveryFile()}", $exception->getMessage());
            self::assertStringContainsString('wp tmswp discover', $exception->getMessage());
        }

        self::assertArrayNotHasKey($config->appNamespace, Kernel::all());
    }

    public function testBootUsesDiscoveryFileWithoutScanningWhenDebugIsDisabled(): void
    {
        $config = $this->productionPluginConfig('DiscoveryFileOnly');
        $scannedHook = 'tmswp_integration/scanned_only';
        $fileOnlyHook = 'tmswp_integration/discovery_file_only';

        // app/ には走査すれば見つかる Hook クラスを置き、探索結果ファイルには app/ に無いクラスのフックだけを書く
        $this->writeFile($config->appDir . '/Hook/ScannedHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$config->appNamespace}\\Hook;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class ScannedHook extends Hook
{
    #[Action('{$scannedHook}')]
    public function handle(): void
    {
    }
}
PHP);
        (new DiscoveryFile($config))->write(new DiscoveryResult([], [
            new DiscoveredHookMethod($config->appNamespace . '\\Hook\\FileOnlyHook', 'handle', $fileOnlyHook, 15, 0),
        ]));

        $messages = $this->captureLogs(static fn(): Kernel => Kernel::boot($config));

        $filters = $GLOBALS['wp_filter'];
        self::assertIsArray($filters);
        $registered = $filters[$fileOnlyHook] ?? null;
        self::assertInstanceOf(WP_Hook::class, $registered);
        self::assertSame([15], array_keys($registered->callbacks));
        self::assertFalse(has_filter($scannedHook));
        self::assertFalse(class_exists($config->appNamespace . '\\Hook\\ScannedHook', false));
        self::assertArrayHasKey($config->appNamespace, Kernel::all());

        // init の後に boot するため遅延 boot のログだけが残り、探索の失敗は記録されない
        foreach ($messages as $message) {
            self::assertStringNotContainsString('tmswp.discovery', $message);
        }
    }

    private function pluginDiscoveryFile(): DiscoveryFile
    {
        $file = self::pluginKernel()->container()->get(DiscoveryFile::class);
        self::assertInstanceOf(DiscoveryFile::class, $file);

        return $file;
    }

    /**
     * 本番相当(debug=false)のプラグイン形態の構成を、一時ディレクトリのプラグインで作る。テーマ形態にしないのは、
     * テーマ切替のフック(他のテストが発火させる)にこの Kernel を登録しないためである。
     */
    private function productionPluginConfig(string $name): KernelConfig
    {
        $root = $this->createTempDirectory('integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");

        return KernelConfig::plugin($root . '/plugin.php', $root . '/app', 'TmswpIntegration' . $name . uniqid(), false);
    }

    /**
     * Log の出力先を一時的に差し替えて、ログをファイルに書かずに捕まえる。
     *
     * @param callable(): mixed $callback
     *
     * @return list<string> 記録されたメッセージ
     */
    private function captureLogs(callable $callback): array
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            /** @param array<mixed> $context */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $registry = new LoggerRegistry();
        $registry->set('integration', $logger);
        $previous = Log::registry();
        Log::setRegistry($registry);

        try {
            $callback();
        } finally {
            Log::setRegistry($previous);
        }

        return $logger->messages;
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
