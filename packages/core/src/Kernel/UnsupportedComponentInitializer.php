<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use TMSWP\Core\Exception\UnsupportedComponentException;

/**
 * 後続マイルストーンが実装するコンポーネントをM1で誤って初期化した場合の明示エラー。
 *
 * @internal
 */
final class UnsupportedComponentInitializer implements ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        throw new UnsupportedComponentException(
            "component initializer for '{$component->attributeClass}' is not implemented in M1: {$component->class}",
        );
    }
}
