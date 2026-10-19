<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use Psr\Container\ContainerInterface;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Exception\InvalidSchemaException;

final class OptionRepositoryFactory
{
    /** @var array<class-string, OptionRepository> */
    private array $repositories = [];

    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @param class-string $groupClass
     */
    public function for(string $groupClass): OptionRepository
    {
        if (\array_key_exists($groupClass, $this->repositories)) {
            return $this->repositories[$groupClass];
        }

        if (!enum_exists($groupClass)) {
            throw new InvalidSchemaException("option group must be an enum: {$groupClass}");
        }

        $reflection = new ReflectionEnum($groupClass);

        if (!$reflection->isBacked() || $reflection->getBackingType()->getName() !== 'string') {
            throw new InvalidSchemaException("option group must be a string backed enum: {$groupClass}");
        }

        $attributes = $reflection->getAttributes(AsOptionGroup::class);

        if ($attributes === []) {
            throw new InvalidSchemaException("option group enum requires #[AsOptionGroup]: {$groupClass}");
        }

        $attribute = $attributes[0]->newInstance();
        $storage = $this->container->get($attribute->storage);

        if (!$storage instanceof OptionStorageInterface) {
            throw new InvalidSchemaException("option storage '{$attribute->storage}' must implement OptionStorageInterface");
        }

        $definitions = [];

        foreach ($reflection->getCases() as $case) {
            $caseName = $case->getName();

            if (!\is_string($caseName)) {
                throw new InvalidSchemaException("option group case name must be a string: {$groupClass}");
            }

            if (!$case instanceof ReflectionEnumBackedCase) {
                throw new InvalidSchemaException("option group case must be backed: {$groupClass}::{$caseName}");
            }

            $caseAttributes = $case->getAttributes(OptionSchema::class);

            if ($caseAttributes === []) {
                continue;
            }

            $value = $case->getBackingValue();

            if (!\is_string($value)) {
                throw new InvalidSchemaException("option group case must use string backing value: {$groupClass}::{$caseName}");
            }

            $definitions[] = OptionSchemaDefinition::fromAttribute(
                $groupClass,
                $caseName,
                $value,
                $caseAttributes[0]->newInstance(),
            );
        }

        $repository = new OptionRepository($groupClass, $attribute->prefix, OptionSchemaSet::fromList($definitions), $storage);
        $this->repositories[$groupClass] = $repository;

        return $repository;
    }

    /**
     * @param class-string $groupClass
     */
    public function preload(string $groupClass): void
    {
        $this->for($groupClass);
    }
}
