<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use TMSWP\Core\Exception\KernelException;

final readonly class KernelConfig
{
    private const DISCOVERY_FILE_NAME = 'tmswp-discovery.php';

    private function __construct(
        public KernelContext $context,
        public string $appDir,
        public string $appNamespace,
        public ?string $pluginFile,
        public bool $debug,
        public bool $actions,
    ) {}

    public static function theme(
        string $appDir,
        string $appNamespace = 'App',
        ?bool $debug = null,
        bool $actions = true,
    ): self {
        return new self(
            KernelContext::Theme,
            self::resolveAppDir($appDir),
            self::normalizeNamespace($appNamespace),
            null,
            self::resolveDebug($debug),
            $actions,
        );
    }

    public static function plugin(
        string $file,
        string $appDir,
        string $appNamespace = 'App',
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
            self::resolveDebug($debug),
            false,
        );
    }

    /**
     * 探索結果ファイルの絶対パス。appDir の親ディレクトリ(テーマ・プラグインのルート)直下に固定する。
     */
    public function discoveryFile(): string
    {
        return \dirname($this->appDir) . '/' . self::DISCOVERY_FILE_NAME;
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

    private static function resolveDebug(?bool $debug): bool
    {
        if ($debug !== null) {
            return $debug;
        }

        return \defined('WP_DEBUG') && WP_DEBUG === true;
    }
}
