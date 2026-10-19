<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

trait UsesTempDirectories
{
    /** @var list<string> */
    private array $tempDirectories = [];

    protected function createTempDirectory(string $prefix): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmswp-' . $prefix . '-' . uniqid('', true);

        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("一時ディレクトリを作成できません: {$dir}");
        }

        $this->tempDirectories[] = $dir;

        return $dir;
    }

    protected function cleanupTempDirectories(): void
    {
        foreach (array_reverse($this->tempDirectories) as $dir) {
            if (is_dir($dir)) {
                $this->deleteDirectory($dir);
            }
        }

        $this->tempDirectories = [];
    }

    private function deleteDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($dir);
    }
}
