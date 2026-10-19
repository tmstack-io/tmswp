<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use Psr\Log\AbstractLogger;
use Stringable;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\HookMethodKind;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Log\Log;
use TMSWP\Core\Log\LoggerRegistry;
use TMSWP\Core\Tests\Support\IntegrationTestCase;
use TMSWP\Core\Tests\Support\UsesTempDirectories;
use WP_Hook;
use WP_Rewrite;

final class KernelBootIntegrationTest extends IntegrationTestCase
{
    use UsesTempDirectories;

    /** fixture のテーマの Contact(#[AsModel])のテーブル。テーマの有効化で Migrator が作る */
    private const FIXTURE_CONTACTS_TABLE = 'tmswp_fixture_contacts';

    /** @var list<Closure(string): void> */
    private array $autoloaders = [];

    public function tear_down(): void
    {
        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        // パーマリンクの設定は $wp_rewrite に残り、テストライブラリは戻さないため、変えたテストの後で既定(空)に戻す
        if (self::rewrite()->permalink_structure !== '') {
            $this->set_permalink_structure('');
        }

        // テスト中の CREATE TABLE はテストライブラリにより一時テーブルになり、接続が続く限り残るため削除する
        self::wpdb()->query('DROP TABLE IF EXISTS ' . self::wpdb()->prefix . self::FIXTURE_CONTACTS_TABLE);
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    public function testHookMethodReceivesNumericStringCoercedLikeWordPress(): void
    {
        // CoercionProbeHook::addOne(int $value)。strict_types のファイルから直接呼ぶと TypeError になる
        self::assertSame(42, apply_filters('tmswp_fixture_theme/int_filter', '41'));
    }

    /**
     * 前提の確認: rewrite_rules が空のとき、WordPress は wp_loaded より前のルールの作り直しを保存せず、wp_loaded で
     * 作り直して保存する(WP_Rewrite::refresh_rewrite_rules())。Kernel の有効化・無効化はこれに頼り、
     * rewrite_rules を消すだけにしている。
     */
    public function testWordPressDefersSavingRebuiltRewriteRulesUntilWpLoaded(): void
    {
        $rewrite = self::rewrite();
        $this->set_permalink_structure('/%postname%/');
        delete_option('rewrite_rules');
        $actions = $GLOBALS['wp_actions'];
        self::assertIsArray($actions);
        unset($actions['wp_loaded']);
        // wp_loaded の前の状態にする(書き換えた $wp_actions は tear_down でテストライブラリが戻す)
        $GLOBALS['wp_actions'] = $actions;

        self::assertNotEmpty($rewrite->wp_rewrite_rules());
        self::assertFalse(get_option('rewrite_rules'));
        self::assertNotFalse(has_action('wp_loaded', [$rewrite, 'flush_rules']));
    }

    public function testThemeActivationLetsNextRequestRebuildRewriteRulesWithPostTypes(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $config = $this->productionThemeConfig('ThemeRewrite');
        $postType = $this->bootProductionKernelWithPostType($config);

        try {
            // 有効化の前に、CPT を含まないルールが保存されている
            update_option('rewrite_rules', ['stale/?$' => 'index.php?stale=1']);

            do_action('after_switch_theme', 'Previous Theme', wp_get_theme());

            self::assertFalse(get_option('rewrite_rules'));
            $rules = $this->nextRequestRewriteRules($postType);
            self::assertArrayHasKey("{$postType}/?\$", $rules);
            self::assertArrayNotHasKey('stale/?$', $rules);
            self::assertSame($rules, get_option('rewrite_rules'));
        } finally {
            unregister_post_type($postType);
        }
    }

    public function testPluginActivationAddsAndDeactivationRemovesPostTypeRewriteRules(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $config = $this->productionPluginConfig('PluginRewrite');
        $postType = $this->bootProductionKernelWithPostType($config);
        $pluginBasename = plugin_basename((string) $config->pluginFile);

        try {
            // 有効化: その前に保存されていたルールを消し、次のリクエストで CPT を含めて作り直させる
            update_option('rewrite_rules', ['stale/?$' => 'index.php?stale=1']);
            do_action("activate_{$pluginBasename}");

            self::assertFalse(get_option('rewrite_rules'));
            self::assertArrayHasKey("{$postType}/?\$", $this->nextRequestRewriteRules($postType));

            // 無効化: そのリクエストではまだ CPT が登録されているが、そのルールを保存せずに消す
            do_action("deactivate_{$pluginBasename}");

            self::assertFalse(get_option('rewrite_rules'));
        } finally {
            // 次のリクエストでは、無効にしたプラグインは読み込まれず CPT も登録されない
            unregister_post_type($postType);
        }

        $rules = $this->nextRequestRewriteRules($postType);
        self::assertNotEmpty($rules);

        foreach (array_keys($rules) as $pattern) {
            self::assertStringNotContainsString($postType, (string) $pattern);
        }
    }

    public function testDebugLateBootRegistersNothingBeforeThrowing(): void
    {
        $root = $this->createTempDirectory('integration-debug-late-boot');
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $config = KernelConfig::plugin($root . '/plugin.php', $root . '/app', 'TmswpIntegrationDebugLate' . uniqid(), true);
        $namespace = $config->appNamespace;
        $postType = 'tmswp_dl_' . substr(md5($namespace), 0, 8);
        $hook = 'tmswp_integration/debug_late_filter';
        // init の後に debug で boot する。init 10 の投稿タイプと、フックメソッドの両方を持つ
        $this->writePostTypeClass($config, $postType);
        $this->writeFile($config->appDir . '/Hook/LateHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class LateHook extends Hook
{
    #[Filter('{$hook}')]
    public function filter(string \$value): string
    {
        return \$value . '-filtered';
    }
}
PHP);
        $this->registerAppAutoloader($config);
        $before = self::registeredCallbacks();

        try {
            Kernel::boot($config);
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringContainsString("after the 'init' hook had fired", $exception->getMessage());
        }

        self::assertSame(array_keys($before), array_keys(self::registeredCallbacks()));
        self::assertFalse(has_filter($hook));
        self::assertFalse(post_type_exists($postType));
        self::assertArrayNotHasKey($namespace, Kernel::all());
    }

    public function testKernelAddsNoDatabaseQueriesToNormalRequests(): void
    {
        $wpdb = self::wpdb();
        $config = $this->productionPluginConfig('QueryCount');
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        $before = self::registeredCallbacks();

        // プラグインの読み込み時(plugins_loaded の前)の状態で boot する。書き換えた $wp_actions は boot の後に戻す
        $actions = $GLOBALS['wp_actions'];
        self::assertIsArray($actions);
        $position = array_search('plugins_loaded', array_keys($actions), true);
        self::assertIsInt($position);
        $GLOBALS['wp_actions'] = \array_slice($actions, 0, $position, true);
        $queries = $wpdb->num_queries;

        try {
            Kernel::boot($config);
        } finally {
            $GLOBALS['wp_actions'] = $actions;
        }

        // Kernel が足したコールバックのうち、通常のリクエストで発火するフックのものを、WordPress と同じく実行する
        $lifecycleHooks = [
            'activate_' . plugin_basename((string) $config->pluginFile),
            'deactivate_' . plugin_basename((string) $config->pluginFile),
            'tmswp/activated',
            'admin_init',
        ];
        $requestCallbacks = array_filter(
            array_diff_key(self::registeredCallbacks(), $before),
            static fn(array $callback): bool => !\in_array($callback['hook'], $lifecycleHooks, true),
        );
        self::assertNotSame([], $requestCallbacks);

        foreach ($requestCallbacks as $callback) {
            self::assertSame(0, $callback['acceptedArgs'], "{$callback['hook']} の引数");
            self::assertIsCallable($callback['function']);
            \call_user_func($callback['function']);
        }

        self::assertSame($queries, $wpdb->num_queries);
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

            $scanned = $discovery->scan();

            self::assertSame(\dirname($kernel->config()->appDir) . '/tmswp-discovery.php', $file->path());
            self::assertEquals($scanned, $file->load());
            self::assertTrue($file->isUpToDate($scanned), "{$file->path()} is out of date; run composer discover:build");
        }

        $hookClass = self::PLUGIN_NAMESPACE . '\\Hook\\PluginProbeHook';
        self::assertEquals(
            [
                new DiscoveredHookMethod($hookClass, 'filter', 'tmswp_fixture_plugin/filter', 10, 1, HookMethodKind::Filter),
                new DiscoveredHookMethod($hookClass, 'handle', 'tmswp_fixture_plugin/action', 10, 0, HookMethodKind::Action),
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
            new DiscoveredHookMethod($config->appNamespace . '\\Hook\\FileOnlyHook', 'handle', $fileOnlyHook, 15, 0, HookMethodKind::Action),
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

    /**
     * 次のリクエストでルールが必要になったときの rewrite_rules を返す。
     *
     * 同じプロセスの WP_Rewrite は、ルールを作るたびに CPT のパーマリンク構造から作ったルールを extra_rules_top に
     * 足していき、unregister_post_type() でもその一部が残る。そのため次のリクエストと同じく新しい WP_Rewrite を作り、
     * 渡した投稿タイプのうち登録されているもののルールを登録し直してから、wp_loaded の後にルールを求める
     * (rewrite_rules が空なら作り直して保存する)。$wp_rewrite は呼び出しの後に元へ戻す。
     *
     * fixture の投稿タイプやコアのタクソノミーは、パーマリンクを設定していない状態で init に登録されており
     * (WordPress はそのとき rewrite の設定を解決しない)登録し直せないため、ここでは扱わない。
     *
     * @return array<string> 正規表現 => クエリ
     */
    private function nextRequestRewriteRules(string $postType): array
    {
        $current = self::rewrite();
        $next = new WP_Rewrite();
        $GLOBALS['wp_rewrite'] = $next;

        try {
            get_post_type_object($postType)?->add_rewrite_rules();
            $rules = $next->wp_rewrite_rules();
        } finally {
            $GLOBALS['wp_rewrite'] = $current;
        }

        self::assertIsArray($rules);

        return $rules;
    }

    private static function rewrite(): WP_Rewrite
    {
        $rewrite = $GLOBALS['wp_rewrite'];
        self::assertInstanceOf(WP_Rewrite::class, $rewrite);

        return $rewrite;
    }

    /**
     * 登録済みのフックのコールバックを、フック・priority・WordPress の ID で一意なキーにして返す。
     *
     * @return array<string, array{hook: string, function: mixed, acceptedArgs: int}>
     */
    private static function registeredCallbacks(): array
    {
        $filters = $GLOBALS['wp_filter'];
        self::assertIsArray($filters);
        $callbacks = [];

        foreach ($filters as $hook => $wpHook) {
            self::assertInstanceOf(WP_Hook::class, $wpHook);

            foreach ($wpHook->callbacks as $priority => $entries) {
                self::assertIsArray($entries);

                foreach ($entries as $id => $entry) {
                    // WordPress の ID は _wp_filter_build_unique_id() が作る文字列
                    self::assertIsString($id);
                    self::assertIsArray($entry);
                    $acceptedArgs = $entry['accepted_args'] ?? null;
                    self::assertIsInt($acceptedArgs);
                    $callbacks["{$hook}\n{$priority}\n{$id}"] = [
                        'hook' => (string) $hook,
                        'function' => $entry['function'] ?? null,
                        'acceptedArgs' => $acceptedArgs,
                    ];
                }
            }
        }

        return $callbacks;
    }

    /**
     * CPT を1つ持つ一時ディレクトリのテーマ・プラグインを、本番相当(debug=false)で boot し、投稿タイプ名を返す。
     * init の後の boot のため、CPT はキャッチアップでその場で登録される。
     */
    private function bootProductionKernelWithPostType(KernelConfig $config): string
    {
        $namespace = $config->appNamespace;
        $postType = 'tmswp_rw_' . substr(md5($namespace), 0, 8);
        $this->writePostTypeClass($config, $postType);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\RewritePost', AsPostType::class, []),
        ], []));

        $this->captureLogs(static fn(): Kernel => Kernel::boot($config));
        self::assertTrue(post_type_exists($postType));

        return $postType;
    }

    /** アーカイブを持つ投稿タイプのクラス(app/Post/RewritePost.php)を書く。 */
    private function writePostTypeClass(KernelConfig $config, string $postType): void
    {
        $namespace = $config->appNamespace;
        $this->writeFile($config->appDir . '/Post/RewritePost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
final class RewritePost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')
            ->label('Rewrite', 'Rewrites')
            ->hasArchive();
    }
}
PHP);
    }

    /** app/ のクラスを、名前空間に対応するパスから読み込む autoload を登録する(tear_down で外す)。 */
    private function registerAppAutoloader(KernelConfig $config): void
    {
        $namespace = $config->appNamespace;
        $this->autoloaders[] = $autoloader = static function (string $class) use ($namespace, $config): void {
            if (str_starts_with($class, $namespace . '\\')) {
                require $config->appDir . '/' . str_replace('\\', '/', substr($class, \strlen($namespace) + 1)) . '.php';
            }
        };
        spl_autoload_register($autoloader);
    }

    /**
     * 本番相当(debug=false)のテーマ形態の構成を、一時ディレクトリのテーマで作る。テーマ切替のフックに登録される
     * コールバックは、テストライブラリが tear_down でフックごと元に戻す。
     */
    private function productionThemeConfig(string $name): KernelConfig
    {
        $root = $this->createTempDirectory('integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));

        return KernelConfig::theme($root . '/app', 'TmswpIntegration' . $name . uniqid(), false, false);
    }

    private function pluginDiscoveryFile(): DiscoveryFile
    {
        return self::pluginKernel()->container()->get(DiscoveryFile::class);
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
