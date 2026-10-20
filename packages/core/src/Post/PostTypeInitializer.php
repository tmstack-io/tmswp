<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\ObjectType;

final class PostTypeInitializer implements ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        if (!is_subclass_of($component->class, PostType::class)) {
            throw new DiscoveryException("post type component must extend PostType: {$component->class}");
        }

        $component->class::register($kernel);

        $registry = $kernel->container()->get(MetaSchemaRegistry::class);

        $registry->registerEntity($component->class, ObjectType::Post, $component->class::slug());
    }
}
