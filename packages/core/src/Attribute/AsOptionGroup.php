<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Option\OptionGroupInitializer;
use TMSWP\Core\Option\OptionStorageInterface;
use TMSWP\Core\Option\WpOptionStorage;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsOptionGroup implements ComponentAttributeInterface
{
    /** @param class-string<OptionStorageInterface> $storage */
    public function __construct(
        public string $prefix = '',
        public string $storage = WpOptionStorage::class,
    ) {}

    public function hookName(KernelContext $context): string
    {
        return 'init';
    }

    public function hookPriority(): int
    {
        return 5;
    }

    public function initializerClass(): string
    {
        return OptionGroupInitializer::class;
    }
}
