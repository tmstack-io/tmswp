<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Container\Container;
use TMSWP\Core\Exception\CircularDependencyException;
use TMSWP\Core\Exception\NotFoundException;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class ContainerTest extends UnitTestCase
{
    public function testItResolvesInstancesAliasesFactoriesAndAutowiring(): void
    {
        $container = new Container();
        $dependency = new ContainerDependency();

        $container->set(ContainerDependency::class, $dependency);
        $container->set('service', ContainerService::class);
        $container->set(FactoryService::class, static fn(): FactoryService => new FactoryService());
        $container->alias(ContainerService::class, 'service');

        $service = $container->get(ContainerService::class);

        self::assertInstanceOf(ContainerService::class, $service);
        self::assertSame($dependency, $service->dependency);
        self::assertSame($service, $container->get('service'));
        self::assertInstanceOf(FactoryService::class, $container->get(FactoryService::class));
    }

    public function testItFailsOnCircularDependencies(): void
    {
        $this->expectException(CircularDependencyException::class);

        (new Container())->get(ContainerCircularA::class);
    }

    public function testItFailsOnUnknownEntries(): void
    {
        $this->expectException(NotFoundException::class);

        (new Container())->get('TMSWP\\Core\\Tests\\Unit\\MissingContainerEntry');
    }
}

final class ContainerDependency {}

final readonly class ContainerService
{
    public function __construct(
        public ContainerDependency $dependency,
    ) {}
}

final class FactoryService {}

final readonly class ContainerCircularA
{
    public function __construct(
        public ContainerCircularB $dependency,
    ) {}
}

final readonly class ContainerCircularB
{
    public function __construct(
        public ContainerCircularA $dependency,
    ) {}
}
