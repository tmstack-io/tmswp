<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\ObjectType;

final class TaxonomyInitializer implements ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        if (!is_subclass_of($component->class, Taxonomy::class)) {
            throw new DiscoveryException("taxonomy component must extend Taxonomy: {$component->class}");
        }

        $component->class::register($kernel);

        $registry = $kernel->container()->get(MetaSchemaRegistry::class);

        $registry->registerEntity($component->class, ObjectType::Term, $component->class::slug());
    }
}
