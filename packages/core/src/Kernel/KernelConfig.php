<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use TMSWP\Core\Exception\KernelException;

final readonly class KernelConfig
{
    private function __construct(
        public KernelContext $context,
        public string $appDir,
        public string $appNamespace,
        public ?string $pluginFile,
        public string $cacheDir,
        public bool $debug,
        public bool $actions,
    ) {}

    public static function theme(
        string $appDir,
        string $appNamespace = 'App',
        ?string $cacheDir = null,
        ?bool $debug = null,
        bool $actions = true,
    ): self {
        return new self(
            KernelContext::Theme,
            self::resolveAppDir($appDir),
            self::normalizeNamespace($appNamespace),
            null,
            self::resolveCacheDir($cacheDir),
            self::resolveDebug($debug),
            $actions,
        );
    }

    public static function plugin(
        string $file,
        string $appDir,
        string $appNamespace = 'App',
        ?string $cacheDir = null,
        ?bool $debug = null,
    ): self {
        $pluginFile = realpath($file);

        if ($pluginFile === false || !is_file($pluginFile)) {
            throw new KernelException("plugin file not found: {$file}");
        }

        return new self(
            KernelContext::Plugin,
            self::resolveAppDir($appDir),
            self::normalizeNamespace($appNamespace),
            $pluginFile,
            self::resolveCacheDir($cacheDir),
            self::resolveDebug($debug),
            false,
        );
    }

    public function instanceId(): string
    {
        return strtolower(str_replace('\\', '-', $this->appNamespace)) . '-' . substr(md5($this->appDir), 0, 12);
    }

    private static function normalizeNamespace(string $appNamespace): string
    {
        $namespace = trim($appNamespace, '\\');

        if ($namespace === '') {
            throw new KernelException('app namespace must not be empty');
        }

        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $namespace) !== 1) {
            throw new KernelException("invalid app namespace: {$appNamespace}");
        }

        if ($namespace === 'TMSWP' || str_starts_with($namespace, 'TMSWP\\')) {
            throw new KernelException("app namespace is reserved by TMSWP core: {$namespace}");
        }

        return $namespace;
    }

    private static function resolveAppDir(string $appDir): string
    {
        $realPath = realpath($appDir);

        if ($realPath === false || !is_dir($realPath) || !is_readable($realPath)) {
            throw new KernelException("app directory not found: {$appDir}");
        }

        return rtrim($realPath, '/\\');
    }

    private static function resolveCacheDir(?string $cacheDir): string
    {
        if ($cacheDir !== null) {
            return rtrim($cacheDir, '/\\');
        }

        if (\defined('WP_CONTENT_DIR')) {
            return rtrim(WP_CONTENT_DIR, '/\\') . '/cache/tmswp';
        }

        return rtrim(sys_get_temp_dir(), '/\\') . '/tmswp-cache';
    }

    private static function resolveDebug(?bool $debug): bool
    {
        if ($debug !== null) {
            return $debug;
        }

        return \defined('WP_DEBUG') && WP_DEBUG === true;
    }
}
