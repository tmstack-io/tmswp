<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

use Throwable;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Log\Log;
use TMSWP\Thumbnail\Cli\ThumbCommand;
use TMSWP\Thumbnail\Exception\InvalidThumbOptionException;
use TMSWP\Thumbnail\Exception\InvalidThumbSourceException;
use WP_CLI;

final class Thumbnail
{
    private static ?ThumbnailConfig $config = null;

    private function __construct() {}

    public static function register(?ThumbnailConfig $config = null): void
    {
        if (self::$config instanceof ThumbnailConfig) {
            throw new LogicException('Thumbnail::register() was already called');
        }

        self::$config = $config ?? new ThumbnailConfig();

        add_action('rest_api_init', static function (): void {
            self::controller()->registerRoutes();
        }, 10, 0);

        if (\defined('WP_CLI') && WP_CLI === true && class_exists('WP_CLI')) {
            WP_CLI::add_command('tmswp thumb', new ThumbCommand());
        }
    }

    /** @param array<string, mixed> $options */
    public static function url(int|string $src, ?int $width = null, ?int $height = null, array $options = []): string
    {
        try {
            return self::buildUrl($src, $width, $height, $options);
        } catch (Throwable $exception) {
            Log::warning('failed to build thumbnail URL', ['exception' => $exception]);

            if (\defined('WP_DEBUG') && WP_DEBUG === true) {
                throw $exception;
            }

            return '';
        }
    }

    public static function config(): ThumbnailConfig
    {
        if (!self::$config instanceof ThumbnailConfig) {
            throw new LogicException('Thumbnail::register() must be called before using thumbnails');
        }

        return self::$config;
    }

    public static function isRegistered(): bool
    {
        return self::$config instanceof ThumbnailConfig;
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$config = null;
    }

    public static function controller(): RestController
    {
        return new RestController(self::signer(), new SourceResolver(), self::cache(), self::config());
    }

    public static function cache(): ThumbCache
    {
        $uploads = self::uploads();
        $config = self::config();

        return new ThumbCache(
            rtrim($uploads['basedir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $config->cacheDirName,
            rtrim($uploads['baseurl'], '/') . '/' . $config->cacheDirName,
        );
    }

    public static function signer(): UrlSigner
    {
        return new UrlSigner(wp_salt('auth'));
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function normalizeOptions(array $options, ThumbnailConfig $config): string
    {
        $allowed = ['crop' => true, 'quality' => true];
        $normalized = [];

        foreach ($options as $key => $value) {
            if (!\is_string($key) || !\array_key_exists($key, $allowed)) {
                throw new InvalidThumbOptionException('thumbnail option keys must be crop or quality');
            }

            if ($key === 'crop') {
                if (!\is_bool($value)) {
                    throw new InvalidThumbOptionException('thumbnail crop option must be boolean');
                }

                if ($value !== $config->defaultCrop) {
                    $normalized[$key] = $value ? '1' : '0';
                }

                continue;
            }

            if (!\is_int($value) || $value < 1 || $value > 100) {
                throw new InvalidThumbOptionException('thumbnail quality option must be an integer from 1 to 100');
            }

            if ($value !== $config->defaultQuality) {
                $normalized[$key] = (string) $value;
            }
        }

        ksort($normalized, SORT_STRING);
        $pairs = [];

        foreach ($normalized as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        return implode('&', $pairs);
    }

    /**
     * @return array{basedir: string, baseurl: string}
     */
    public static function uploads(): array
    {
        $uploads = wp_upload_dir();

        if (!\is_array($uploads) || !\is_string($uploads['basedir'] ?? null) || !\is_string($uploads['baseurl'] ?? null)) {
            throw new InvalidThumbSourceException('wp_upload_dir() did not return basedir and baseurl');
        }

        return ['basedir' => $uploads['basedir'], 'baseurl' => $uploads['baseurl']];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function buildUrl(int|string $src, ?int $width, ?int $height, array $options): string
    {
        $config = self::config();
        $source = \is_int($src) ? "id:{$src}" : $src;
        $widthValue = $width ?? 0;
        $heightValue = $height ?? 0;

        self::assertDimensions($widthValue, $heightValue, $config);

        $optionString = self::normalizeOptions($options, $config);
        $resolver = new SourceResolver();
        $path = $resolver->resolve($source);

        if ($path === null) {
            throw new InvalidThumbSourceException("thumbnail source could not be resolved: {$source}");
        }

        $uploads = self::uploads();
        $relative = self::relativePath($path, $uploads['basedir']);
        $mtime = filemtime($path);

        if ($mtime === false) {
            throw new InvalidThumbSourceException("thumbnail source mtime could not be read: {$source}");
        }

        $extension = self::extension($path);
        $cache = self::cache();
        $key = $cache->key($relative, $mtime, $widthValue, $heightValue, $optionString);

        if ($cache->exists($key, $extension)) {
            return $cache->url($key, $extension);
        }

        $signature = self::signer()->sign($source, $widthValue, $heightValue, $optionString);
        $endpoint = rest_url('tmswp/v1/thumb');

        return add_query_arg([
            'src' => $source,
            'w' => (string) $widthValue,
            'h' => (string) $heightValue,
            'o' => $optionString,
            's' => $signature,
        ], $endpoint);
    }

    public static function assertDimensions(int $width, int $height, ThumbnailConfig $config): void
    {
        if (($width === 0 && $height === 0) || $width < 0 || $height < 0 || $width > $config->maxDimension || $height > $config->maxDimension) {
            throw new InvalidThumbSourceException('thumbnail dimensions are invalid');
        }
    }

    public static function relativePath(string $path, string $baseDir): string
    {
        $base = realpath($baseDir);
        $real = realpath($path);

        if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            throw new InvalidThumbSourceException('thumbnail source is outside uploads');
        }

        return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($real, \strlen($base))), '/');
    }

    public static function extension(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }
}
