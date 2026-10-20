<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use Closure;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Log\LogChannel;

final readonly class KernelConfig
{
    private const DISCOVERY_FILE_NAME = 'tmswp-discovery.php';

    /**
     * @param list<ExtensionInterface> $extensions
     * @param (Closure(): array<string, LogChannel>)|null $logChannels
     */
    private function __construct(
        public KernelContext $context,
        public string $appDir,
        public string $appNamespace,
        public ?string $pluginFile,
        public bool $debug,
        public bool $actions,
        public array $extensions,
        public ?Closure $logChannels,
        public LabelLanguage $labelLanguage,
    ) {}

    /**
     * @param list<ExtensionInterface> $extensions この Kernel で有効にする拡張。同じクラスは1つまで
     * @param (Closure(): array<string, LogChannel>)|null $logChannels ログのチャネル(チャネル名 => 設定)を返すクロージャ。
     *                                                                 boot 中に1回呼ぶ。null なら default チャネル(SimpleLogger)を遅延生成する
     * @param LabelLanguage $labelLanguage labelLanguage() を指定しない PostTypeDefinition・TaxonomyDefinition のラベル言語
     *
     * @throws KernelException 引数が不正な場合
     */
    public static function theme(
        string $appDir,
        string $appNamespace = 'App',
        ?bool $debug = null,
        bool $actions = true,
        array $extensions = [],
        ?Closure $logChannels = null,
        LabelLanguage $labelLanguage = LabelLanguage::En,
    ): self {
        return new self(
            KernelContext::Theme,
            self::resolveAppDir($appDir),
            self::normalizeNamespace($appNamespace),
            null,
            self::resolveDebug($debug),
            $actions,
            self::validateExtensions($extensions),
            $logChannels,
            $labelLanguage,
        );
    }

    /**
     * @param list<ExtensionInterface> $extensions この Kernel で有効にする拡張。同じクラスは1つまで
     * @param (Closure(): array<string, LogChannel>)|null $logChannels ログのチャネル(チャネル名 => 設定)を返すクロージャ。
     *                                                                 boot 中に1回呼ぶ。null なら default チャネル(SimpleLogger)を遅延生成する
     * @param LabelLanguage $labelLanguage labelLanguage() を指定しない PostTypeDefinition・TaxonomyDefinition のラベル言語
     *
     * @throws KernelException 引数が不正な場合
     */
    public static function plugin(
        string $file,
        string $appDir,
        string $appNamespace = 'App',
        ?bool $debug = null,
        array $extensions = [],
        ?Closure $logChannels = null,
        LabelLanguage $labelLanguage = LabelLanguage::En,
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
            self::validateExtensions($extensions),
            $logChannels,
            $labelLanguage,
        );
    }

    /**
     * テーマ・プラグインのルートディレクトリ(appDir の親ディレクトリ)。探索結果ファイルの置き場で、Log の静的な呼び出しが
     * 記録先の Kernel を決める範囲でもある。
     */
    public function rootDir(): string
    {
        return \dirname($this->appDir);
    }

    /**
     * 探索結果ファイルの絶対パス。appDir の親ディレクトリ(テーマ・プラグインのルート)直下に固定する。
     */
    public function discoveryFile(): string
    {
        return $this->rootDir() . '/' . self::DISCOVERY_FILE_NAME;
    }

    /**
     * @param list<ExtensionInterface> $extensions
     *
     * @throws KernelException 同じクラスの拡張が2つ以上ある場合(設定の誤り)
     *
     * @return list<ExtensionInterface>
     */
    private static function validateExtensions(array $extensions): array
    {
        $classes = [];

        foreach ($extensions as $extension) {
            $class = $extension::class;

            if (\array_key_exists($class, $classes)) {
                throw new KernelException("extension {$class} is registered more than once in the same kernel");
            }

            $classes[$class] = true;
        }

        return $extensions;
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

        // && は真偽値を返すため、WP_DEBUG が 1 や 'true' 等の真値でも true になる(true との厳密比較はしない)
        return \defined('WP_DEBUG') && WP_DEBUG;
    }
}
