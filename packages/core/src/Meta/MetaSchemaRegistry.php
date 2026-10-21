<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Attribute\WithMetaRevisions;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Exception\EntityNotRegisteredException;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Exception\LogicException;
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

    /** @var array<string, true> (ObjectType, subtype, key) の組 */
    private array $externalScopes = [];

    /** @var array<string, true> 全サブタイプで外部管理する (ObjectType, key) の組 */
    private array $externalAllSubtypeScopes = [];

    private bool $registered = false;

    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    /**
     * エンティティ1クラスのメタスキーマを、定数と属性(#[MetaSchema]・#[UseMetaStorage]・#[WithMetaRevisions])の
     * リフレクションで集めて登録する。エンティティの Initializer が Kernel::boot() の中で呼ぶ。
     *
     * @param class-string $entityClass
     *
     * @throws LogicException registerAll() の後に呼んだ場合(WordPress に登録されないスキーマになるため)
     * @throws InvalidSchemaException スキーマ・ストレージ・#[WithMetaRevisions] の宣言の誤り
     * @throws DuplicateMetaKeyException キーの重複、または外部管理のキーを宣言した場合
     */
    public function registerEntity(string $entityClass, ObjectType $objectType, string $subtype): void
    {
        if ($this->registered) {
            throw new LogicException("MetaSchemaRegistry::registerEntity() must be called before registerAll(): {$entityClass}");
        }

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
            fn(): bool => $this->registered,
        );
    }

    /**
     * 外部(acf-bridge 等)が管理するキーを申告する。申告したキーは #[MetaSchema] で宣言できないため、register_*_meta
     * されない。#[MetaSchema] は boot の中で登録済みのため、acf-bridge が init 6 で申告した時点で重複を検出する。
     *
     * @throws DuplicateMetaKeyException 同じ (ObjectType, subtype, key) を #[MetaSchema] が宣言済み
     */
    public function markExternallyManaged(ObjectType $objectType, string $subtype, string $key): void
    {
        $declaredClass = $this->declaredScopes[$this->scopeKey($objectType, $subtype, $key)] ?? null;

        if ($declaredClass !== null) {
            throw new DuplicateMetaKeyException("externally managed meta key '{$key}' for {$objectType->value}:{$subtype} is already declared by {$declaredClass}");
        }

        $this->externalScopes[$this->scopeKey($objectType, $subtype, $key)] = true;
    }

    /**
     * ObjectType のすべてのサブタイプについて、外部が管理するキーを申告する。
     *
     * @throws DuplicateMetaKeyException 同じ (ObjectType, key) を、いずれかのサブタイプの #[MetaSchema] が宣言済み
     */
    public function markExternallyManagedInAllSubtypes(ObjectType $objectType, string $key): void
    {
        foreach ($this->entities as $entityClass => $entry) {
            if ($entry['objectType'] === $objectType && $entry['schemas']->has($key)) {
                throw new DuplicateMetaKeyException("externally managed meta key '{$key}' for all {$objectType->value} subtypes is already declared by {$entityClass} ({$objectType->value}:{$entry['subtype']})");
            }
        }

        $this->externalAllSubtypeScopes[$objectType->value . "\0" . $key] = true;
    }

    public function isExternallyManaged(ObjectType $objectType, string $subtype, string $key): bool
    {
        return \array_key_exists($this->scopeKey($objectType, $subtype, $key), $this->externalScopes)
            || \array_key_exists($objectType->value . "\0" . $key, $this->externalAllSubtypeScopes);
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

    /**
     * 登録済みのスキーマを register_*_meta で WordPress に登録する。Kernel が init 20 で1回だけ呼ぶ。
     *
     * @throws LogicException 2回目の呼び出し
     * @throws InvalidSchemaException sanitize が呼び出せない、または WordPress が登録を拒否した場合
     */
    public function registerAll(): void
    {
        if ($this->registered) {
            throw new LogicException('MetaSchemaRegistry::registerAll() must only be called once');
        }

        foreach ($this->entities as $entry) {
            foreach ($entry['schemas']->all() as $definition) {
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

                $registered = match ($entry['objectType']) {
                    ObjectType::Post => register_post_meta($entry['subtype'], $definition->key, $args),
                    ObjectType::Term => register_term_meta($entry['subtype'], $definition->key, $args),
                    ObjectType::User => register_meta('user', $definition->key, $args),
                };

                if ($registered !== true) {
                    throw new InvalidSchemaException("WordPress rejected the meta registration of '{$definition->key}' for {$entry['objectType']->value}:{$entry['subtype']}: {$definition->ownerClass}::{$definition->constantName}");
                }
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

        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $attributes = $constant->getAttributes(MetaSchema::class);

            if ($attributes === []) {
                continue;
            }

            if (!$constant->isPublic()) {
                throw new InvalidSchemaException("#[MetaSchema] requires a public constant: {$reflection->getName()}::{$constant->getName()}");
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

        // #[UseMetaStorage] が無ければ、Kernel が既定のストレージを登録した interface の id で解決する
        if ($attributes === []) {
            return MetaStorageInterface::class;
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
}
