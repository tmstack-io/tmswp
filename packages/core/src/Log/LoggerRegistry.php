<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\UnknownLogChannelException;

final class LoggerRegistry implements LoggerInterface
{
    use LoggerTrait;

    /** @var array<string, LogChannel> */
    private array $channels = [];

    private bool $configured = false;

    /**
     * @param list<string> $levels
     * @param list<string> $scopes
     */
    public function set(string $channel, LoggerInterface $logger, array $levels = [], array $scopes = []): void
    {
        if ($channel === '') {
            throw new InvalidArgumentException('log channel must not be empty');
        }

        foreach ($levels as $level) {
            $this->assertKnownLevel($level);
        }

        foreach ($scopes as $scope) {
            if ($scope === '') {
                throw new InvalidArgumentException('log scope must not be empty');
            }
        }

        $this->channels[$channel] = new LogChannel($logger, $levels, $scopes);
        $this->configured = true;
    }

    public function has(string $channel): bool
    {
        return \array_key_exists($channel, $this->channels);
    }

    public function channel(string $channel): LoggerInterface
    {
        if (!\array_key_exists($channel, $this->channels)) {
            throw new UnknownLogChannelException("unknown log channel: {$channel}");
        }

        return $this->channels[$channel]->logger;
    }

    public function remove(string $channel): void
    {
        unset($this->channels[$channel]);
    }

    public function scope(string $scope): LoggerInterface
    {
        if ($scope === '') {
            throw new InvalidArgumentException('log scope must not be empty');
        }

        return new ScopedLog($this, $scope);
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->dispatch($level, $message, $context, null);
    }

    /**
     * @param array<mixed> $context
     */
    public function dispatch(mixed $level, string|Stringable $message, array $context, ?string $scope): void
    {
        $this->assertKnownLevel($level);

        if ($this->channels === [] && !$this->configured) {
            $this->set('default', new SimpleLogger());
        }

        if ($scope !== null && !\array_key_exists('_scope', $context)) {
            $context['_scope'] = $scope;
        }

        foreach ($this->channels as $channel) {
            $matchLevel = $channel->levels === [] || \in_array($level, $channel->levels, true);
            $matchScope = $channel->scopes === [] || ($scope !== null && \in_array($scope, $channel->scopes, true));

            if ($matchLevel && $matchScope) {
                $channel->logger->log($level, $message, $context);
            }
        }
    }

    /** @throws \Psr\Log\InvalidArgumentException */
    private function assertKnownLevel(mixed $level): void
    {
        if (!\is_string($level) || !\in_array($level, self::levels(), true)) {
            throw new \Psr\Log\InvalidArgumentException('unknown log level: ' . get_debug_type($level));
        }
    }

    /** @return list<string> */
    private static function levels(): array
    {
        return [
            LogLevel::DEBUG,
            LogLevel::INFO,
            LogLevel::NOTICE,
            LogLevel::WARNING,
            LogLevel::ERROR,
            LogLevel::CRITICAL,
            LogLevel::ALERT,
            LogLevel::EMERGENCY,
        ];
    }
}
