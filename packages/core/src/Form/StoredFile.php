<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use FilesystemIterator;
use SplFileInfo;
use TMSWP\Core\Exception\Form\TempFileException;

final readonly class StoredFile
{
    public function __construct(
        public string $token,
        public string $path,
        public string $clientName,
        public string $mime,
        public int $size,
    ) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function moveTo(string $directory, ?string $filename = null): string
    {
        if (!$this->exists()) {
            throw new TempFileException('temporary file does not exist');
        }

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new TempFileException("failed to create directory: {$directory}");
        }

        $name = $filename ?? $this->clientName;

        if (!\function_exists('wp_unique_filename')) {
            throw new TempFileException('wp_unique_filename is not available');
        }

        $name = wp_unique_filename($directory, $name);
        $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;

        if (!rename($this->path, $target)) {
            throw new TempFileException("failed to move temporary file: {$this->path}");
        }

        $this->deleteDirectory(\dirname($this->path));

        return $target;
    }

    public function toAttachment(int $parentPostId = 0): int
    {
        if (!\function_exists('media_handle_sideload')) {
            throw new TempFileException('media_handle_sideload is not available');
        }

        $attachmentId = media_handle_sideload($this->sideloadFile(), $parentPostId);

        if (is_wp_error($attachmentId)) {
            throw new TempFileException($attachmentId->get_error_message());
        }

        $this->deleteDirectory(\dirname($this->path));

        return (int) $attachmentId;
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        $this->deleteDirectory(\dirname($this->path));
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $fileInfo) {
            if ($fileInfo instanceof SplFileInfo && $fileInfo->isFile()) {
                unlink($fileInfo->getPathname());
            }
        }

        rmdir($dir);
    }

    /** @return array<string, string> */
    private function sideloadFile(): array
    {
        return [
            'name' => $this->clientName,
            'type' => $this->mime,
            'tmp_name' => $this->path,
            'error' => '0',
            'size' => (string) $this->size,
        ];
    }
}
