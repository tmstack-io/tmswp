<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use stdClass;
use Stringable;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ValueCaster;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class ValueCasterTest extends UnitTestCase
{
    public function testItCastsScalarAccessors(): void
    {
        self::assertSame(12, ValueCaster::toInt('12.8'));
        self::assertSame(1, ValueCaster::toInt(true));
        self::assertSame(0, ValueCaster::toInt('abc'));

        self::assertSame(12.5, ValueCaster::toFloat('12.5'));
        self::assertSame(1.0, ValueCaster::toFloat(true));
        self::assertSame(0.0, ValueCaster::toFloat('abc'));

        self::assertFalse(ValueCaster::toBool(false));
        self::assertFalse(ValueCaster::toBool(null));
        self::assertFalse(ValueCaster::toBool(''));
        self::assertFalse(ValueCaster::toBool('0'));
        self::assertFalse(ValueCaster::toBool(0));
        self::assertFalse(ValueCaster::toBool(0.0));
        self::assertFalse(ValueCaster::toBool([]));
        self::assertFalse(ValueCaster::toBool('false'));
        self::assertTrue(ValueCaster::toBool('true'));

        self::assertSame('value', ValueCaster::toString('value'));
        self::assertSame('12', ValueCaster::toString(12));
        self::assertSame('1', ValueCaster::toString(true));
        self::assertSame('', ValueCaster::toString(false));
        self::assertSame('stringable', ValueCaster::toString(new ValueCasterStringable()));
        self::assertSame('', ValueCaster::toString([]));
    }

    public function testItCastsArrayAccessors(): void
    {
        self::assertSame(['a', 'b'], ValueCaster::toList(['first' => 'a', 'second' => 'b']));
        self::assertSame([], ValueCaster::toList(null));
        self::assertSame(['value'], ValueCaster::toList('value'));

        self::assertSame(['a' => 1], ValueCaster::toJsonArray(['a' => 1]));
        self::assertSame(['a' => 1], ValueCaster::toJsonArray('{"a":1}'));
        self::assertSame([], ValueCaster::toJsonArray('invalid'));
        self::assertSame([], ValueCaster::toJsonArray(1));
    }

    public function testItValidatesSchemaDefaultCompatibility(): void
    {
        self::assertTrue(ValueCaster::accepts(MetaValueType::String, 'value'));
        self::assertTrue(ValueCaster::accepts(MetaValueType::String, 123));
        self::assertTrue(ValueCaster::accepts(MetaValueType::String, new ValueCasterStringable()));
        self::assertFalse(ValueCaster::accepts(MetaValueType::String, []));

        self::assertTrue(ValueCaster::accepts(MetaValueType::Boolean, false));
        self::assertFalse(ValueCaster::accepts(MetaValueType::Boolean, 0));

        self::assertTrue(ValueCaster::accepts(MetaValueType::Integer, 1));
        self::assertFalse(ValueCaster::accepts(MetaValueType::Integer, 1.0));

        self::assertTrue(ValueCaster::accepts(MetaValueType::Number, 1));
        self::assertTrue(ValueCaster::accepts(MetaValueType::Number, 1.5));
        self::assertFalse(ValueCaster::accepts(MetaValueType::Number, '1.5'));

        self::assertTrue(ValueCaster::accepts(MetaValueType::Array, []));
        self::assertFalse(ValueCaster::accepts(MetaValueType::Array, '[]'));

        self::assertTrue(ValueCaster::accepts(MetaValueType::Object, ['key' => 'value']));
        self::assertFalse(ValueCaster::accepts(MetaValueType::Object, new stdClass()));
    }
}

final class ValueCasterStringable implements Stringable
{
    public function __toString(): string
    {
        return 'stringable';
    }
}
