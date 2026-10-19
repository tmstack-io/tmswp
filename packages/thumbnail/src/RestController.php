<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

use TMSWP\Core\Log\Log;
use TMSWP\Thumbnail\Exception\InvalidThumbOptionException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestController
{
    public function __construct(
        private readonly UrlSigner $signer,
        private readonly SourceResolver $resolver,
        private readonly ThumbCache $cache,
        private readonly ThumbnailConfig $config,
    ) {}

    public function registerRoutes(): void
    {
        register_rest_route('tmswp/v1', '/thumb', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $src = $this->stringParam($request, 'src');
        $widthRaw = $this->stringParam($request, 'w');
        $heightRaw = $this->stringParam($request, 'h');
        $optionsRaw = $this->stringParam($request, 'o');
        $signature = $this->stringParam($request, 's');

        if (preg_match('/\A[0-9a-f]{64}\z/i', $signature) !== 1) {
            return new WP_Error('tmswp_thumb_unsigned', 'Unsigned thumbnail request.', ['status' => 403]);
        }

        if (!$this->signer->verify($src, $widthRaw, $heightRaw, $optionsRaw, $signature)) {
            return new WP_Error('tmswp_thumb_bad_signature', 'Bad thumbnail signature.', ['status' => 403]);
        }

        try {
            $options = self::parseOptions($optionsRaw, $this->config);
            $width = $this->integer($widthRaw);
            $height = $this->integer($heightRaw);
            Thumbnail::assertDimensions($width, $height, $this->config);
        } catch (InvalidThumbOptionException|Exception\InvalidThumbSourceException $exception) {
            return new WP_Error('tmswp_thumb_bad_request', $exception->getMessage(), ['status' => 400]);
        }

        $path = $this->resolver->resolve($src);

        if ($path === null) {
            return new WP_Error('tmswp_thumb_not_found', 'Thumbnail source was not found.', ['status' => 404]);
        }

        $mtime = filemtime($path);

        if ($mtime === false) {
            return new WP_Error('tmswp_thumb_not_found', 'Thumbnail source mtime was not readable.', ['status' => 404]);
        }

        $uploads = Thumbnail::uploads();
        $relative = Thumbnail::relativePath($path, $uploads['basedir']);
        $extension = Thumbnail::extension($path);
        $key = $this->cache->key($relative, $mtime, $width, $height, $optionsRaw);

        if (!$this->cache->exists($key, $extension)) {
            $generated = $this->generate($path, $this->cache->path($key, $extension), $key, $width, $height, $options['crop'], $options['quality']);

            if (!$generated) {
                return $this->redirect($this->sourceUrl($relative, $uploads['baseurl']));
            }
        }

        return $this->redirect($this->cache->url($key, $extension));
    }

    /**
     * @return array{crop: bool, quality: int}
     */
    public static function parseOptions(string $options, ThumbnailConfig $config): array
    {
        $parsed = ['crop' => $config->defaultCrop, 'quality' => $config->defaultQuality];

        if ($options === '') {
            return $parsed;
        }

        foreach (explode('&', $options) as $pair) {
            $parts = explode('=', $pair, 2);

            if (\count($parts) !== 2) {
                throw new InvalidThumbOptionException('thumbnail option format is invalid');
            }

            [$key, $value] = $parts;

            if ($key === 'crop') {
                if (!\in_array($value, ['0', '1'], true)) {
                    throw new InvalidThumbOptionException('thumbnail crop option must be 0 or 1');
                }

                $parsed['crop'] = $value === '1';
                continue;
            }

            if ($key === 'quality') {
                if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > 100) {
                    throw new InvalidThumbOptionException('thumbnail quality option must be an integer from 1 to 100');
                }

                $parsed['quality'] = (int) $value;
                continue;
            }

            throw new InvalidThumbOptionException('thumbnail option keys must be crop or quality');
        }

        return $parsed;
    }

    private function stringParam(WP_REST_Request $request, string $key): string
    {
        $value = $request->get_param($key);

        return \is_scalar($value) ? (string) $value : '';
    }

    private function integer(string $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new Exception\InvalidThumbSourceException('thumbnail dimensions must be integers');
        }

        return (int) $value;
    }

    private function generate(string $source, string $target, string $key, int $width, int $height, bool $crop, int $quality): bool
    {
        $this->cache->ensureDirectory($key);
        $editor = wp_get_image_editor($source);

        if (is_wp_error($editor)) {
            Log::error('failed to create image editor for thumbnail', ['error' => $editor]);

            return false;
        }

        $resize = $editor->resize($width > 0 ? $width : null, $height > 0 ? $height : null, $crop);

        if (is_wp_error($resize)) {
            Log::error('failed to resize thumbnail', ['error' => $resize]);

            return false;
        }

        $editor->set_quality($quality);
        $saved = $editor->save($target);

        if (is_wp_error($saved)) {
            Log::error('failed to save thumbnail', ['error' => $saved]);

            return false;
        }

        return true;
    }

    private function redirect(string $url): WP_REST_Response
    {
        return new WP_REST_Response(null, 302, [
            'Location' => $url,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function sourceUrl(string $relative, string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($relative, '/');
    }
}
