<?php

declare(strict_types=1);

namespace TMSWP\Core\View;

final class View
{
    /** @var array<string, mixed> */
    private static array $vars = [];

    private function __construct() {}

    /** @param array<string, mixed> $vars */
    public static function set(array $vars): void
    {
        self::$vars = array_merge(self::$vars, $vars);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!\array_key_exists($key, self::$vars)) {
            if ($default === null && \defined('WP_DEBUG') && WP_DEBUG === true && \function_exists('wp_trigger_error')) {
                wp_trigger_error(__METHOD__, "未定義の View 変数 '{$key}' を参照しました。actions 側の return キーを確認してください。", E_USER_WARNING);
            }

            return $default;
        }

        return self::$vars[$key];
    }

    public static function has(string $key): bool
    {
        return \array_key_exists($key, self::$vars);
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$vars = [];
    }
}
