<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail;

final readonly class ThumbnailConfig
{
    public function __construct(
        public int $maxDimension = 2560,
        public int $defaultQuality = 82,
        public bool $defaultCrop = true,
        public string $cacheDirName = 'tmswp-thumb',
    ) {}
}
