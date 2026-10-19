<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ThumbCache
{
    public function __construct(
        private readonly string $baseDir,
        private readonly string $baseUrl,
    ) {}

    public function key(string $relativePath, int $mtime, int $width, int $height, string $options): string
    {
        return substr(hash('sha256', "{$relativePath}|{$mtime}|{$width}|{$height}|{$options}"), 0, 40);
    }

    public function path(string $key, string $ext): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . substr($key, 0, 2) . DIRECTORY_SEPARATOR . $key . '.' . $ext;
    }

    public function url(string $key, string $ext): string
    {
        return rtrim($this->baseUrl, '/') . '/' . substr($key, 0, 2) . '/' . $key . '.' . $ext;
    }

    public function exists(string $key, string $ext): bool
    {
        return is_file($this->path($key, $ext));
    }

    public function ensureDirectory(string $key): void
    {
        $root = $this->baseDir;
        $dir = $root . DIRECTORY_SEPARATOR . substr($key, 0, 2);

        if (!is_dir($root)) {
            wp_mkdir_p($root);
            file_put_contents($root . DIRECTORY_SEPARATOR . 'index.html', '');
        }

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
    }

    public function clear(?int $olderThanDays = null): int
    {
        if (!is_dir($this->baseDir)) {
            return 0;
        }

        $threshold = $olderThanDays === null ? null : time() - ($olderThanDays * DAY_IN_SECONDS);
        $deleted = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseDir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile() || $fileInfo->getFilename() === 'index.html') {
                continue;
            }

            if ($threshold !== null && $fileInfo->getMTime() >= $threshold) {
                continue;
            }

            if (unlink($fileInfo->getPathname())) {
                ++$deleted;
            }
        }

        return $deleted;
    }
}
