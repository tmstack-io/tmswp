<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Kernel\KernelContext;

/**
 * acf-bridgeの構成。既定値は登録したKernelの構成からforKernel()で決める。生成の入力・出力の場所と、生成の調整
 * (classMap・methodNameMap・optionsPagePostIds)を持つ。
 */
final readonly class BridgeConfig
{
    /**
     * @param list<string> $jsonDirs acf-jsonを読むディレクトリ(絶対パス)。並びの先のディレクトリを優先する。存在しないものは無視する
     * @param string $outputDir 生成先のディレクトリ(絶対パス)
     * @param string $namespace 生成クラスの名前空間
     * @param array<string, string> $classMap グループのkey => クラスの短い名前
     * @param array<string, string> $methodNameMap "{グループのkey}.{フィールド名}" => メソッド名
     * @param array<string, string> $optionsPagePostIds オプションページのmenu_slug => ACFのpost_id。acf-jsonの
     *                                                  ui_options_pageに無いページ(PHPで登録したページ)に使う
     * @param bool $previewMeta プレビュー時にACFの値を保存する(§8.3)
     * @param bool $registerLoadJsonDirs jsonDirsをacf/settings/load_jsonに加える
     *
     * @throws AcfBridgeRuntimeException 入力が不正な場合(設定の誤り)
     */
    public function __construct(
        public array $jsonDirs,
        public string $outputDir,
        public string $namespace,
        public array $classMap = [],
        public array $methodNameMap = [],
        public array $optionsPagePostIds = [],
        public bool $previewMeta = false,
        public bool $registerLoadJsonDirs = true,
    ) {
        foreach ($jsonDirs as $jsonDir) {
            self::assertAbsolutePath('jsonDirs', $jsonDir);
        }

        self::assertAbsolutePath('outputDir', $outputDir);

        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $namespace) !== 1) {
            throw new AcfBridgeRuntimeException("BridgeConfig namespace must be a PHP namespace without leading or trailing backslashes: '{$namespace}'");
        }

        self::assertStringMap('classMap', $classMap);
        self::assertStringMap('methodNameMap', $methodNameMap);
        self::assertStringMap('optionsPagePostIds', $optionsPagePostIds);
    }

    /**
     * Kernelの構成から既定の構成を作る。
     *
     * - テーマ: jsonDirs = [{stylesheet}/acf-json](子テーマのときは{template}/acf-jsonも後ろに加える)
     * - プラグイン: jsonDirs = [dirname(pluginFile)/acf-json]
     * - outputDir = {appDir}/Acf、namespace = {appNamespace}\Acf
     *
     * @param array<string, string> $classMap
     * @param array<string, string> $methodNameMap
     * @param array<string, string> $optionsPagePostIds
     *
     * @throws AcfBridgeRuntimeException プラグインのKernelにpluginFileが無い場合、または入力が不正な場合
     */
    public static function forKernel(
        KernelConfig $config,
        array $classMap = [],
        array $methodNameMap = [],
        array $optionsPagePostIds = [],
        bool $previewMeta = false,
    ): self {
        if ($config->context === KernelContext::Theme) {
            $jsonDirs = [rtrim(get_stylesheet_directory(), '/\\') . '/acf-json'];
            $templateDir = rtrim(get_template_directory(), '/\\') . '/acf-json';

            if ($templateDir !== $jsonDirs[0]) {
                $jsonDirs[] = $templateDir;
            }
        } else {
            if ($config->pluginFile === null) {
                throw new AcfBridgeRuntimeException('the plugin kernel has no plugin file to locate its acf-json directory');
            }

            $jsonDirs = [\dirname($config->pluginFile) . '/acf-json'];
        }

        return new self(
            jsonDirs: $jsonDirs,
            outputDir: $config->appDir . '/Acf',
            namespace: $config->appNamespace . '\Acf',
            classMap: $classMap,
            methodNameMap: $methodNameMap,
            optionsPagePostIds: $optionsPagePostIds,
            previewMeta: $previewMeta,
        );
    }

    /**
     * @throws AcfBridgeRuntimeException
     */
    private static function assertAbsolutePath(string $name, mixed $path): void
    {
        if (!\is_string($path) || (!str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\/\\\]/', $path) !== 1)) {
            throw new AcfBridgeRuntimeException("BridgeConfig {$name} must be absolute paths, got " . (\is_string($path) ? "'{$path}'" : get_debug_type($path)));
        }
    }

    /**
     * @param array<mixed> $map
     *
     * @throws AcfBridgeRuntimeException
     */
    private static function assertStringMap(string $name, array $map): void
    {
        foreach ($map as $key => $value) {
            if (!\is_string($key) || $key === '' || !\is_string($value) || $value === '') {
                throw new AcfBridgeRuntimeException("BridgeConfig {$name} must map non-empty strings to non-empty strings");
            }
        }
    }
}
