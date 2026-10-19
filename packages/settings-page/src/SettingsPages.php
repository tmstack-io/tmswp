<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage;

use TMSWP\Core\Exception\LogicException;

final class SettingsPages
{
    private static bool $registered = false;

    /** @var array<string, class-string<SettingsPage>> */
    private static array $pagesBySlug = [];

    private function __construct() {}

    /**
     * @param list<class-string<SettingsPage>> $pages
     */
    public static function register(array $pages = []): void
    {
        if (self::$registered) {
            throw new LogicException('SettingsPages::register() was already called');
        }

        self::$registered = true;

        foreach ($pages as $pageClass) {
            self::registerPage($pageClass);
            add_action('admin_menu', static function () use ($pageClass): void {
                (new PageRegistrar($pageClass))->registerMenu();
            }, 10, 0);
        }
    }

    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    /**
     * @param class-string $pageClass
     */
    public static function registerPage(string $pageClass): void
    {
        if (!is_subclass_of($pageClass, SettingsPage::class)) {
            throw new LogicException("settings page must extend SettingsPage: {$pageClass}");
        }

        $definition = $pageClass::definition();
        $registeredClass = self::$pagesBySlug[$definition->slug] ?? null;

        if ($registeredClass !== null) {
            throw new LogicException("duplicate settings page slug '{$definition->slug}': {$registeredClass}, {$pageClass}");
        }

        self::$pagesBySlug[$definition->slug] = $pageClass;
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$registered = false;
        self::$pagesBySlug = [];
    }
}
