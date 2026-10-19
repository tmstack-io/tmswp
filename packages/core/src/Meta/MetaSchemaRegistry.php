<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionClassConstant;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Attribute\WithMetaRevisions;
use TMSWP\Core\Entity\WpBridge;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Exception\EntityNotRegisteredException;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Post\PostType;
use WP_Post;
use WP_Term;
use WP_User;

final class MetaSchemaRegistry
{
    /**
     * @var array<class-string, array{
     *     objectType: ObjectType,
     *     subtype: string,
     *     schemas: MetaSchemaSet,
     *     storageClass: class-string<MetaStorageInterface>,
     *     revisions: bool
     * }>
     */
    private array $entities = [];

    /** @var array<string, class-string> */
    private array $declaredScopes = [];

    /** @var array<string, string> */
    private array $externalScopes = [];

    private bool $registered = false;

    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @param class-string $entityClass
     */
    public function registerEntity(string $entityClass, ObjectType $objectType, string $subtype): void
    {
        $reflection = new ReflectionClass($entityClass);
        $schemas = MetaSchemaSet::fromList($this->definitionsFor($reflection));
        $storageClass = $this->storageClassFor($reflection);
        $this->assertStorage($storageClass, $entityClass);
        $revisions = $this->revisionsEnabled($reflection, $objectType);

        $this->registerEntry($entityClass, $objectType, $subtype, $schemas, $storageClass, $revisions);
    }

    public function schemasFor(string $entityClass): MetaSchemaSet
    {
        if (!\array_key_exists($entityClass, $this->entities)) {
            return MetaSchemaSet::empty();
        }

        return $this->entities[$entityClass]['schemas'];
    }

    public function objectTypeOf(string $entityClass): ObjectType
    {
        if (!\array_key_exists($entityClass, $this->entities)) {
            throw new EntityNotRegisteredException("{$entityClass} is not registered. Did you forget an entity attribute?");
        }

        return $this->entities[$entityClass]['objectType'];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return MetaBag<T>
     */
    public function bagFor(string $entityClass, WP_Post|WP_Term|WP_User|int $object): MetaBag
    {
        if (!\array_key_exists($entityClass, $this->entities)) {
            throw new EntityNotRegisteredException("{$entityClass} is not registered. Did you forget an entity attribute?");
        }

        $entry = $this->entities[$entityClass];
        $storage = $this->container->get($entry['storageClass']);

        if (!$storage instanceof MetaStorageInterface) {
            throw new InvalidSchemaException("meta storage '{$entry['storageClass']}' must implement MetaStorageInterface");
        }

        return new MetaBag(
            $entityClass,
            $entry['objectType'],
            $entry['objectType']->resolveId($object),
            $storage,
            $entry['schemas'],
        );
    }

    public function markExternallyManaged(ObjectType $objectType, string $subtype, string $key, string $managedBy): void
    {
        $scope = $this->scopeKey($objectType, $subtype, $key);
        $declaredClass = $this->declaredClassFor($objectType, $subtype, $key);

        if ($declaredClass !== null) {
            throw new DuplicateMetaKeyException("externally managed meta key '{$key}' by {$managedBy} is already declared by {$declaredClass}");
        }

        $this->externalScopes[$scope] = $managedBy;
    }

    public function isExternallyManaged(ObjectType $objectType, string $subtype, string $key): bool
    {
        return \array_key_exists($this->scopeKey($objectType, $subtype, $key), $this->externalScopes)
            || \array_key_exists($this->scopeKey($objectType, 'all', $key), $this->externalScopes);
    }

    /**
     * @return list<array{entityClass: class-string, objectType: ObjectType, subtype: string, schema: MetaSchemaDefinition}>
     */
    public function schemas(): array
    {
        $schemas = [];

        foreach ($this->entities as $entityClass => $entry) {
            foreach ($entry['schemas']->all() as $schema) {
                $schemas[] = [
                    'entityClass' => $entityClass,
                    'objectType' => $entry['objectType'],
                    'subtype' => $entry['subtype'],
                    'schema' => $schema,
                ];
            }
        }

        return $schemas;
    }

    public function registerAll(): void
    {
        if ($this->registered) {
            throw new LogicException('MetaSchemaRegistry::registerAll() must only be called once');
        }

        foreach ($this->entities as $entry) {
            foreach ($entry['schemas']->all() as $definition) {
                if ($this->isExternallyManaged($entry['objectType'], $entry['subtype'], $definition->key)) {
                    continue;
                }

                $args = [
                    'type' => $definition->type->value,
                    'description' => $definition->description,
                    'single' => $definition->single,
                    'show_in_rest' => $definition->showInRest,
                ];

                if ($definition->hasDefault()) {
                    $args['default'] = $definition->default;
                }

                if ($definition->sanitize !== null) {
                    if (!\is_callable($definition->sanitize)) {
                        throw new InvalidSchemaException("sanitize callback is not callable: {$definition->ownerClass}::{$definition->constantName}");
                    }

                    $args['sanitize_callback'] = $definition->sanitize;
                }

                if ($entry['objectType'] === ObjectType::Post && $entry['revisions']) {
                    $args['revisions_enabled'] = true;
                }

                match ($entry['objectType']) {
                    ObjectType::Post => WpBridge::call('register_post_meta', $entry['subtype'], $definition->key, $args),
                    ObjectType::Term => WpBridge::call('register_term_meta', $entry['subtype'], $definition->key, $args),
                    ObjectType::User => WpBridge::call('register_meta', 'user', $definition->key, $args),
                };
            }
        }

        $this->registered = true;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return list<MetaSchemaDefinition>
     */
    private function definitionsFor(ReflectionClass $reflection): array
    {
        $definitions = [];

        foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $attributes = $constant->getAttributes(MetaSchema::class);

            if ($attributes === []) {
                continue;
            }

            $value = $constant->getValue();

            if (!\is_string($value)) {
                throw new InvalidSchemaException("meta schema constant must be a string: {$reflection->getName()}::{$constant->getName()}");
            }

            $attribute = $attributes[0]->newInstance();

            $definitions[] = MetaSchemaDefinition::fromAttribute(
                $reflection->getName(),
                $constant->getName(),
                $value,
                $attribute,
            );
        }

        return $definitions;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return class-string<MetaStorageInterface>
     */
    private function storageClassFor(ReflectionClass $reflection): string
    {
        $attributes = $reflection->getAttributes(UseMetaStorage::class);

        if ($attributes === []) {
            return WpMetaStorage::class;
        }

        $attribute = $attributes[0]->newInstance();

        return $attribute->storageClass;
    }

    /** @param class-string<MetaStorageInterface> $storageClass */
    private function assertStorage(string $storageClass, string $entityClass): void
    {
        $storage = $this->container->get($storageClass);

        if (!$storage instanceof MetaStorageInterface) {
            throw new InvalidSchemaException("meta storage '{$storageClass}' for {$entityClass} must implement MetaStorageInterface");
        }
    }

    /** @param ReflectionClass<object> $reflection */
    private function revisionsEnabled(ReflectionClass $reflection, ObjectType $objectType): bool
    {
        if ($reflection->getAttributes(WithMetaRevisions::class) === []) {
            return false;
        }

        if ($objectType !== ObjectType::Post) {
            throw new InvalidSchemaException("#[WithMetaRevisions] is only allowed on post type entities: {$reflection->getName()}");
        }

        if (!is_subclass_of($reflection->getName(), PostType::class)) {
            throw new InvalidSchemaException("#[WithMetaRevisions] requires a PostType subclass: {$reflection->getName()}");
        }

        if (!\in_array('revisions', $reflection->getName()::definition()->supportsList(), true)) {
            throw new InvalidSchemaException("#[WithMetaRevisions] requires supports('revisions'): {$reflection->getName()}");
        }

        return true;
    }

    /**
     * @param class-string $entityClass
     * @param class-string<MetaStorageInterface> $storageClass
     */
    private function registerEntry(
        string $entityClass,
        ObjectType $objectType,
        string $subtype,
        MetaSchemaSet $schemas,
        string $storageClass,
        bool $revisions,
    ): void {
        foreach ($schemas->all() as $definition) {
            if ($this->isExternallyManaged($objectType, $subtype, $definition->key)) {
                throw new DuplicateMetaKeyException("meta key '{$definition->key}' is externally managed and cannot be declared by {$entityClass}");
            }

            $scope = $this->scopeKey($objectType, $subtype, $definition->key);

            if (\array_key_exists($scope, $this->declaredScopes)) {
                throw new DuplicateMetaKeyException("duplicate meta key '{$definition->key}' for {$objectType->value}:{$subtype}");
            }

            $this->declaredScopes[$scope] = $entityClass;
        }

        $this->entities[$entityClass] = [
            'objectType' => $objectType,
            'subtype' => $subtype,
            'schemas' => $schemas,
            'storageClass' => $storageClass,
            'revisions' => $revisions,
        ];
    }

    private function scopeKey(ObjectType $objectType, string $subtype, string $key): string
    {
        return $objectType->value . "\0" . $subtype . "\0" . $key;
    }

    private function declaredClassFor(ObjectType $objectType, string $subtype, string $key): ?string
    {
        if ($subtype !== 'all') {
            return $this->declaredScopes[$this->scopeKey($objectType, $subtype, $key)] ?? null;
        }

        $prefix = $objectType->value . "\0";
        $suffix = "\0" . $key;

        foreach ($this->declaredScopes as $scope => $class) {
            if (str_starts_with($scope, $prefix) && str_ends_with($scope, $suffix)) {
                return $class;
            }
        }

        return null;
    }
}
