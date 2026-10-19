<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Meta\MetaSchemaDefinition;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class MetaSchemaDefinitionTest extends UnitTestCase
{
    #[DataProvider('invalidSchemaProvider')]
    public function testItRejectsInvalidDeclarations(string $key, MetaSchema $schema): void
    {
        $this->expectException(InvalidSchemaException::class);

        MetaSchemaDefinition::fromAttribute(MetaSchemaDefinitionFixture::class, 'META_KEY', $key, $schema);
    }

    /** @return iterable<string, array{string, MetaSchema}> */
    public static function invalidSchemaProvider(): iterable
    {
        yield 'empty key' => ['', self::schema()];
        yield 'default type mismatch' => ['price', self::schema(type: MetaValueType::Integer, default: '1200')];
        yield 'single false with default' => ['related_id', self::schema(default: [], single: false)];
        yield 'empty sanitize string' => ['code', self::schema(sanitize: '')];
        yield 'invalid sanitize tuple' => ['code', self::schema(sanitize: [MetaSchemaDefinitionFixture::class])];
        yield 'missing sanitize class' => ['code', self::schema(sanitize: ['Missing\\MetaSchemaSanitizer', 'sanitize'])];
    }

    /**
     * @param bool|array<string, mixed> $showInRest
     */
    private static function schema(
        MetaValueType $type = MetaValueType::String,
        mixed $default = null,
        bool $single = true,
        bool|array $showInRest = false,
        string $description = '',
        mixed $sanitize = null,
    ): MetaSchema {
        $schema = (new ReflectionClass(MetaSchema::class))->newInstanceArgs([
            $type,
            $default,
            $single,
            $showInRest,
            $description,
            $sanitize,
        ]);

        if (!$schema instanceof MetaSchema) {
            throw new LogicException('MetaSchema fixture を生成できません');
        }

        return $schema;
    }
}

final class MetaSchemaDefinitionFixture {}
