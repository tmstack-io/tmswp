<?php

declare(strict_types=1);

namespace TmswpStarterTheme\Tests\Unit\Hook;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use TmswpStarterTheme\Hook\ThemeSetupHook;

/**
 * フックのメソッドを直接呼び、WordPressの関数の呼び出しと戻り値を確かめる例。WordPressの関数はBrain Monkeyで差し替える。
 */
final class ThemeSetupHookTest extends TestCase
{
    // Brain Monkeyの期待(expect)をアサーションとして数え、満たされなければテストを失敗にする
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testSetupThemeAddsThemeSupports(): void
    {
        Functions\expect('add_theme_support')->once()->with('post-thumbnails');
        Functions\expect('add_theme_support')->once()->with('title-tag');
        Functions\expect('add_theme_support')->once()->with('html5', ['search-form', 'gallery', 'caption']);

        (new ThemeSetupHook())->setupTheme();
    }

    public function testExcerptMoreReplacesTheDefaultSuffix(): void
    {
        self::assertSame('...', (new ThemeSetupHook())->excerptMore(' [&hellip;]'));
    }
}
