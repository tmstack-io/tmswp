<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Exception\InvalidArgumentException;

final readonly class Thumbnail
{
    public function __construct(
        public string $url,
        public ?int $width,
        public ?int $height,
        public string $alt,
    ) {
        if ($url === '') {
            throw new InvalidArgumentException('thumbnail url must not be empty');
        }
    }

    /** @param array<string, scalar|null> $attributes */
    public function img(array $attributes = []): string
    {
        unset($attributes['src'], $attributes['alt'], $attributes['width'], $attributes['height']);

        $attributes = [
            'src' => $this->url,
            'alt' => $this->alt,
            ...$attributes,
        ];

        if ($this->width !== null) {
            $attributes['width'] = $this->width;
        }

        if ($this->height !== null) {
            $attributes['height'] = $this->height;
        }

        $html = '<img';

        foreach ($attributes as $name => $value) {
            if ($value === null) {
                continue;
            }

            $html .= ' ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return $html . '>';
    }
}
