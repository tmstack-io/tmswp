<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Exception\LogicException;
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

    public function testAllReturnsTheSameListAsGetForSingleFalseKeys(): void
    {
        $storage = new ArrayMetaStorage();
        $bag = $this->bag($storage);
        $bag->set('related_id', [12, 34, 56]);

        self::assertSame([12, 34, 56], $bag->get('related_id'));
        self::assertSame($bag->get('related_id'), $bag->all()['related_id']);
    }

    public function testSetRejectsNullForDeclaredAndUndeclaredKeys(): void
    {
        $storage = new ArrayMetaStorage();
        $bag = $this->bag($storage);

        foreach (['code', 'undeclared'] as $key) {
            try {
                $bag->set($key, null);
                self::fail("null を set できてしまいました: {$key}");
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString("meta '{$key}' cannot be set to null; use delete()", $exception->getMessage());
            }

            self::assertFalse($bag->has($key));
        }
    }

    public function testSetRejectsValuesThatDoNotMatchTheSchemaType(): void
    {
        $storage = new ArrayMetaStorage();
        $bag = $this->bag($storage);

        $this->assertRejectedWrite($bag, 'price', '1200', "meta 'price' expects integer, got string");
        $this->assertRejectedWrite($bag, 'code', ['A-001'], "meta 'code' expects string, got array");
        $this->assertRejectedWrite($bag, 'related_id', [12, '34'], "meta 'related_id' expects integer, got string");

        // 未宣言のキーは型を問わない(WP 組み込みのメタや他プラグインのキーを書く経路)
        self::assertTrue($bag->set('undeclared', ['any' => 'shape']));
        self::assertSame(['any' => 'shape'], $bag->get('undeclared'));
    }

    /**
     * sanitize を宣言したキーは、スキーマを WordPress に登録する前には書けない(WordPress が sanitize を適用しないため)。
     * sanitize の無いキーの書き込み・読み取り・delete() は登録の前でも使え、登録の後は sanitize のキーにも書ける。
     */
    public function testSanitizedKeyCannotBeWrittenBeforeRegistrationWithWordPress(): void
    {
        $storage = new ArrayMetaStorage();
        $registered = false;
        $bag = new MetaBag(
            MetaBagFixtureEntity::class,
            ObjectType::Post,
            10,
            $storage,
            MetaSchemaSet::fromList([
                new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_CODE', 'code', MetaValueType::String, null, true, false, '', null),
                new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_NOTE', 'note', MetaValueType::String, null, true, false, '', 'trim'),
            ]),
            static function () use (&$registered): bool {
                return $registered;
            },
        );

        try {
            $bag->set('note', 'before');
            self::fail('LogicException was not thrown');
        } catch (LogicException $exception) {
            self::assertSame(
                "meta 'note' of " . MetaBagFixtureEntity::class . ' declares sanitize and cannot be written before its schema is registered with WordPress; WordPress applies sanitize only after the registration at init priority 20, so write it after that',
                $exception->getMessage(),
            );
        }

        self::assertFalse($bag->has('note'));
        self::assertTrue($bag->set('code', 'A-1'));
        self::assertSame('A-1', $bag->string('code'));
        self::assertTrue($bag->delete('code'));
        self::assertSame('', $bag->string('note'));

        $registered = true;

        self::assertTrue($bag->set('note', 'after'));
        self::assertSame('after', $bag->string('note'));
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

    /** @param MetaBag<MetaBagFixtureEntity> $bag */
    private function assertRejectedWrite(MetaBag $bag, string $key, mixed $value, string $message): void
    {
        try {
            $bag->set($key, $value);
            self::fail("スキーマの型に合わない値を set できてしまいました: {$key}");
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertFalse($bag->has($key));
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
                new MetaSchemaDefinition(MetaBagFixtureEntity::class, 'META_RELATED_IDS', 'related_id', MetaValueType::Integer, null, false, false, '', null),
            ]),
            static fn(): bool => true,
        );
    }
}

final class MetaBagFixtureEntity {}

/**
 * MetaStorageInterface の契約(書き込みは変えたかどうかを返す)に従う、プロセス内配列への保存実装。
 */
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
        $storageKey = $this->storageKey($type, $objectId, $key);
        $changed = !\array_key_exists($storageKey, $this->values) || $this->values[$storageKey] !== $value;
        $this->values[$storageKey] = $value;

        return $changed;
    }

    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        $storageKey = $this->storageKey($type, $objectId, $key);
        $changed = ($this->rows[$storageKey] ?? []) !== $values;
        $this->rows[$storageKey] = $values;

        return $changed;
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        $existed = $this->has($type, $objectId, $key);
        unset($this->values[$this->storageKey($type, $objectId, $key)], $this->rows[$this->storageKey($type, $objectId, $key)]);

        return $existed;
    }

    private function storageKey(ObjectType $type, int $objectId, string $key): string
    {
        return $type->value . ':' . $objectId . ':' . $key;
    }
}
