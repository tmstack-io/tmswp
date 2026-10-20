<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

use TMSWP\AcfBridge\Cli\AcfCommand;
use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\AcfBridge\Manifest\ManagedKey;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use WP_CLI;

final class AcfBridge
{
    private static ?BridgeConfig $config = null;

    private function __construct() {}

    public static function boot(?BridgeConfig $config = null): void
    {
        if (self::$config instanceof BridgeConfig) {
            throw new AcfBridgeRuntimeException('AcfBridge::boot() was already called');
        }

        self::$config = $config ?? BridgeConfig::fromKernel();

        if (self::$config->registerLoadJsonDirs) {
            add_filter('acf/settings/load_json', static function (array $paths): array {
                foreach (self::config()->jsonDirs as $dir) {
                    if (is_dir($dir) && !\in_array($dir, $paths, true)) {
                        $paths[] = $dir;
                    }
                }

                return $paths;
            }, 10, 1);
        }

        add_action('init', [self::class, 'registerManagedKeys'], 6, 0);

        if (\defined('WP_CLI') && WP_CLI === true && class_exists('WP_CLI')) {
            WP_CLI::add_command('tmswp acf', new AcfCommand());
        }

        if (self::$config->previewMeta) {
            PreviewAcfSupport::register();
        }
    }

    public static function config(): BridgeConfig
    {
        if (!self::$config instanceof BridgeConfig) {
            throw new AcfBridgeRuntimeException('AcfBridge is not booted. Call AcfBridge::boot() in functions.php / plugin bootstrap.');
        }

        return self::$config;
    }

    /**
     * @internal
     */
    public static function registerManagedKeys(): void
    {
        if (!\function_exists('acf')) {
            return;
        }

        $manifestClass = self::config()->namespace . '\\AcfManifest';

        if (!class_exists($manifestClass) || !method_exists($manifestClass, 'managedKeys')) {
            return;
        }

        $kernel = self::kernelForConfig();
        $registry = $kernel->container()->get(MetaSchemaRegistry::class);

        $managedKeys = $manifestClass::managedKeys();

        if (!is_iterable($managedKeys)) {
            throw new LogicException("{$manifestClass}::managedKeys() must return an iterable list");
        }

        foreach ($managedKeys as $key) {
            if (!$key instanceof ManagedKey || $key->objectType === null) {
                continue;
            }

            // ACF の location の値 'all' は、その ObjectType のすべてのサブタイプを表す
            if ($key->objectSubtype === 'all') {
                $registry->markExternallyManagedInAllSubtypes($key->objectType, $key->metaKey);
                continue;
            }

            $registry->markExternallyManaged($key->objectType, $key->objectSubtype, $key->metaKey);
        }
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$config = null;
    }

    private static function kernelForConfig(): Kernel
    {
        $configNamespace = self::config()->namespace;

        foreach (Kernel::all() as $appNamespace => $kernel) {
            if ($configNamespace === $appNamespace || str_starts_with($configNamespace, $appNamespace . '\\')) {
                return $kernel;
            }
        }

        throw new AcfBridgeRuntimeException("no TMSWP kernel found for ACF namespace '{$configNamespace}'");
    }
}
