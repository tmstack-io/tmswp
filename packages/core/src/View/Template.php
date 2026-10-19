<?php

declare(strict_types=1);

namespace TMSWP\Core\View;

use LogicException;
use Throwable;
use TMSWP\Core\Exception\TemplateNotFoundException;

final class Template
{
    private static ?TemplateLocator $locator = null;

    /** @var list<string> */
    private static array $templateStack = [];

    private function __construct() {}

    /** @param array<string, mixed> $args */
    public static function part(string $slug, array $args = []): void
    {
        $locator = self::locator();
        $file = $locator->locate($slug);

        if ($file === null) {
            throw new TemplateNotFoundException(
                "template '{$slug}' was not found. Candidates: " . implode(', ', $locator->candidatePaths($slug)),
            );
        }

        self::includeFile($file, $args);
    }

    /** @param array<string, mixed> $args */
    public static function render(string $slug, array $args = []): string
    {
        ob_start();

        try {
            self::part($slug, $args);

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }
    }

    public static function exists(string $slug): bool
    {
        return self::locator()->exists($slug);
    }

    public static function locate(string $slug): ?string
    {
        return self::locator()->locate($slug);
    }

    public static function locator(): TemplateLocator
    {
        if (!self::$locator instanceof TemplateLocator) {
            self::$locator = new TemplateLocator();
        }

        return self::$locator;
    }

    public static function setLocator(?TemplateLocator $locator): void
    {
        self::$locator = $locator;
    }

    /** @param array<string, mixed> $args */
    private static function includeFile(string $file, array $args): void
    {
        self::$templateStack[] = $file;

        try {
            (static function (array $args): void {
                include self::currentTemplateFile();
            })($args);
        } finally {
            array_pop(self::$templateStack);
        }
    }

    private static function currentTemplateFile(): string
    {
        $key = array_key_last(self::$templateStack);

        if ($key === null) {
            throw new LogicException('no current template file is being included');
        }

        return self::$templateStack[$key];
    }
}
