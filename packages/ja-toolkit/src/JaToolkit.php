<?php

declare(strict_types=1);

namespace TMSWP\JaToolkit;

use ReflectionClass;
use TMSWP\Core\Exception\LogicException;

final class JaToolkit
{
    /** @var array<class-string<ModuleInterface>, ModuleInterface> */
    private static array $modules = [];

    private static bool $textdomainRegistered = false;

    private function __construct() {}

    /**
     * @param list<class-string<ModuleInterface>|ModuleInterface> $modules
     */
    public static function enable(array $modules): void
    {
        if (\function_exists('did_action') && did_action('init') > 0) {
            throw new LogicException('JaToolkit::enable() must be called before the init hook');
        }

        self::registerTextdomainLoader();

        foreach ($modules as $module) {
            $instance = self::resolveModule($module);
            $class = $instance::class;

            if (\array_key_exists($class, self::$modules)) {
                throw new LogicException("ja-toolkit module is already enabled: {$class}");
            }

            self::$modules[$class] = $instance;
            $instance->register();
        }
    }

    /**
     * @param class-string<ModuleInterface> $moduleClass
     */
    public static function isEnabled(string $moduleClass): bool
    {
        return \array_key_exists($moduleClass, self::$modules);
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$modules = [];
        self::$textdomainRegistered = false;
    }

    /**
     * @param class-string<ModuleInterface>|ModuleInterface $module
     */
    private static function resolveModule(string|ModuleInterface $module): ModuleInterface
    {
        if ($module instanceof ModuleInterface) {
            return $module;
        }

        if (!is_subclass_of($module, ModuleInterface::class)) {
            throw new LogicException("ja-toolkit module must implement ModuleInterface: {$module}");
        }

        $reflection = new ReflectionClass($module);
        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new LogicException("ja-toolkit module '{$module}' requires constructor arguments; pass an instance instead");
        }

        return new $module();
    }

    private static function registerTextdomainLoader(): void
    {
        if (self::$textdomainRegistered) {
            return;
        }

        add_action('init', static function (): void {
            $locale = determine_locale();
            load_textdomain('tmswp', \dirname(__DIR__) . "/languages/tmswp-{$locale}.mo");
        }, 1, 0);

        self::$textdomainRegistered = true;
    }
}
