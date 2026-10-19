<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Exception\DiscoveryException;

final readonly class DiscoveredComponent
{
    /**
     * @param non-empty-string $class
     * @param non-empty-string $attributeClass
     * @param array<array-key, mixed> $attributeArguments
     */
    public function __construct(
        public string $class,
        public string $attributeClass,
        public array $attributeArguments,
    ) {}

    public function attribute(): ComponentAttributeInterface
    {
        if (!class_exists($this->attributeClass)) {
            throw new DiscoveryException("component attribute class not found: {$this->attributeClass}");
        }

        $attribute = new $this->attributeClass(...$this->attributeArguments);

        if (!$attribute instanceof ComponentAttributeInterface) {
            throw new DiscoveryException("attribute class '{$this->attributeClass}' does not implement ComponentAttributeInterface");
        }

        return $attribute;
    }
}
