<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Kernel\ComponentCache;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Tests\Support\IntegrationTestCase;

final class KernelBootIntegrationTest extends IntegrationTestCase
{
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

    public function testBootStoresDiscoveryCacheThatRestoresHookMethods(): void
    {
        $cache = self::pluginKernel()->container()->get(ComponentCache::class);
        self::assertInstanceOf(ComponentCache::class, $cache);
        self::assertFileExists($cache->file());

        $restored = $cache->load();
        self::assertNotNull($restored);

        $hookClass = self::PLUGIN_NAMESPACE . '\\Hook\\PluginProbeHook';
        self::assertEquals(
            [
                new DiscoveredHookMethod($hookClass, 'filter', 'tmswp_fixture_plugin/filter', 10, 1),
                new DiscoveredHookMethod($hookClass, 'handle', 'tmswp_fixture_plugin/action', 10, 0),
            ],
            $restored->hookMethods,
        );
    }
}
