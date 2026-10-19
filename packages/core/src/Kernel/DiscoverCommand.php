<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Exception\KernelException;
use WP_CLI;

/**
 * `wp tmswp discover [--check]`。起動中の全 Kernel の appDir を debug に関係なく走査し、探索結果ファイルを
 * 生成する。`--check` は生成する内容とファイルをバイト単位で比べ、無い・古い Kernel があれば非 0 で終わる。
 */
final class DiscoverCommand
{
    private const USAGE = 'Usage: wp tmswp discover [--check]';

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (!class_exists('WP_CLI')) {
            throw new KernelException('WP_CLI is not available');
        }

        if ($args !== [] || array_diff(array_keys($assocArgs), ['check']) !== []) {
            WP_CLI::error(self::USAGE);
        }

        $kernels = Kernel::all();

        if ($kernels === []) {
            WP_CLI::error('no TMSWP kernel is booted; boot the kernels from the theme or plugins before running wp tmswp discover');
        }

        try {
            // すべての Kernel を走査し終えてから書く(途中の Kernel の失敗で一部のファイルだけが更新されないようにする)
            $targets = [];

            foreach ($kernels as $namespace => $kernel) {
                $targets[$namespace] = [self::file($kernel), self::discovery($kernel)->scan()];
            }

            if (\array_key_exists('check', $assocArgs)) {
                $stale = $this->staleFiles($targets);

                if ($stale !== []) {
                    WP_CLI::error(implode("\n", $stale) . "\nrun wp tmswp discover to regenerate the discovery files");
                }

                WP_CLI::success('discovery files are up to date for ' . \count($targets) . ' kernel(s)');

                return;
            }

            $written = 0;

            foreach ($targets as $namespace => [$file, $result]) {
                if ($file->isUpToDate($result)) {
                    WP_CLI::line("{$namespace}: {$file->path()} is up to date");
                    continue;
                }

                $file->write($result);
                WP_CLI::line("{$namespace}: wrote {$file->path()}");
                ++$written;
            }
        } catch (DiscoveryException $exception) {
            // WP_CLI::error() は非 0 で終了するため、ここから先へは進まない
            WP_CLI::error($exception->getMessage());

            return;
        }

        WP_CLI::success('discovery files are up to date for ' . \count($targets) . " kernel(s); wrote {$written} file(s)");
    }

    /**
     * 探索結果ファイルが無い・内容が違う Kernel を「namespace: パス is missing / is out of date」で列挙する。
     *
     * @param array<string, array{DiscoveryFile, DiscoveryResult}> $targets
     *
     * @return list<string>
     */
    private function staleFiles(array $targets): array
    {
        $stale = [];

        foreach ($targets as $namespace => [$file, $result]) {
            if (!is_file($file->path())) {
                $stale[] = "{$namespace}: {$file->path()} is missing";
            } elseif (!$file->isUpToDate($result)) {
                $stale[] = "{$namespace}: {$file->path()} is out of date";
            }
        }

        return $stale;
    }

    private static function file(Kernel $kernel): DiscoveryFile
    {
        $file = $kernel->container()->get(DiscoveryFile::class);

        return $file;
    }

    private static function discovery(Kernel $kernel): Discovery
    {
        $discovery = $kernel->container()->get(Discovery::class);

        return $discovery;
    }
}
