<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

final class UrlSigner
{
    public function __construct(private readonly string $secret) {}

    public function sign(string $src, int $width, int $height, string $options): string
    {
        return hash_hmac('sha256', "{$src}|{$width}|{$height}|{$options}", $this->secret);
    }

    public function verify(string $src, string $width, string $height, string $options, string $signature): bool
    {
        $expected = hash_hmac('sha256', "{$src}|{$width}|{$height}|{$options}", $this->secret);

        return hash_equals($expected, $signature);
    }
}
