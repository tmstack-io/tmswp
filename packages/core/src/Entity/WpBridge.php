<?php

declare(strict_types=1);

namespace TMSWP\Core\Entity;

use ReflectionClass;
use TMSWP\Core\Exception\LogicException;

/**
 * WPスタブの shaped array 型境界を、FW内部の動的呼び出し1箇所へ閉じ込める。
 *
 * @internal
 */
final class WpBridge
{
    private function __construct() {}

    public static function call(string $function, mixed ...$args): mixed
    {
        if (!\function_exists($function)) {
            throw new LogicException("WordPress function is not available: {$function}");
        }

        return $function(...$args);
    }

    /** @param class-string $class */
    public static function instantiate(string $class, mixed ...$args): object
    {
        return (new ReflectionClass($class))->newInstanceArgs($args);
    }
}
