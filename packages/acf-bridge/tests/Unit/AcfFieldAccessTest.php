<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\AcfBridge\AcfFieldAccess;
use TMSWP\AcfBridge\Exception\AcfNotActiveException;
use TMSWP\Core\Tests\Support\UnitTestCase;

/**
 * ACFを読み込まないユニットテストの環境で、AcfFieldAccessのACFに触れない部分を確かめる。値の正規化・形の不一致・
 * has()・日時の往復は、実物の無料版ACFの統合テスト(AcfBridgeIntegrationTest)で確かめる。
 */
final class AcfFieldAccessTest extends UnitTestCase
{
    public function testItKeepsAcfIdWithoutAccessingAcfRuntime(): void
    {
        $access = AcfFieldAccessFixture::make('term_10');

        self::assertSame('term_10', $access->acfId());
    }

    /**
     * 値に触れる操作は、ACFが有効でなければ(get_fieldが無ければ)AcfNotActiveExceptionになる(docs/09 §10)。
     *
     * @param Closure(AcfFieldAccessFixture): mixed $call
     */
    #[DataProvider('valueCalls')]
    public function testValueAccessRequiresActiveAcf(string $method, Closure $call): void
    {
        self::assertFalse(\function_exists('get_field'));

        $this->expectException(AcfNotActiveException::class);
        $this->expectExceptionMessage(AcfFieldAccessFixture::class . "::{$method} requires Advanced Custom Fields (ACF) 6.2+");

        $call(AcfFieldAccessFixture::make(10));
    }

    /** @return iterable<string, array{string, Closure(AcfFieldAccessFixture): mixed}> */
    public static function valueCalls(): iterable
    {
        yield 'has' => ['has', static fn(AcfFieldAccessFixture $access): bool => $access->has('price')];
        yield 'value' => ['value', static fn(AcfFieldAccessFixture $access): mixed => $access->value('price')];
        yield 'rawValue' => ['rawValue', static fn(AcfFieldAccessFixture $access): mixed => $access->rawValue('price')];
        yield 'update' => ['update', static fn(AcfFieldAccessFixture $access): bool => $access->update('price', '1200')];
        yield 'delete' => ['delete', static fn(AcfFieldAccessFixture $access): bool => $access->delete('price')];
        yield 'acfString' => ['acfString', static fn(AcfFieldAccessFixture $access): string => $access->stringValue('price')];
        yield 'acfUpdateDate' => ['acfUpdateDate', static fn(AcfFieldAccessFixture $access): null => $access->setDate('published_at', '20260613')];
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

    public function setDate(string $name, string $value): null
    {
        $this->acfUpdateDate($name, $value, 'Ymd');

        return null;
    }
}
