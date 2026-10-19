<?php

declare(strict_types=1);

namespace TMSWP\Core\User;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\ObjectType;

final class UserRoleInitializer implements ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        if (!is_subclass_of($component->class, UserRole::class)) {
            throw new DiscoveryException("user role component must extend UserRole: {$component->class}");
        }

        $component->class::register();

        $registry = $kernel->container()->get(MetaSchemaRegistry::class);

        $registry->registerEntity($component->class, ObjectType::User, '');
    }
}
