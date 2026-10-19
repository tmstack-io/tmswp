<?php

declare(strict_types=1);

namespace TMSWP\Core\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use TMSWP\Core\Exception\CircularDependencyException;
use TMSWP\Core\Exception\ContainerException;
use TMSWP\Core\Exception\NotFoundException;

final class Container implements ContainerInterface
{
    /** @var array<string, Closure(self): mixed|string> */
    private array $definitions = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var list<string> */
    private array $resolving = [];

    public function set(string $id, object|string $concrete): void
    {
        $resolvedId = $this->resolveAlias($id);

        if (\array_key_exists($resolvedId, $this->instances)) {
            throw new ContainerException("cannot redefine resolved container entry '{$resolvedId}'");
        }

        if ($concrete instanceof Closure) {
            $this->definitions[$resolvedId] = $concrete;

            return;
        }

        if (\is_string($concrete)) {
            $this->definitions[$resolvedId] = $concrete;

            return;
        }

        $this->instances[$resolvedId] = $concrete;
    }

    public function alias(string $alias, string $id): void
    {
        if ($alias === $id) {
            throw new ContainerException("container alias '{$alias}' cannot reference itself");
        }

        if (\array_key_exists($alias, $this->instances)) {
            throw new ContainerException("cannot alias resolved container entry '{$alias}'");
        }

        if (\array_key_exists($id, $this->aliases)) {
            throw new ContainerException("container alias '{$alias}' cannot reference another alias '{$id}'");
        }

        $this->aliases[$alias] = $id;
    }

    public function get(string $id): mixed
    {
        $resolvedId = $this->resolveAlias($id);

        if (\array_key_exists($resolvedId, $this->instances)) {
            return $this->instances[$resolvedId];
        }

        if (\in_array($resolvedId, $this->resolving, true)) {
            $chain = implode(' -> ', [...$this->resolving, $resolvedId]);

            throw new CircularDependencyException("circular dependency: {$chain}");
        }

        $this->resolving[] = $resolvedId;

        try {
            if (\array_key_exists($resolvedId, $this->definitions)) {
                $definition = $this->definitions[$resolvedId];
                if ($definition instanceof Closure) {
                    $value = $definition($this);
                } else {
                    if (!class_exists($definition)) {
                        throw new NotFoundException("defined class '{$definition}' not found for '{$resolvedId}'");
                    }

                    $value = $this->instantiate($definition);
                }
            } elseif (class_exists($resolvedId)) {
                $value = $this->instantiate($resolvedId);
            } else {
                throw new NotFoundException("no entry or class found for '{$resolvedId}'");
            }

            $this->instances[$resolvedId] = $value;

            return $value;
        } finally {
            array_pop($this->resolving);
        }
    }

    public function has(string $id): bool
    {
        $resolvedId = $this->resolveAlias($id);

        if (\array_key_exists($resolvedId, $this->instances) || \array_key_exists($resolvedId, $this->definitions)) {
            return true;
        }

        if (!class_exists($resolvedId)) {
            return false;
        }

        return (new ReflectionClass($resolvedId))->isInstantiable();
    }

    private function resolveAlias(string $id): string
    {
        if (\array_key_exists($id, $this->aliases)) {
            return $this->aliases[$id];
        }

        return $id;
    }

    /**
     * @param class-string $class
     */
    private function instantiate(string $class): object
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("class '{$class}' is not instantiable");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                continue;
            }

            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            if ($type !== null && $type->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new ContainerException(
                "unresolvable parameter \${$parameter->getName()} of {$class}::__construct(); register the entry explicitly or add a default value",
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
