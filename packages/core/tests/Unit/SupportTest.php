<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use Generator;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use Stringable;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Support\Arr;
use TMSWP\Core\Support\Html;
use TMSWP\Core\Support\Str;
use TMSWP\Testing\UnitTestCase;

final class SupportTest extends UnitTestCase
{
    public function testArrDotNotationAndHelpers(): void
    {
        $array = [
            'user' => ['address' => ['city' => 'Tokyo'], 'name' => null],
            10 => 'ten',
        ];

        self::assertSame('Tokyo', Arr::get($array, 'user.address.city'));
        self::assertNull(Arr::get($array, 'user.name'));
        self::assertSame('missing', Arr::get($array, 'user.address.zip', 'missing'));
        self::assertTrue(Arr::has($array, 'user.name'));
        self::assertTrue(Arr::has($array, 10));

        Arr::set($array, 'user.address.zip', '100-0001');
        Arr::set($array, 'user.name.first', 'Taro');

        self::assertSame('100-0001', Arr::get($array, 'user.address.zip'));
        self::assertSame('Taro', Arr::get($array, 'user.name.first'));
        self::assertSame(['user' => $array['user']], Arr::only($array, ['user', 'missing']));
        self::assertSame('ten', Arr::first($array, static fn(mixed $value): bool => $value === 'ten'));
        self::assertSame([], Arr::wrap(null));
        self::assertSame(['x'], Arr::wrap('x'));
    }

    public function testStrReplaceStringifyAndCaseHelpers(): void
    {
        self::assertSame('Hello Taro {nested}', Str::replace('Hello {name}', ['name' => 'Taro {nested}', 'nested' => 'ignored']));
        self::assertSame('あいう…', Str::truncate('あいうえお', 3));
        self::assertSame("a\nb\nc", Str::convertEol("a\r\nb\rc"));
        self::assertSame("a\r\nb\r\nc\r\nd", Str::convertEol("a\r\nb\rc\nd", "\r\n"));
        self::assertSame('a<br>b<br>c', Str::convertEol("a\r\nb\rc", '<br>'));
        self::assertSame('product_post', Str::snake('ProductPost'));
        self::assertSame('product-post', Str::kebab('ProductPost'));
        self::assertSame('ProductPost', Str::pascal('product-post'));
        self::assertSame('productPost', Str::camel('product_post'));

        self::assertSame('', Str::stringify(null));
        self::assertSame('true', Str::stringify(true));
        self::assertSame('draft', Str::stringify(SupportBackedEnum::Draft));
        self::assertSame('Published', Str::stringify(SupportUnitEnum::Published));
        self::assertSame('2026-06-13T00:00:00+00:00', Str::stringify(new DateTimeImmutable('2026-06-13 00:00:00 UTC')));
        self::assertSame('a, b', Str::stringify($this->generator()));
        self::assertSame('a, b', Str::stringify(['a', ['b']]));
        self::assertSame('stringable', Str::stringify(new SupportStringable()));
        self::assertSame('{"key":"value"}', Str::stringify(new SupportJsonSerializable()));
        self::assertSame('(Closure)', Str::stringify(static fn(): null => null));
    }

    public function testTruncateRejectsInvalidLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::truncate('abc', 0);
    }

    public function testHtmlAttributesAndClasses(): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));

        self::assertSame('', Html::attributes([]));
        self::assertSame(
            ' class="btn is-active" disabled aria-pressed="false" value="" data-ids="1 2" checked title="&quot;quoted&quot;"',
            Html::attributes([
                'class' => ['btn', 'is-active' => true, 'is-hidden' => false],
                'disabled' => true,
                'hidden' => false,
                'aria-pressed' => false,
                'value' => '',
                'data-ids' => [1, 2],
                'checked',
                'title' => '"quoted"',
                'nullable' => null,
            ]),
        );
        self::assertSame('btn btn-lg is-active', Html::classes('btn btn-lg', ['btn', 'is-active' => true]));
    }

    public function testHtmlEscapesUrlAttributesWithEscUrl(): void
    {
        $this->stubUrlEscaping();

        self::assertSame(
            ' href="https://example.com/?a=1&amp;b=2" SRC="/img/a.png" action="" ping="https://a.example/p https://b.example/p" title="javascript:void(0)"',
            Html::attributes([
                'href' => 'https://example.com/?a=1&b=2',
                'SRC' => '/img/a.png',
                'action' => '',
                'ping' => ['https://a.example/p', 'https://b.example/p'],
                'title' => 'javascript:void(0)',
            ]),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function disallowedUrlProvider(): iterable
    {
        yield 'href' => [['href' => 'javascript:alert(1)']];
        yield 'uppercase name' => [['FormAction' => 'javascript:alert(1)']];
        yield 'url in list' => [['ping' => 'https://a.example/p javascript:alert(1)']];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('disallowedUrlProvider')]
    public function testHtmlRejectsUrlWithDisallowedProtocol(array $attributes): void
    {
        $this->stubUrlEscaping();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed protocol: javascript:alert(1)');

        Html::attributes($attributes);
    }

    public function testHtmlRejectsInvalidAttributeName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Html::attributes(['bad name' => 'x']);
    }

    /** esc_url() の代わり。WordPress と同じく、許可されないプロトコルの URL を空文字にする */
    private function stubUrlEscaping(): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_url')->alias(static fn(string $url): string => preg_match('/\A\s*javascript:/i', $url) === 1 ? '' : htmlspecialchars($url, ENT_QUOTES, 'UTF-8'));
    }

    /** @return Generator<int, string> */
    private function generator(): Generator
    {
        yield 'a';
        yield 'b';
    }
}

enum SupportBackedEnum: string
{
    case Draft = 'draft';
}

enum SupportUnitEnum
{
    case Published;
}

final class SupportStringable implements Stringable
{
    public function __toString(): string
    {
        return 'stringable';
    }
}

final class SupportJsonSerializable implements JsonSerializable
{
    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['key' => 'value'];
    }
}
