<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelContext;

final readonly class BridgeConfig
{
    /**
     * @param list<string> $jsonDirs
     * @param array<string, string> $classMap
     * @param array<string, string> $methodNameMap
     */
    public function __construct(
        public array $jsonDirs,
        public string $outputDir,
        public string $namespace,
        public array $classMap = [],
        public array $methodNameMap = [],
        public bool $previewMeta = false,
        public bool $registerLoadJsonDirs = true,
    ) {}

    public static function fromKernel(): self
    {
        $kernels = Kernel::all();

        if (\count($kernels) !== 1) {
            throw new AcfBridgeRuntimeException('AcfBridge::boot() must be called after Kernel::boot(), or pass BridgeConfig explicitly.');
        }

        $kernel = reset($kernels);
        $config = $kernel->config();
        $jsonDirs = [];

        if ($config->context === KernelContext::Theme) {
            if (!\function_exists('get_stylesheet_directory') || !\function_exists('get_template_directory')) {
                throw new AcfBridgeRuntimeException('theme ACF json directories require WordPress theme directory functions');
            }

            $jsonDirs[] = rtrim(get_stylesheet_directory(), '/\\') . '/acf-json';
            $templateDir = rtrim(get_template_directory(), '/\\') . '/acf-json';

            if ($templateDir !== $jsonDirs[0]) {
                $jsonDirs[] = $templateDir;
            }
        } else {
            if ($config->pluginFile === null) {
                throw new AcfBridgeRuntimeException('plugin context requires pluginFile for ACF bridge defaults');
            }

            $jsonDirs[] = \dirname($config->pluginFile) . '/acf-json';
        }

        return new self(
            jsonDirs: $jsonDirs,
            outputDir: $config->appDir . '/Acf',
            namespace: $config->appNamespace . '\\Acf',
        );
    }
}
