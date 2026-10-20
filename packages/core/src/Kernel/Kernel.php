<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use Closure;
use Psr\Container\ContainerInterface;
use Throwable;
use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Log\LoggerRegistry;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\MigrateCommand;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Option\OptionRepositoryFactory;
use TMSWP\Core\View\ActionRouter;
use TMSWP\Core\View\Template;
use WP_CLI;

final class Kernel
{
    /**
     * この tmswp/core の版。boot 時に、読み込まれたすべての vendor の installed.php にある tmswp/core の版と照合する
     * (InstalledVersionCheck)。リリースのたびに、タグの版(先頭の v を除く)に合わせて更新する。
     */
    public const string VERSION = '0.2.0';

    /** 対応する WordPress の下限の版(D-09) */
    private const string MIN_WORDPRESS_VERSION = '7.0';

    /** @var array<string, self> */
    private static array $kernels = [];

    /**
     * boot が進行中の Kernel の名前空間(進行中でなければ null)。logChannels・拡張の register() の時点では外側の
     * Kernel はまだレジストリに無いため、そこから別の boot を呼ぶと、assertCanBoot() の同一・入れ子の名前空間や
     * appDir の検査をすり抜ける。それを防ぐため、キャッチアップの初期化からの boot も含め、boot の再入を禁止する印として持つ。
     */
    private static ?string $booting = null;

    private static bool $cliRegistered = false;

    private static bool $textdomainRegistered = false;

    private readonly KernelConfig $config;

    private readonly Container $container;

    private readonly LoggerRegistry $logger;

    /** @var list<DiscoveredComponent> */
    private array $components = [];

    /** @var list<DiscoveredHookMethod> */
    private array $hookMethods = [];

    /**
     * キャッチアップ(§3.3)でこれから実行するグループ((最初に発火した順, priority) の昇順)。キャッチアップ中でなければ
     * null。defer() が、発火済みのフックへの予約をこの中に入れる。
     *
     * @var list<array{hook: string, priority: int, runner: Closure(): void, deferred: bool}>|null
     */
    private ?array $catchUpQueue = null;

    private function __construct(KernelConfig $config)
    {
        $this->config = $config;
        $this->container = new Container();
        $this->logger = new LoggerRegistry(self::logChannels($config));

        $this->container->set(self::class, $this);
        $this->container->set(KernelConfig::class, $config);
        $this->container->set(Container::class, $this->container);
        $this->container->alias(ContainerInterface::class, Container::class);
        $this->container->set(DiscoveryFile::class, new DiscoveryFile($config));
        $this->container->set(LoggerRegistry::class, $this->logger);
        $this->container->set(Connection::class, static fn(): Connection => Connection::fromGlobal());
        $this->container->set(Migrator::class, static function (Container $container): Migrator {
            $connection = $container->get(Connection::class);

            return new Migrator($connection);
        });
        $this->container->set(MetaSchemaRegistry::class, static fn(Container $container): MetaSchemaRegistry => new MetaSchemaRegistry($container));
        $this->container->set(OptionRepositoryFactory::class, static fn(Container $container): OptionRepositoryFactory => new OptionRepositoryFactory($container));
        $this->container->set(Discovery::class, function (Container $container) use ($config): Discovery {
            $file = $container->get(DiscoveryFile::class);

            return new Discovery($config, $file);
        });
    }

    /**
     * Kernel を起動してレジストリに登録する(§3)。
     *
     * boot の途中(拡張の register()・logChannels・キャッチアップで動く初期化)から別の Kernel を boot することは
     * できない。テーマは functions.php、プラグインはプラグインのファイルで、それぞれ独立に boot する。
     *
     * @throws KernelException 別の Kernel の boot が進行中の場合や、WordPress の版・名前空間・appDir 等の検査に失敗した場合
     * @throws LogicException boot 時の初期化(メタスキーマの宣言の誤りによる InvalidSchemaException・
     *                        DuplicateMetaKeyException 等)や、本番の遅延 boot のキャッチアップの初期化が投げた設定の誤り
     */
    public static function boot(KernelConfig $config): self
    {
        if (self::$booting !== null) {
            throw new KernelException("cannot boot the TMSWP kernel for namespace '{$config->appNamespace}' while the kernel for namespace '" . self::$booting . "' is booting; boot each kernel from the top level of functions.php or the plugin main file");
        }

        self::$booting = $config->appNamespace;

        try {
            return self::bootKernel($config);
        } finally {
            // 成功・失敗・プラグインの有効化のサンドボックスでの早期 return のどれでも印を外す
            self::$booting = null;
        }
    }

    private static function bootKernel(KernelConfig $config): self
    {
        self::assertSupportedWordPress();
        // Container・LoggerRegistry 等の PSR の interface を実装するクラスは、この検査の後で初めて読み込む
        PsrInterfaceCheck::assertCompatible();
        InstalledVersionCheck::assertInstalledVersions(self::VERSION);
        self::assertCanBoot($config);

        $kernel = new self($config);
        $discovery = $kernel->container->get(Discovery::class);

        $result = $discovery->discover();
        $kernel->components = $result->components;
        $kernel->hookMethods = $result->hookMethods;

        if ($kernel->isPluginActivationSandbox()) {
            $kernel->registerLifecycleHooks();
            $kernel->registerModelMigrator();
            self::$kernels[$config->appNamespace] = $kernel;

            return $kernel;
        }

        try {
            // debug の遅延 boot の検査(何も登録しないうちに例外)と、コアのフックの登録
            $missed = $kernel->scheduleAll();
            $kernel->registerTemplateFallback();
            $kernel->registerLifecycleHooks();
            $kernel->registerModelMigrator();
            $kernel->registerActionRouter();
            self::registerTextdomainLoader();
            // 拡張が Container に入れるサービスを、キャッチアップの初期化でも使えるよう、キャッチアップより先に登録する
            $kernel->registerExtensions();
            // キャッチアップで動くアプリのコードが Kernel::forClass()・forFile()(Log の静的な呼び出し)で
            // この Kernel を引けるよう、キャッチアップより先にレジストリに登録する
            self::$kernels[$config->appNamespace] = $kernel;
            // メタスキーマの登録等の boot 時の初期化。拡張が Container に入れたサービス(メタのストレージ等)を使え、
            // キャッチアップで動く初期化やアプリのコードからも登録済みのスキーマを使えるよう、この位置で行う
            $kernel->initializeAtBoot();
            $kernel->catchUp($missed);
        } catch (Throwable $exception) {
            // boot が失敗したら、起動していない Kernel をレジストリに残さないよう取り除いてから、例外をそのまま投げ直す
            unset(self::$kernels[$config->appNamespace]);

            throw $exception;
        }

        self::registerCliCommand();

        return $kernel;
    }

    public static function get(string $appNamespace): self
    {
        $namespace = trim($appNamespace, '\\');

        if (!\array_key_exists($namespace, self::$kernels)) {
            throw new KernelException("no TMSWP kernel booted for namespace '{$namespace}'");
        }

        return self::$kernels[$namespace];
    }

    public static function forClass(string $class): self
    {
        $matchedNamespace = null;
        $matchedKernel = null;

        foreach (self::$kernels as $namespace => $kernel) {
            if (str_starts_with($class, $namespace . '\\') && ($matchedNamespace === null || \strlen($namespace) > \strlen($matchedNamespace))) {
                $matchedNamespace = $namespace;
                $matchedKernel = $kernel;
            }
        }

        if (!$matchedKernel instanceof self) {
            throw new KernelException("class '{$class}' does not belong to any booted TMSWP application");
        }

        return $matchedKernel;
    }

    /**
     * ファイルの実パス(realpath())を含むルートディレクトリ(rootDirs()。実パス)を持つ Kernel を、最も長く一致する
     * ルートで解決する。Log の静的な呼び出しが、記録先の Kernel を決めるのに使う。
     *
     * @throws KernelException ファイルの実パスが取れない(存在しない等)、またはどの Kernel のルートにも含まれない場合
     */
    public static function forFile(string $file): self
    {
        // ルートは実パスのため、ファイルも実パスにしてから比べる(シンボリックリンクや `..` を含む別の表記でも、
        // 実体の場所で判定する)
        $realFile = realpath($file);

        if ($realFile === false) {
            throw new KernelException("cannot resolve the real path of '{$file}' to find its TMSWP kernel");
        }

        $matchedRoot = null;
        $matchedKernel = null;

        foreach (self::$kernels as $kernel) {
            foreach ($kernel->rootDirs() as $root) {
                if (str_starts_with($realFile, $root . DIRECTORY_SEPARATOR) && ($matchedRoot === null || \strlen($root) > \strlen($matchedRoot))) {
                    $matchedRoot = $root;
                    $matchedKernel = $kernel;
                }
            }
        }

        if (!$matchedKernel instanceof self) {
            $real = $realFile === $file ? '' : " (real path '{$realFile}')";

            throw new KernelException("file '{$file}'{$real} is not inside the root directory of any booted TMSWP kernel");
        }

        return $matchedKernel;
    }

    /** @return array<string, self> */
    public static function all(): array
    {
        return self::$kernels;
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$kernels = [];
        self::$cliRegistered = false;
        self::$textdomainRegistered = false;
        MigrateCommand::reset();
    }

    public function config(): KernelConfig
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->container;
    }

    /**
     * この Kernel のログのレジストリ。チャネルは KernelConfig の logChannels で宣言する。
     */
    public function logger(): LoggerRegistry
    {
        return $this->logger;
    }

    /** @return list<DiscoveredComponent> */
    public function components(): array
    {
        return $this->components;
    }

    /**
     * コールバックを、フック $hook の priority $priority の時点で引数なしで実行するよう予約する(§3.3)。
     *
     * 通常は add_action() で登録する。本番の遅延 boot のキャッチアップの最中に、発火済みのフックへ予約した場合は、
     * add_action() では二度と実行されないため、キャッチアップの残りのグループの中の (最初に発火した順, priority) の
     * 位置(同じ位置のグループの後)に入れて実行する。コンポーネントの初期化が、同じ Kernel の後の priority の初期化を
     * 待って行う処理(builtin のタクソノミーの紐付けの再試行。03-entities §9.2)に使う。
     *
     * @internal
     *
     * @param Closure(): void $callback
     */
    public function defer(string $hook, int $priority, Closure $callback): void
    {
        if ($this->catchUpQueue === null) {
            add_action($hook, $callback, $priority, 0);

            return;
        }

        $firedOrder = self::firedHookOrder();

        if (!\array_key_exists($hook, $firedOrder)) {
            add_action($hook, $callback, $priority, 0);

            return;
        }

        $position = \count($this->catchUpQueue);

        foreach ($this->catchUpQueue as $index => $queued) {
            $byFiredOrder = $firedOrder[$queued['hook']] <=> $firedOrder[$hook];

            if (($byFiredOrder !== 0 ? $byFiredOrder : $queued['priority'] <=> $priority) > 0) {
                $position = $index;
                break;
            }
        }

        array_splice($this->catchUpQueue, $position, 0, [['hook' => $hook, 'priority' => $priority, 'runner' => $callback, 'deferred' => true]]);
    }

    /**
     * WordPress の版が下限未満なら起動を拒否する。設定の誤りなので、debug に関係なく常に例外にする。
     *
     * 版は WordPress が wp-includes/version.php で公開する `$wp_version` から取る。WordPress 自身の
     * 「Requires at least」の判定(is_wp_version_compatible())と同じく、`-` 以降の接尾辞(`-RC1`・`-beta2`・
     * `-alpha-12345-src` 等)を除いて比べるため、7.0 のリリース候補・開発版は 7.0 として扱う。
     *
     * @throws KernelException 版が取れない、または下限未満の場合
     */
    private static function assertSupportedWordPress(): void
    {
        $version = $GLOBALS['wp_version'] ?? null;

        if (!\is_string($version) || $version === '') {
            throw new KernelException('cannot determine the WordPress version: global $wp_version is not set');
        }

        $release = explode('-', $version, 2)[0];

        if (version_compare($release, self::MIN_WORDPRESS_VERSION, '<')) {
            throw new KernelException('TMSWP requires WordPress ' . self::MIN_WORDPRESS_VERSION . " or later (running {$version})");
        }
    }

    private static function assertCanBoot(KernelConfig $config): void
    {
        $namespace = $config->appNamespace;

        if (\array_key_exists($namespace, self::$kernels)) {
            throw new KernelException("TMSWP kernel for namespace '{$namespace}' is already booted");
        }

        foreach (self::$kernels as $bootedNamespace => $kernel) {
            if (
                str_starts_with($namespace . '\\', $bootedNamespace . '\\')
                || str_starts_with($bootedNamespace . '\\', $namespace . '\\')
            ) {
                throw new KernelException("nested app namespaces are forbidden: {$namespace}, {$bootedNamespace}");
            }

            $bootedDir = $kernel->config->appDir;

            if (
                $config->appDir === $bootedDir
                || str_starts_with($config->appDir . DIRECTORY_SEPARATOR, $bootedDir . DIRECTORY_SEPARATOR)
                || str_starts_with($bootedDir . DIRECTORY_SEPARATOR, $config->appDir . DIRECTORY_SEPARATOR)
            ) {
                throw new KernelException("shared or nested app directories are forbidden: {$config->appDir}, {$bootedDir}");
            }

            // 探索結果ファイルは appDir の親ディレクトリに置くため、同じ親を持つ appDir どうしは同じファイルを上書きし合う
            if ($config->discoveryFile() === $kernel->config->discoveryFile()) {
                throw new KernelException("app directories {$config->appDir} and {$bootedDir} share the discovery file {$config->discoveryFile()}; place each app directory in its own theme or plugin root");
            }
        }
    }

    /**
     * コンポーネントの初期化・MetaSchema の WordPress への登録・`#[Action]` / `#[Filter]` のメソッドを、WordPress のフックに
     * 登録する(§7)。
     *
     * 遅延 boot の検査(発火済みのフックがあるか)は、どのフックを登録するよりも先に行う。debug では何も登録・
     * 実行しないまま例外を投げる。本番では発火済みのフックのグループを返し、boot() が拡張とレジストリへの登録の後で
     * catchUp() に渡す(キャッチアップ。§3.3)。
     *
     * @throws KernelException debug の遅延 boot の場合
     *
     * @return list<array{hook: string, priority: int, runner: Closure(): void}> 発火済みのグループ(キャッチアップの順)
     */
    private function scheduleAll(): array
    {
        $groups = $this->componentGroups();
        // MetaSchema の WordPress への登録(init 20。register_*_meta)も、コンポーネントの初期化と同じ順序の規則で扱う。
        // スキーマ自体は boot の中で集める(initializeAtBoot())。WordPress へは、acf-bridge が外部管理のキーを申告する
        // init 6 の後、かつ revisions_enabled の検査(post_type_supports())が効くよう投稿タイプの登録(init 10)の後に流す
        $groups[] = [
            'hook' => 'init',
            'priority' => 20,
            'runner' => $this->container->get(MetaSchemaRegistry::class)->registerAll(...),
        ];

        $firedOrder = self::firedHookOrder();
        $pending = [];
        $missed = [];

        foreach ($groups as $group) {
            if (\array_key_exists($group['hook'], $firedOrder)) {
                $missed[] = $group;
            } else {
                $pending[] = $group;
            }
        }

        // 発火済みのグループは、WordPress でフックが最初に発火した順に、同じフックの中では priority の昇順に並べる
        usort($missed, static function (array $a, array $b) use ($firedOrder): int {
            $byFiredOrder = $firedOrder[$a['hook']] <=> $firedOrder[$b['hook']];

            return $byFiredOrder !== 0 ? $byFiredOrder : $a['priority'] <=> $b['priority'];
        });

        if ($missed !== [] && $this->config->debug) {
            throw new KernelException(self::lateBootMessage($missed[0]['hook']));
        }

        foreach ($this->hookMethods as $hookMethod) {
            add_filter(
                $hookMethod->hook,
                fn(mixed ...$args): mixed => $this->invokeHookMethod($hookMethod, $args),
                $hookMethod->priority,
                $hookMethod->kind->registeredArgs($hookMethod->acceptedArgs),
            );
        }

        // 未発火のフックの実行順は WordPress が priority で決めるため、登録の順序に意味は無い
        foreach ($pending as $group) {
            add_action($group['hook'], $group['runner'], $group['priority'], 0);
        }

        return $missed;
    }

    /**
     * BootInitializerInterface を実装する Initializer の boot 時の初期化(エンティティのメタスキーマの登録)を、
     * components の順(FQCN 昇順)に実行する(§7)。それ以外の Initializer は、ここでは Container から取り出さない。
     *
     * 失敗は設定の誤り(00-overview.md §5.6)なので、debug に関係なく例外をそのまま投げる。
     */
    private function initializeAtBoot(): void
    {
        foreach ($this->components as $component) {
            $initializerClass = $component->attribute()->initializerClass();

            if (is_a($initializerClass, BootInitializerInterface::class, true)) {
                $this->container->get($initializerClass)->initializeAtBoot($component, $this);
            }
        }
    }

    /**
     * 本番の遅延 boot で、発火済みのフックの処理をその場で実行して縮退動作させる(キャッチアップ。§3.3)。
     *
     * @param list<array{hook: string, priority: int, runner: Closure(): void}> $missed scheduleAll() が返したグループ
     */
    private function catchUp(array $missed): void
    {
        $queue = [];

        foreach ($missed as $group) {
            $queue[] = ['hook' => $group['hook'], 'priority' => $group['priority'], 'runner' => $group['runner'], 'deferred' => false];
        }

        $this->catchUpQueue = $queue;

        try {
            // 実行中のグループが defer() で予約したものも、この列に入って順に実行される(列は finally まで null にならない)
            while ($this->catchUpQueue !== null && $this->catchUpQueue !== []) {
                $group = array_shift($this->catchUpQueue);

                // 遅延 boot の記録は、発火済みのグループごとに1回。defer() で予約したものは記録しない
                if (!$group['deferred']) {
                    $this->logger->error(self::lateBootMessage($group['hook']));
                }

                ($group['runner'])();
            }
        } finally {
            $this->catchUpQueue = null;
        }
    }

    /**
     * コンポーネントを (フック, priority) ごとのグループにまとめる。グループの実行は、FQCN 昇順に並んだ
     * コンポーネントを1つのクロージャで順に初期化する。
     *
     * @throws DiscoveryException 探索結果ファイルの属性を復元できない場合(設定の誤り。debug に関係なく投げる)
     *
     * @return list<array{hook: string, priority: int, runner: Closure(): void}>
     */
    private function componentGroups(): array
    {
        /** @var array<string, array{hook: string, priority: int, items: list<array{DiscoveredComponent, ComponentAttributeInterface}>}> $groups */
        $groups = [];

        foreach ($this->components as $component) {
            $attribute = $component->attribute();
            $hook = $attribute->hookName($this->config->context);
            $priority = $attribute->hookPriority();
            $key = $hook . "\n" . $priority;

            if (!\array_key_exists($key, $groups)) {
                $groups[$key] = ['hook' => $hook, 'priority' => $priority, 'items' => []];
            }

            $groups[$key]['items'][] = [$component, $attribute];
        }

        return array_values(array_map(
            fn(array $group): array => [
                'hook' => $group['hook'],
                'priority' => $group['priority'],
                'runner' => function () use ($group): void {
                    foreach ($group['items'] as [$component, $attribute]) {
                        $this->runInitializer($component, $attribute);
                    }
                },
            ],
            $groups,
        ));
    }

    private static function lateBootMessage(string $hook): string
    {
        return "Kernel::boot() was called after the '{$hook}' hook had fired. Boot from the top level of functions.php or the plugin main file.";
    }

    /**
     * 発火済みのフックと、それが最初に発火した順位を返す。
     *
     * WordPress の do_action() / do_action_ref_array() は、フックが初めて発火したときに `$wp_actions` へキーを足し、
     * 2回目以降は値を増やすだけなので、キーの並びが最初に発火した順になる(PHP の配列は挿入順を保つ)。
     *
     * @throws KernelException `$wp_actions` が無い場合
     *
     * @return array<string, int> フック名 => 最初に発火した順位(0 始まり)
     */
    private static function firedHookOrder(): array
    {
        $actions = $GLOBALS['wp_actions'] ?? null;

        if (!\is_array($actions)) {
            throw new KernelException('cannot determine fired hooks: global $wp_actions is not set');
        }

        $order = [];

        foreach (array_keys($actions) as $position => $hook) {
            $order[(string) $hook] = $position;
        }

        return $order;
    }

    /**
     * `#[Action]` / `#[Filter]` のメソッドを呼ぶ。
     *
     * WordPress の WP_Hook と同じく call_user_func_array() で呼び、strict_types に関係なく WordPress 本来の弱い
     * 型変換(数値文字列から int への変換等)を効かせる。名前空間を付けずに呼ぶこと: `\call_user_func_array()` と
     * 書くと PHP がコンパイル時に直接の呼び出しへ置き換え、このファイルの strict_types が効いて TypeError になる
     * (php-cs-fixer の native_function_invocation からも除外している)。
     *
     * Filter は引数を宣言しないメソッドでも第1引数を受け取って登録する(HookMethodKind::registeredArgs())ため、
     * メソッドには宣言した引数の数に切り詰めて渡す。
     *
     * 失敗はエラー方針(00-overview.md §5.6)で隔離する。debug では例外をそのまま投げ、本番ではこの Kernel のログに
     * error で記録してこのメソッドだけをスキップし、第1引数をそのまま返す(filter の値を変えない。action の戻り値は使われない)。
     *
     * @param array<int|string, mixed> $args WordPress から受け取った引数
     */
    private function invokeHookMethod(DiscoveredHookMethod $hookMethod, array $args): mixed
    {
        try {
            $callback = [$this->container->get($hookMethod->class), $hookMethod->method];

            if (!\is_callable($callback)) {
                throw new KernelException("hook handler '{$hookMethod->class}::{$hookMethod->method}' is not callable");
            }

            return call_user_func_array($callback, \array_slice($args, 0, $hookMethod->acceptedArgs));
        } catch (Throwable $exception) {
            if ($this->config->debug) {
                throw $exception;
            }

            $this->logger->error('tmswp.kernel: hook method {class}::{method} failed on {hook}', [
                'class' => $hookMethod->class,
                'method' => $hookMethod->method,
                'hook' => $hookMethod->hook,
                'exception' => $exception,
            ]);

            return $args[0] ?? null;
        }
    }

    /**
     * コンポーネント1件を初期化する。初期化は投稿タイプ・タクソノミー・オプション等の登録そのもので、
     * そこで起きる失敗は設定の誤り(00-overview.md §5.6)なので、debug に関係なく例外をそのまま投げる。
     *
     * @throws DiscoveryException initializer が ComponentInitializerInterface を実装していない場合
     */
    private function runInitializer(DiscoveredComponent $component, ComponentAttributeInterface $attribute): void
    {
        $initializer = $this->container->get($attribute->initializerClass());

        if (!$initializer instanceof ComponentInitializerInterface) {
            throw new DiscoveryException("initializer '{$attribute->initializerClass()}' must implement ComponentInitializerInterface (component {$component->class})");
        }

        $initializer->initialize($component, $this);
    }

    /**
     * 有効化・無効化のフックを登録する(§3.4)。
     *
     * どちらも `rewrite_rules` のオプションを消すだけで、その場では作り直さない。WordPress は `rewrite_rules` が
     * 空なら次にルールが必要になったときに作り直し、`wp_loaded` より前なら保存を `wp_loaded` まで遅らせる
     * (WP_Rewrite::refresh_rewrite_rules())。そのため有効化の次のリクエストでは CPT 等の登録後のルールになり、
     * 無効化の次のリクエストでは無効にしたテーマ・プラグインのルールが残らない。毎リクエストの確認は行わない。
     */
    private function registerLifecycleHooks(): void
    {
        $activate = function (): void {
            delete_option('rewrite_rules');
            do_action('tmswp/activated', $this);
        };
        $deactivate = function (): void {
            delete_option('rewrite_rules');
            do_action('tmswp/deactivated', $this);
        };

        if ($this->config->context === KernelContext::Plugin) {
            if ($this->config->pluginFile === null) {
                throw new KernelException('plugin context requires pluginFile');
            }

            register_activation_hook($this->config->pluginFile, $activate);
            register_deactivation_hook($this->config->pluginFile, $deactivate);

            return;
        }

        add_action('after_switch_theme', $activate, 10, 0);
        add_action('switch_theme', $deactivate, 10, 0);
    }

    /**
     * forFile() が判定に使うルートディレクトリ。KernelConfig::rootDir() に加え、テーマ形態では、有効な子テーマの
     * ディレクトリ(get_stylesheet_directory() の実パス)が親テーマ(get_template_directory() の実パス)と異なる場合に
     * それも含める。Template::part() は子テーマのテンプレートを探すため、子テーマのファイルからの Log の呼び出しを
     * テーマの Kernel に記録できるようにする。
     *
     * 子テーマのディレクトリは判定のたびに取る。テーマ形態の Kernel は functions.php で boot するため、レジストリに
     * テーマの Kernel がある時点では WordPress は有効なテーマを決めている(wp-settings.php は setup_theme の後に
     * 有効なテーマの functions.php を読み込む)。
     *
     * @throws KernelException テーマのディレクトリの実パスが取れない場合
     *
     * @return list<string>
     */
    private function rootDirs(): array
    {
        $roots = [$this->config->rootDir()];

        if ($this->config->context !== KernelContext::Theme) {
            return $roots;
        }

        $stylesheet = self::realThemeDirectory(get_stylesheet_directory());

        if ($stylesheet !== self::realThemeDirectory(get_template_directory())) {
            $roots[] = $stylesheet;
        }

        return $roots;
    }

    /**
     * @throws KernelException ディレクトリが存在しない場合
     */
    private static function realThemeDirectory(string $directory): string
    {
        $real = realpath($directory);

        if ($real === false) {
            throw new KernelException("cannot resolve the theme directory: {$directory}");
        }

        return rtrim($real, '/\\');
    }

    /**
     * KernelConfig の extensions を、渡した順に1回ずつ登録する。プラグインの有効化のサンドボックスでは、コンポーネントの
     * 初期化と同じく呼ばない。
     */
    private function registerExtensions(): void
    {
        foreach ($this->config->extensions as $extension) {
            $extension->register($this);
        }
    }

    /**
     * KernelConfig の logChannels を呼び、チャネルの宣言を検証する。null なら空(遅延デフォルトチャネル)を返す。
     *
     * @throws KernelException 戻り値がチャネル名 => LogChannel の空でない配列でない場合(設定の誤り)
     *
     * @return array<string, LogChannel>
     */
    private static function logChannels(KernelConfig $config): array
    {
        if ($config->logChannels === null) {
            return [];
        }

        $channels = ($config->logChannels)();

        if (!\is_array($channels) || $channels === []) {
            throw new KernelException('logChannels must return a non-empty array of channel name => ' . LogChannel::class . '; omit logChannels to use the default channel');
        }

        $validated = [];

        foreach ($channels as $name => $channel) {
            if (!\is_string($name) || !$channel instanceof LogChannel) {
                throw new KernelException('logChannels must return channel name (string) => ' . LogChannel::class . ', got ' . get_debug_type($name) . ' => ' . get_debug_type($channel));
            }

            $validated[$name] = $channel;
        }

        return $validated;
    }

    private function isPluginActivationSandbox(): bool
    {
        return $this->config->context === KernelContext::Plugin
            && \defined('WP_SANDBOX_SCRAPING')
            && WP_SANDBOX_SCRAPING === true;
    }

    private function registerActionRouter(): void
    {
        if ($this->config->context !== KernelContext::Theme || !$this->config->actions) {
            return;
        }

        $router = new ActionRouter();
        add_filter('template_include', [$router, 'dispatch'], ActionRouter::PRIORITY, 1);
    }

    private function registerTemplateFallback(): void
    {
        if ($this->config->context !== KernelContext::Plugin || $this->config->pluginFile === null) {
            return;
        }

        $templatesDir = \dirname($this->config->pluginFile) . DIRECTORY_SEPARATOR . 'templates';

        if (is_dir($templatesDir)) {
            Template::locator()->addFallbackDir($templatesDir);
        }
    }

    private function registerModelMigrator(): void
    {
        $migrator = $this->container->get(Migrator::class);

        $migrator->listen($this);
    }

    private static function registerCliCommand(): void
    {
        if (self::$cliRegistered || !\defined('WP_CLI') || WP_CLI !== true || !class_exists('WP_CLI')) {
            return;
        }

        WP_CLI::add_command('tmswp discover', new DiscoverCommand());

        self::$cliRegistered = true;
    }

    private static function registerTextdomainLoader(): void
    {
        if (self::$textdomainRegistered) {
            return;
        }

        add_action('init', static function (): void {
            $locale = determine_locale();
            load_textdomain('tmswp', \dirname(__DIR__, 2) . "/languages/tmswp-{$locale}.mo");
        }, 1, 0);

        self::$textdomainRegistered = true;
    }
}
