<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

final class Log
{
    private static ?LoggerRegistry $registry = null;

    private function __construct() {}

    public static function registry(): LoggerRegistry
    {
        if (!self::$registry instanceof LoggerRegistry) {
            self::$registry = new LoggerRegistry();
        }

        return self::$registry;
    }

    public static function setRegistry(?LoggerRegistry $registry): void
    {
        self::$registry = $registry;
    }

    /**
     * @param list<string> $levels
     * @param list<string> $scopes
     */
    public static function set(string $channel, LoggerInterface $logger, array $levels = [], array $scopes = []): void
    {
        self::registry()->set($channel, $logger, $levels, $scopes);
    }

    public static function channel(string $channel): LoggerInterface
    {
        return self::registry()->channel($channel);
    }

    public static function scope(string $scope): LoggerInterface
    {
        return self::registry()->scope($scope);
    }

    /** @param array<mixed> $context */
    public static function debug(string|Stringable $message, array $context = []): void
    {
        self::log(LogLevel::DEBUG, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function info(string|Stringable $message, array $context = []): void
    {
        self::log(LogLevel::INFO, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function notice(string|Stringable $message, array $context = []): void
    {
        self::log(LogLevel::NOTICE, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function warning(string|Stringable $message, array $context = []): void
    {
        self::log(LogLevel::WARNING, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function error(string|Stringable $message, array $context = []): void
    {
        self::log(LogLevel::ERROR, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function critical(string|Stringable $message, array $context = []): void
    {
        self::log(LogLevel::CRITICAL, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function alert(string|Stringable $message, array $context = []): void
    {
        self::log(LogLevel::ALERT, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function emergency(string|Stringable $message, array $context = []): void
    {
        self::log(LogLevel::EMERGENCY, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function log(string $level, string|Stringable $message, array $context = []): void
    {
        self::registry()->log($level, $message, $context);
    }
}
