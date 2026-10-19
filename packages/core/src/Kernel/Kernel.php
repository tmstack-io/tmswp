<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use Psr\Container\ContainerInterface;
use Throwable;
use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Log\Log;
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
    /** @var array<string, self> */
    private static array $kernels = [];

    private static bool $cliRegistered = false;

    private static bool $textdomainRegistered = false;

    private readonly KernelConfig $config;

    private readonly Container $container;

    /** @var list<DiscoveredComponent> */
    private array $components = [];

    /** @var list<DiscoveredHookMethod> */
    private array $hookMethods = [];

    private function __construct(KernelConfig $config)
    {
        $this->config = $config;
        $this->container = new Container();

        $this->container->set(self::class, $this);
        $this->container->set(KernelConfig::class, $config);
        $this->container->set(Container::class, $this->container);
        $this->container->alias(ContainerInterface::class, Container::class);
        $this->container->set(DiscoveryFile::class, new DiscoveryFile($config));
        $this->container->set(LoggerRegistry::class, static fn(): LoggerRegistry => Log::registry());
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

    public static function boot(KernelConfig $config): self
    {
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

        $kernel->scheduleAll();
        $kernel->registerMetaSchemaHooks();
        $kernel->registerTemplateFallback();
        $kernel->registerLifecycleHooks();
        $kernel->registerModelMigrator();
        $kernel->registerActionRouter();
        self::registerTextdomainLoader();

        self::$kernels[$config->appNamespace] = $kernel;
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

    /** @return list<DiscoveredComponent> */
    public function components(): array
    {
        return $this->components;
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

    private function scheduleAll(): void
    {
        foreach ($this->hookMethods as $hookMethod) {
            add_filter(
                $hookMethod->hook,
                fn(mixed ...$args): mixed => $this->invokeHookMethod($hookMethod, $args),
                $hookMethod->priority,
                $hookMethod->acceptedArgs,
            );
        }

        /** @var array<string, array{hook: string, priority: int, items: list<array{DiscoveredComponent, ComponentAttributeInterface}>}> $groups */
        $groups = [];

        foreach ($this->components as $component) {
            try {
                $attribute = $component->attribute();
            } catch (DiscoveryException $exception) {
                if ($this->config->debug) {
                    throw $exception;
                }

                Log::error('tmswp.kernel: failed to restore component attribute', [
                    'class' => $component->class,
                    'exception' => $exception,
                ]);
                continue;
            }

            $hook = $attribute->hookName($this->config->context);
            $priority = $attribute->hookPriority();
            $key = $hook . "\n" . $priority;

            if (!\array_key_exists($key, $groups)) {
                $groups[$key] = ['hook' => $hook, 'priority' => $priority, 'items' => []];
            }

            $groups[$key]['items'][] = [$component, $attribute];
        }

        uasort(
            $groups,
            static fn(array $a, array $b): int => [$a['hook'], $a['priority']] <=> [$b['hook'], $b['priority']],
        );

        foreach ($groups as $group) {
            $runner = function () use ($group): void {
                foreach ($group['items'] as [$component, $attribute]) {
                    $this->runInitializer($component, $attribute);
                }
            };

            if (did_action($group['hook']) > 0) {
                $message = "Kernel::boot() was called after the '{$group['hook']}' hook had fired. Boot from the top level of functions.php or the plugin main file.";

                if ($this->config->debug) {
                    throw new KernelException($message);
                }

                Log::error($message);
                $runner();
                continue;
            }

            add_action($group['hook'], $runner, $group['priority'], 0);
        }
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function invokeHookMethod(DiscoveredHookMethod $hookMethod, array $args): mixed
    {
        $callback = [$this->container->get($hookMethod->class), $hookMethod->method];

        if (!\is_callable($callback)) {
            throw new KernelException("hook handler '{$hookMethod->class}::{$hookMethod->method}' is not callable");
        }

        return $callback(...$args);
    }

    private function runInitializer(DiscoveredComponent $component, ComponentAttributeInterface $attribute): void
    {
        $initializer = $this->container->get($attribute->initializerClass());

        if (!$initializer instanceof ComponentInitializerInterface) {
            $message = "initializer '{$attribute->initializerClass()}' must implement ComponentInitializerInterface";

            if ($this->config->debug) {
                throw new DiscoveryException($message);
            }

            Log::error($message, ['class' => $component->class]);

            return;
        }

        try {
            $initializer->initialize($component, $this);
        } catch (Throwable $exception) {
            if ($this->config->debug) {
                throw $exception;
            }

            Log::error('tmswp.kernel: failed to initialize {class}', [
                'class' => $component->class,
                'exception' => $exception,
            ]);
        }
    }

    private function registerLifecycleHooks(): void
    {
        add_action('init', function (): void {
            $option = $this->flushFlagOption();

            if (get_option($option) === false) {
                return;
            }

            flush_rewrite_rules();
            delete_option($option);
        }, 99, 0);

        if ($this->config->context === KernelContext::Plugin) {
            if ($this->config->pluginFile === null) {
                throw new KernelException('plugin context requires pluginFile');
            }

            register_activation_hook($this->config->pluginFile, function (): void {
                add_option($this->flushFlagOption(), '1', '', false);
                do_action('tmswp/activated', $this);
            });
            register_deactivation_hook($this->config->pluginFile, function (): void {
                flush_rewrite_rules();
                do_action('tmswp/deactivated', $this);
            });

            return;
        }

        add_action('after_switch_theme', function (): void {
            add_option($this->flushFlagOption(), '1', '', false);
            do_action('tmswp/activated', $this);
        }, 10, 0);
        add_action('switch_theme', function (): void {
            do_action('tmswp/deactivated', $this);
        }, 10, 0);
    }

    private function isPluginActivationSandbox(): bool
    {
        return $this->config->context === KernelContext::Plugin
            && \defined('WP_SANDBOX_SCRAPING')
            && WP_SANDBOX_SCRAPING === true;
    }

    private function registerMetaSchemaHooks(): void
    {
        $registry = $this->container->get(MetaSchemaRegistry::class);

        $runner = $registry->registerAll(...);

        if (did_action('init') > 0) {
            $message = "Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file.";

            if ($this->config->debug) {
                throw new KernelException($message);
            }

            Log::error($message);
            $runner();

            return;
        }

        add_action('init', $runner, 20, 0);
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

    private function flushFlagOption(): string
    {
        return 'tmswp_flush_rewrite_' . $this->config->instanceId();
    }
}
