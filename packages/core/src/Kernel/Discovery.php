<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use SplFileInfo;
use Throwable;
use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Log\Log;
use UnitEnum;

final class Discovery
{
    public function __construct(
        private readonly KernelConfig $config,
        private readonly ComponentCache $cache,
    ) {}

    public function discover(): DiscoveryResult
    {
        $cached = $this->cache->load();

        if ($cached instanceof DiscoveryResult) {
            return $cached;
        }

        $result = $this->scan();

        if (!$this->cache->store($result)) {
            Log::warning('tmswp.discovery: failed to write component cache to {file}. Discovery will run on every request.', [
                'file' => $this->cache->file(),
            ]);
        }

        return $result;
    }

    public function scan(): DiscoveryResult
    {
        $dirs = [$this->config->appDir => $this->mtime($this->config->appDir)];
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->config->appDir, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $current) use (&$dirs): bool {
                    if ($current->isDir()) {
                        $baseName = $current->getBasename();

                        if (str_starts_with($baseName, '.') || $baseName === 'vendor' || $baseName === 'node_modules') {
                            return false;
                        }

                        $realPath = $current->getRealPath();

                        if ($realPath !== false) {
                            $dirs[$realPath] = $this->mtime($realPath);
                        }
                    }

                    return true;
                },
            ),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            if (preg_match('/^[A-Z][A-Za-z0-9]*\.php$/', $fileInfo->getBasename()) !== 1) {
                continue;
            }

            $realPath = $fileInfo->getRealPath();

            if ($realPath === false) {
                continue;
            }

            $files[$realPath] = $this->mtime($realPath);
        }

        ksort($dirs, SORT_STRING);
        ksort($files, SORT_STRING);

        $components = [];
        $hookMethods = [];

        foreach ($files as $file => $mtime) {
            $fqcn = $this->fqcnForFile($file);
            $reflection = $this->reflectCandidate($file, $fqcn);

            if (!$reflection instanceof ReflectionClass) {
                continue;
            }

            $className = $reflection->getName();
            $this->assertOptionSchemaTargets($reflection, $file, $fqcn);
            $componentAttributes = $reflection->getAttributes(ComponentAttributeInterface::class, ReflectionAttribute::IS_INSTANCEOF);
            $hasAsHook = $reflection->getAttributes(AsHook::class) !== [];
            $hasRegisterOverride = $hasAsHook && $this->hasRegisterOverride($reflection);
            $hasHookMethod = false;

            if ($componentAttributes !== [] && !$reflection->isInstantiable() && !$reflection->isEnum()) {
                $this->fail("component attribute on non-instantiable class {$fqcn}", $file, $fqcn);
                continue;
            }

            foreach ($componentAttributes as $attribute) {
                $arguments = $attribute->getArguments();

                if (!$this->isCacheSafe($arguments)) {
                    $this->fail('attribute arguments must be cache-safe (scalar/array/enum)', $file, $fqcn);
                    continue 2;
                }

                try {
                    $instance = $attribute->newInstance();
                } catch (Throwable $exception) {
                    $this->fail("failed to instantiate attribute on {$fqcn}: {$exception->getMessage()}", $file, $fqcn, $exception);
                    continue 2;
                }

                if (!$instance instanceof ComponentAttributeInterface) {
                    continue;
                }

                if ($instance instanceof AsHook && !$hasRegisterOverride) {
                    continue;
                }

                $components[] = new DiscoveredComponent($className, $attribute->getName(), $arguments, $file, $mtime);
            }

            foreach ($reflection->getMethods() as $method) {
                $methodAttributes = [
                    ...$method->getAttributes(Action::class),
                    ...$method->getAttributes(Filter::class),
                ];

                if ($methodAttributes === []) {
                    continue;
                }

                $hasHookMethod = true;

                if (!$hasAsHook) {
                    $this->fail("method-level #[Action]/#[Filter] requires #[AsHook] on class {$fqcn}", $file, $fqcn);
                    continue 2;
                }

                if ($method->isStatic() || !$method->isPublic() || $this->hasVariadicParameter($method)) {
                    $this->fail("method-level hook attributes require public non-static methods without variadic parameters: {$fqcn}::{$method->getName()}", $file, $fqcn);
                    continue 2;
                }

                foreach ($methodAttributes as $methodAttribute) {
                    try {
                        $hookAttribute = $methodAttribute->newInstance();
                    } catch (Throwable $exception) {
                        $this->fail("failed to instantiate hook attribute on {$fqcn}::{$method->getName()}: {$exception->getMessage()}", $file, $fqcn, $exception);
                        continue 3;
                    }

                    $hookMethods[] = new DiscoveredHookMethod(
                        $className,
                        $method->getName(),
                        $hookAttribute->hook,
                        $hookAttribute->priority,
                        $method->getNumberOfParameters(),
                    );
                }
            }

            if ($hasAsHook && !$hasHookMethod && !$hasRegisterOverride) {
                $this->fail("hook class {$fqcn} registers no hooks", $file, $fqcn);
            }
        }

        usort(
            $components,
            static fn(DiscoveredComponent $a, DiscoveredComponent $b): int => [$a->class, $a->attributeClass] <=> [$b->class, $b->attributeClass],
        );
        usort(
            $hookMethods,
            static fn(DiscoveredHookMethod $a, DiscoveredHookMethod $b): int => [$a->class, $a->method, $a->hook, $a->priority] <=> [$b->class, $b->method, $b->hook, $b->priority],
        );

        return new DiscoveryResult($components, $hookMethods, $files, $dirs);
    }

    private function fqcnForFile(string $file): string
    {
        $relative = substr($file, \strlen($this->config->appDir) + 1);
        $withoutExtension = substr($relative, 0, -4);

        return $this->config->appNamespace . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $withoutExtension);
    }

    /**
     * @return ReflectionClass<object>|null
     */
    private function reflectCandidate(string $file, string $fqcn): ?ReflectionClass
    {
        if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn) && !enum_exists($fqcn)) {
            $this->fail(
                "file {$file} is expected to define class {$fqcn}; check the PSR-4 autoload mapping '{$this->config->appNamespace}\\' => '{$this->config->appDir}' in composer.json and run 'composer dump-autoload' if the mapping looks correct",
                $file,
                $fqcn,
            );

            return null;
        }

        $reflection = new ReflectionClass($fqcn);

        if ($reflection->isInterface() || $reflection->isTrait()) {
            return null;
        }

        $declaredFile = $reflection->getFileName();

        if ($this->config->debug && $reflection->getName() !== $fqcn) {
            $this->fail("class name case mismatch for {$fqcn}; reflection returned {$reflection->getName()}", $file, $fqcn);
        }

        if ($this->config->debug && ($declaredFile === false || realpath($declaredFile) !== $file)) {
            $this->fail("class {$fqcn} was loaded from a different file; expected {$file}", $file, $fqcn);
        }

        return $reflection;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function hasRegisterOverride(ReflectionClass $reflection): bool
    {
        if (!$reflection->hasMethod('register')) {
            return false;
        }

        $method = $reflection->getMethod('register');

        return $method->getDeclaringClass()->getName() !== \TMSWP\Core\Hook\Hook::class;
    }

    private function hasVariadicParameter(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function assertOptionSchemaTargets(ReflectionClass $reflection, string $file, string $fqcn): void
    {
        if ($reflection->getAttributes(OptionSchema::class) !== []) {
            $this->fail("#[OptionSchema] is only allowed on enum cases: {$fqcn}", $file, $fqcn);
        }

        $className = $reflection->getName();
        $enum = $reflection->isEnum() && is_subclass_of($className, UnitEnum::class) ? new ReflectionEnum($className) : null;

        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->getAttributes(OptionSchema::class) === []) {
                continue;
            }

            if ($enum instanceof ReflectionEnum && $enum->hasCase($constant->getName())) {
                continue;
            }

            $this->fail("#[OptionSchema] is only allowed on enum cases: {$fqcn}::{$constant->getName()}", $file, $fqcn);
        }
    }

    /**
     */
    private function isCacheSafe(mixed $value): bool
    {
        if ($value === null || \is_scalar($value) || $value instanceof UnitEnum) {
            return true;
        }

        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (!$this->isCacheSafe($entry)) {
                return false;
            }
        }

        return true;
    }

    private function fail(string $message, string $file, string $class, ?Throwable $previous = null): void
    {
        $messageWithContext = "{$message}; file: {$file}; class: {$class}";

        if ($this->config->debug) {
            throw new DiscoveryException($messageWithContext, previous: $previous);
        }

        Log::error('tmswp.discovery: ' . $messageWithContext, [
            'file' => $file,
            'class' => $class,
            'exception' => $previous,
        ]);
    }

    private function mtime(string $path): int
    {
        $mtime = filemtime($path);

        if ($mtime === false) {
            return 0;
        }

        return $mtime;
    }
}
