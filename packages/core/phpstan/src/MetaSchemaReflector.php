<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PHPStan\Reflection\ReflectionProvider;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\OptionSchema;

final class MetaSchemaReflector
{
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    /**
     * @param class-string $className
     *
     * @return array{type: string, hasDefault: bool, single: bool}|null
     */
    public function metaSchemaOf(string $className, string $constantName): ?array
    {
        $constant = $this->metaConstant($className, $constantName);

        if ($constant === null) {
            return null;
        }

        $attributes = $constant->getAttributes(MetaSchema::class);

        if ($attributes === []) {
            return null;
        }

        $schema = $attributes[0]->newInstance();

        return [
            'type' => $schema->type->value,
            'hasDefault' => $schema->default !== null,
            'single' => $schema->single,
        ];
    }

    /**
     * @param class-string $className
     */
    public function metaConstantNameForKey(string $className, string $key): ?string
    {
        if (!$this->reflectionProvider->hasClass($className) || !class_exists($className)) {
            return null;
        }

        $reflection = new ReflectionClass($className);

        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            if ($constant->getAttributes(MetaSchema::class) === []) {
                continue;
            }

            if ($constant->getValue() === $key) {
                return $constant->getName();
            }
        }

        return null;
    }

    /**
     * @param class-string $enumClass
     *
     * @return array{type: string, hasDefault: bool}|null
     */
    public function optionSchemaOf(string $enumClass, string $caseName): ?array
    {
        if (!$this->reflectionProvider->hasClass($enumClass) || !enum_exists($enumClass)) {
            return null;
        }

        $reflection = new ReflectionEnum($enumClass);

        if (!$reflection->hasCase($caseName)) {
            return null;
        }

        $case = $reflection->getCase($caseName);

        if (!$case instanceof ReflectionEnumBackedCase) {
            return null;
        }

        $attributes = $case->getAttributes(OptionSchema::class);

        if ($attributes === []) {
            return null;
        }

        $schema = $attributes[0]->newInstance();

        return [
            'type' => $schema->type->value,
            'hasDefault' => $schema->default !== null,
        ];
    }

    /**
     * @param class-string $className
     */
    public function declaringClassOfMetaConstant(string $className, string $constantName): ?string
    {
        $constant = $this->metaConstant($className, $constantName);

        return $constant?->getDeclaringClass()->getName();
    }

    /**
     * @param class-string $className
     */
    private function metaConstant(string $className, string $constantName): ?ReflectionClassConstant
    {
        if (!$this->reflectionProvider->hasClass($className) || !class_exists($className)) {
            return null;
        }

        $reflection = new ReflectionClass($className);

        $constant = $reflection->getReflectionConstant($constantName);

        return $constant instanceof ReflectionClassConstant ? $constant : null;
    }
}
