<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Cli;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TMSWP\Thumbnail\Thumbnail;

final class ThumbCommand
{
    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function clear(array $args, array $assocArgs): void
    {
        $days = $this->days($assocArgs);
        $deleted = Thumbnail::cache()->clear($days);
        $this->callWpCli('success', "deleted {$deleted} thumbnail cache file(s)");
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function status(array $args, array $assocArgs): void
    {
        $uploads = Thumbnail::uploads();
        $dir = rtrim($uploads['basedir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . Thumbnail::config()->cacheDirName;
        $count = 0;
        $bytes = 0;

        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }

                ++$count;
                $bytes += $fileInfo->getSize();
            }
        }

        $this->callWpCli('line', "files: {$count}");
        $this->callWpCli('line', "bytes: {$bytes}");
    }

    /** @param array<string, mixed> $assocArgs */
    private function days(array $assocArgs): ?int
    {
        $value = $assocArgs['days'] ?? null;

        if ($value === null) {
            return null;
        }

        if (!\is_scalar($value) || filter_var((string) $value, FILTER_VALIDATE_INT) === false) {
            throw new \TMSWP\Core\Exception\InvalidArgumentException('thumb clear --days must be an integer');
        }

        return (int) $value;
    }

    private function callWpCli(string $method, string $message): void
    {
        $callback = ['WP_CLI', $method];

        if (!\is_callable($callback)) {
            throw new \TMSWP\Core\Exception\LogicException("WP_CLI::{$method}() is not available");
        }

        $callback($message);
    }
}
