<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Meta\MetaBag;
use TMSWP\Core\Meta\MetaSchemaDefinition;
use TMSWP\Core\Meta\MetaSchemaSet;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class MetaBagTest extends UnitTestCase
{
    public function testSchemaDefaultAndTypedAccessorsResolveValuesInOrder(): void
    {
        $storage = new ArrayMetaStorage();
        $bag = $this->bag($storage);

        self::assertFalse($bag->has('price'));
        self::assertSame(100, $bag->int('price'));
        self::assertSame(250, $bag->int('price', 250));

        $storage->set(ObjectType::Post, 10, 'price', '1200');

        self::assertTrue($bag->has('price'));
        self::assertSame(1200, $bag->int('price', 250));
    }

    public function testSingleFalseUsesAllRowsAndRequiresListOnSet(): void
    {
        $storage = new ArrayMetaStorage();
        $bag = $this->bag($storage);

        self::assertTrue($bag->set('related_id', [12, 34]));
        self::assertSame([12, 34], $bag->list('related_id'));
        self::assertSame([12, 34], $bag->get('related_id'));

        $this->expectException(InvalidArgumentException::class);

        $bag->set('related_id', '12');
    }

    public function testAllReturnsDeclaredKeysOnlyWithSchemaDefaults(): void
    {
        $storage = new ArrayMetaStorage();
        $bag = $this->bag($storage);
        $storage->set(ObjectType::Post, 10, 'code', 'A-001');
        $storage->set(ObjectType::Post, 10, 'external', 'ignored');

        self::assertSame([
            'price' => 100,
            'code' => 'A-001',
            'related_id' => null,
        ], $bag->all());
    }

    public function testSchemaDefinitionRejectsInvalidDeclaration(): void
    {
        $this->expectException(InvalidSchemaException::class);

        MetaSchemaDefinition::fromAttribute(
            MetaBagFixtureEntity::class,
            'META_PRICE',
            'price',
            new MetaSchema(type: MetaValueType::Integer, default: 'invalid'),
        );
    }

    /** @return MetaBag<MetaBagFixtureEntity> */
    private function bag(ArrayMetaStorage $storage): MetaBag
    {
        return new MetaBag(
            MetaBagFixtureEntity::class,
            ObjectType::Post,
            10,
            $storage,
            MetaSchemaSet::fromList([
                new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_PRICE', 'price', MetaValueType::Integer, 100, true, true, '', null),
                new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_CODE', 'code', MetaValueType::String, null, true, false, '', null),
                new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_RELATED_IDS', 'related_id', MetaValueType::Array, null, false, false, '', null),
            ]),
        );
    }
}

final class MetaBagFixtureEntity {}

final class ArrayMetaStorage implements MetaStorageInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, list<mixed>> */
    private array $rows = [];

    public function has(ObjectType $type, int $objectId, string $key): bool
    {
        return \array_key_exists($this->storageKey($type, $objectId, $key), $this->values)
            || \array_key_exists($this->storageKey($type, $objectId, $key), $this->rows);
    }

    public function get(ObjectType $type, int $objectId, string $key): mixed
    {
        $storageKey = $this->storageKey($type, $objectId, $key);

        if (!\array_key_exists($storageKey, $this->values)) {
            return null;
        }

        return $this->values[$storageKey];
    }

    public function getAll(ObjectType $type, int $objectId, string $key): array
    {
        $storageKey = $this->storageKey($type, $objectId, $key);

        if (!\array_key_exists($storageKey, $this->rows)) {
            return [];
        }

        return $this->rows[$storageKey];
    }

    public function getMultiple(ObjectType $type, int $objectId, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $storageKey = $this->storageKey($type, $objectId, $key);

            if (\array_key_exists($storageKey, $this->values)) {
                $values[$key] = $this->values[$storageKey];
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
        $this->rows[$this->storageKey($type, $objectId, $key)] = $values;

        return true;
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        unset($this->values[$this->storageKey($type, $objectId, $key)], $this->rows[$this->storageKey($type, $objectId, $key)]);

        return true;
    }

    private function storageKey(ObjectType $type, int $objectId, string $key): string
    {
        return $type->value . ':' . $objectId . ':' . $key;
    }
}
