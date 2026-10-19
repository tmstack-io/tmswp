<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;

final class ModelInitializer implements ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        if (!is_subclass_of($component->class, Model::class)) {
            throw new DiscoveryException("model component must extend Model: {$component->class}");
        }

        $migrator = $kernel->container()->get(Migrator::class);

        if (!$migrator instanceof Migrator) {
            throw new KernelException('container entry Migrator must resolve to Migrator');
        }

        $migrator->register($component->class);
    }
}
