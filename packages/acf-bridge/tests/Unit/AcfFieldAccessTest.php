<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use TMSWP\AcfBridge\AcfFieldAccess;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class AcfFieldAccessTest extends UnitTestCase
{
    public function testItKeepsAcfIdWithoutAccessingAcfRuntime(): void
    {
        $access = AcfFieldAccessFixture::make('term_10');

        self::assertSame('term_10', $access->acfId());
    }

    public function testItNormalizesUnsetValues(): void
    {
        Functions\when('get_field')->alias(static fn(string $name, int|string $postId, bool $format): mixed => match ($name) {
            'empty_string' => false,
            'empty_number' => '',
            'empty_list' => null,
            'empty_array' => [],
            'truthy' => '1',
            default => null,
        });

        $access = AcfFieldAccessFixture::make(10);

        self::assertSame('', $access->stringValue('empty_string'));
        self::assertNull($access->numberValue('empty_number'));
        self::assertSame([], $access->listValue('empty_list'));
        self::assertNull($access->nullableArrayValue('empty_array'));
        self::assertTrue($access->boolValue('truthy'));
    }

    public function testItParsesRawDateWithWordPressTimezone(): void
    {
        Functions\when('get_field')->alias(static fn(string $name, int|string $postId, bool $format): mixed => $format ? '' : '20260613');
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('Asia/Tokyo'));

        $date = AcfFieldAccessFixture::make(10)->dateValue('published_at', 'Ymd');

        self::assertSame('2026-06-13 00:00:00 +09:00', $date?->format('Y-m-d H:i:s P'));
    }
}

final class AcfFieldAccessFixture
{
    use AcfFieldAccess;

    public static function make(int|string $acfId): self
    {
        return new self($acfId);
    }

    public function stringValue(string $name): string
    {
        return $this->acfString($name);
    }

    public function numberValue(string $name): int|float|null
    {
        return $this->acfNumber($name);
    }

    /** @return list<mixed> */
    public function listValue(string $name): array
    {
        return $this->acfList($name);
    }

    /** @return array<string, mixed>|null */
    public function nullableArrayValue(string $name): ?array
    {
        return $this->acfNullableArray($name);
    }

    public function boolValue(string $name): bool
    {
        return $this->acfBool($name);
    }

    public function dateValue(string $name, string $rawFormat): ?DateTimeImmutable
    {
        return $this->acfDateTime($name, $rawFormat);
    }
}
