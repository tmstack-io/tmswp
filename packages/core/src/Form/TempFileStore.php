<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TMSWP\Core\Exception\Form\TempFileException;

final class TempFileStore
{
    public function __construct(private readonly string $baseDir) {}

    public static function default(): self
    {
        if (!\function_exists('wp_upload_dir')) {
            throw new TempFileException('wp_upload_dir is not available');
        }

        $upload = wp_upload_dir();

        if (!\is_array($upload) || !\is_string($upload['basedir'] ?? null) || $upload['basedir'] === '') {
            throw new TempFileException('wp_upload_dir did not return basedir');
        }

        return new self(rtrim($upload['basedir'], '/\\') . '/tmswp-form-tmp');
    }

    public function store(UploadedFile $file): StoredFile
    {
        if (!$file->isValid()) {
            throw new TempFileException($file->errorMessage());
        }

        $this->prepareBaseDir();
        $token = bin2hex(random_bytes(16));
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $token;

        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new TempFileException("failed to create temporary file directory: {$dir}");
        }

        if (!\function_exists('sanitize_file_name')) {
            throw new TempFileException('sanitize_file_name is not available');
        }

        $name = sanitize_file_name($file->clientName);
        $name = $name !== '' ? $name : 'file.bin';
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        $mime = $file->detectedMime();

        if (!move_uploaded_file($file->tmpPath, $path)) {
            throw new TempFileException("failed to move uploaded file: {$file->tmpPath}");
        }

        $meta = ['name' => $name, 'mime' => $mime, 'size' => $file->size, 'created' => time()];

        if (file_put_contents($dir . '/meta.json', json_encode($meta, JSON_THROW_ON_ERROR)) === false) {
            throw new TempFileException('failed to write temporary file metadata');
        }

        if (mt_rand(1, 100) === 1) {
            $this->gc();
        }

        return new StoredFile($token, $path, $name, $mime, $file->size);
    }

    public function exists(string $token): bool
    {
        return preg_match('/\A[a-f0-9]{32}\z/', $token) === 1 && is_file($this->baseDir . DIRECTORY_SEPARATOR . $token . '/meta.json');
    }

    public function resolve(string $token): StoredFile
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $token) !== 1) {
            throw new TempFileException("invalid temporary file token: {$token}");
        }

        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $token;
        $metaFile = $dir . '/meta.json';
        $json = is_file($metaFile) ? file_get_contents($metaFile) : false;

        if (!\is_string($json)) {
            throw new TempFileException("temporary file metadata not found: {$token}");
        }

        try {
            $meta = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TempFileException("temporary file metadata is broken: {$token}", previous: $exception);
        }

        if (!\is_array($meta) || !\is_string($meta['name'] ?? null) || !\is_string($meta['mime'] ?? null) || !\is_int($meta['size'] ?? null)) {
            throw new TempFileException("temporary file metadata is broken: {$token}");
        }

        $path = $dir . DIRECTORY_SEPARATOR . $meta['name'];

        if (!is_file($path)) {
            throw new TempFileException("temporary file not found: {$token}");
        }

        return new StoredFile($token, $path, $meta['name'], $meta['mime'], $meta['size']);
    }

    public function delete(string $token): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $token) !== 1) {
            throw new TempFileException("invalid temporary file token: {$token}");
        }

        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $token;

        if (is_dir($dir)) {
            $this->deleteDirectory($dir);
        }
    }

    public function gc(int $maxAgeSeconds = 86400): int
    {
        if (!is_dir($this->baseDir)) {
            return 0;
        }

        $deleted = 0;
        $now = time();

        foreach (new FilesystemIterator($this->baseDir, FilesystemIterator::SKIP_DOTS) as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isDir()) {
                continue;
            }

            $metaFile = $fileInfo->getPathname() . '/meta.json';
            $created = $fileInfo->getMTime();
            $json = is_file($metaFile) ? file_get_contents($metaFile) : false;

            if (\is_string($json)) {
                $meta = json_decode($json, true);
                $created = \is_array($meta) && \is_int($meta['created'] ?? null) ? $meta['created'] : $created;
            }

            if (($now - $created) <= $maxAgeSeconds) {
                continue;
            }

            $this->deleteDirectory($fileInfo->getPathname());
            ++$deleted;
        }

        return $deleted;
    }

    private function prepareBaseDir(): void
    {
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0700, true) && !is_dir($this->baseDir)) {
            throw new TempFileException("failed to create temporary file base directory: {$this->baseDir}");
        }

        if (file_put_contents($this->baseDir . '/.htaccess', "Require all denied\n") === false) {
            throw new TempFileException('failed to write temporary file directory .htaccess');
        }

        if (file_put_contents($this->baseDir . '/index.html', '') === false) {
            throw new TempFileException('failed to write temporary file directory index.html');
        }
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
