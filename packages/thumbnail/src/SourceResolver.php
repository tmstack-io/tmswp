<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

final class SourceResolver
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    public function resolve(string $src): ?string
    {
        $uploads = wp_upload_dir();

        if (!\is_array($uploads) || !\is_string($uploads['basedir'] ?? null) || !\is_string($uploads['baseurl'] ?? null)) {
            return null;
        }

        $path = $this->pathFromSource($src, $uploads['basedir'], $uploads['baseurl']);

        if ($path === null || !is_file($path) || !$this->hasAllowedExtension($path)) {
            return null;
        }

        $base = realpath($uploads['basedir']);
        $real = realpath($path);

        if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    private function pathFromSource(string $src, string $baseDir, string $baseUrl): ?string
    {
        if (preg_match('/\Aid:([1-9][0-9]*)\z/', $src, $matches) === 1) {
            $file = get_attached_file((int) $matches[1]);

            return \is_string($file) ? $file : null;
        }

        $prefix = rtrim($baseUrl, '/') . '/';

        if (!str_starts_with($src, $prefix)) {
            return null;
        }

        $relative = rawurldecode(substr($src, \strlen($prefix)));

        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function hasAllowedExtension(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return \in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }
}
