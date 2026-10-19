<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Attribute\WithMetaRevisions;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class MetaSchemaRegistryTest extends UnitTestCase
{
    public function testItAllowsSameMetaKeyAcrossDifferentSubtypesAndRejectsDuplicateScope(): void
    {
        $registry = new MetaSchemaRegistry(new Container());
        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');
        $registry->registerEntity(RegistryOtherProductPost::class, ObjectType::Post, 'registry_other');

        self::assertSame(ObjectType::Post, $registry->objectTypeOf(RegistryProductPost::class));
        self::assertSame(ObjectType::Post, $registry->objectTypeOf(RegistryOtherProductPost::class));

        $this->expectException(DuplicateMetaKeyException::class);

        $registry->registerEntity(RegistryDuplicateProductPost::class, ObjectType::Post, 'registry_product');
    }

    public function testExternallyManagedKeysCannotBeDeclaredByPhpSchema(): void
    {
        $registry = new MetaSchemaRegistry(new Container());
        $registry->markExternallyManaged(ObjectType::Post, 'registry_product', 'shared_key', 'test');

        $this->expectException(DuplicateMetaKeyException::class);

        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');
    }

    public function testExternallyManagedAllSubtypeCoversConcreteSubtypes(): void
    {
        $registry = new MetaSchemaRegistry(new Container());
        $registry->markExternallyManaged(ObjectType::Post, 'all', 'shared_key', 'test');

        self::assertTrue($registry->isExternallyManaged(ObjectType::Post, 'registry_product', 'shared_key'));

        $this->expectException(DuplicateMetaKeyException::class);

        $registry->registerEntity(RegistryProductPost::class, ObjectType::Post, 'registry_product');
    }

    public function testUseMetaStorageControlsBagStorage(): void
    {
        $container = new Container();
        $registry = new MetaSchemaRegistry($container);
        $registry->registerEntity(RegistryCustomStoragePost::class, ObjectType::Post, 'registry_custom');

        $bag = $registry->bagFor(RegistryCustomStoragePost::class, 10);
        self::assertTrue($bag->set(RegistryCustomStoragePost::META_CUSTOM, 'stored'));
        self::assertSame('stored', $bag->string(RegistryCustomStoragePost::META_CUSTOM));

        $storage = $container->get(RegistryArrayMetaStorage::class);
        self::assertInstanceOf(RegistryArrayMetaStorage::class, $storage);
        self::assertSame('stored', $storage->get(ObjectType::Post, 10, 'custom_key'));
    }

    public function testItRejectsInvalidStorageClass(): void
    {
        $registry = new MetaSchemaRegistry(new Container());
        require_once __DIR__ . '/../fixtures/meta/invalid-storage-entity.php';
        $entityClass = 'TMSWP\\Core\\Tests\\Fixtures\\Meta\\InvalidStoragePost';

        if (!is_subclass_of($entityClass, PostType::class)) {
            self::fail("fixture entity class が見つかりません: {$entityClass}");
        }

        $this->expectException(InvalidSchemaException::class);

        $registry->registerEntity($entityClass, ObjectType::Post, 'registry_invalid_storage');
    }

    public function testItRejectsMissingSanitizeCallableWhenRegisteringMeta(): void
    {
        $registry = new MetaSchemaRegistry(new Container());
        $registry->registerEntity(RegistryMissingSanitizePost::class, ObjectType::Post, 'registry_missing_sanitize');

        $this->expectException(InvalidSchemaException::class);

        $registry->registerAll();
    }

    public function testWithMetaRevisionsRequiresPostTypeWithRevisionSupport(): void
    {
        $registry = new MetaSchemaRegistry(new Container());

        $this->expectException(InvalidSchemaException::class);

        $registry->registerEntity(RegistryRevisionlessPost::class, ObjectType::Post, 'registry_revisionless');
    }

    public function testWithMetaRevisionsIsOnlyAllowedForPostObjectType(): void
    {
        $registry = new MetaSchemaRegistry(new Container());

        $this->expectException(InvalidSchemaException::class);

        $registry->registerEntity(RegistryRevisionedPost::class, ObjectType::Term, 'registry_term');
    }
}

#[AsPostType]
final class RegistryProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_SHARED = 'shared_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_product');
    }
}

#[AsPostType]
final class RegistryOtherProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_SHARED = 'shared_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_other');
    }
}

#[AsPostType]
final class RegistryDuplicateProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_SHARED = 'shared_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_duplicate');
    }
}

#[AsPostType]
#[UseMetaStorage(RegistryArrayMetaStorage::class)]
final class RegistryCustomStoragePost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_CUSTOM = 'custom_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_custom');
    }
}

#[AsPostType]
final class RegistryMissingSanitizePost extends PostType
{
    #[MetaSchema(type: MetaValueType::String, sanitize: [self::class, 'missingSanitize'])]
    public const META_CODE = 'code';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_sanitize');
    }
}

#[AsPostType]
#[WithMetaRevisions]
final class RegistryRevisionlessPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_revlss')->supports('title');
    }
}

#[AsPostType]
#[WithMetaRevisions]
final class RegistryRevisionedPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_rev')->supports('title', 'revisions');
    }
}

final class RegistryArrayMetaStorage implements MetaStorageInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function has(ObjectType $type, int $objectId, string $key): bool
    {
        return \array_key_exists($this->storageKey($type, $objectId, $key), $this->values);
    }

    public function get(ObjectType $type, int $objectId, string $key): mixed
    {
        return $this->values[$this->storageKey($type, $objectId, $key)] ?? null;
    }

    public function getAll(ObjectType $type, int $objectId, string $key): array
    {
        $value = $this->get($type, $objectId, $key);

        return \is_array($value) && array_is_list($value) ? $value : [];
    }

    public function getMultiple(ObjectType $type, int $objectId, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            if ($this->has($type, $objectId, $key)) {
                $values[$key] = $this->get($type, $objectId, $key);
            }
        }

        return $values;
    }

    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool
    {
        $this->values[$this->storageKey($type, $objectId, $key)] = $value;

        return true;
    }

    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        return $this->set($type, $objectId, $key, $values);
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        unset($this->values[$this->storageKey($type, $objectId, $key)]);

        return true;
    }

    private function storageKey(ObjectType $type, int $objectId, string $key): string
    {
        return $type->value . ':' . $objectId . ':' . $key;
    }
}
