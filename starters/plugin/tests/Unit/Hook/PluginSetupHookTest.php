<?php

declare(strict_types=1);

namespace TmswpStarterPlugin\Tests\Unit\Hook;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use TmswpStarterPlugin\Hook\PluginSetupHook;

/**
 * フックのメソッドを直接呼んで確かめる例。WordPressの関数はBrain Monkeyで差し替える。
 *
 * PluginSetupHookは雛形で、まだ処理を持たない。このテストはBrain Monkeyの準備の形を示す出発点で、register()が
 * 未定義のWordPressの関数を呼ぶと失敗するが、add_action()等によるフックの追加は検出しない。register()やメソッドに
 * 処理を足したら、このテストを、足した処理を確かめるテスト(WordPressの関数の呼び出しを`Brain\Monkey\Functions\expect()`で、
 * 戻り値を`assertSame()`等で確かめる)に書き換える。
 */
final class PluginSetupHookTest extends TestCase
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

    public function testRegisterRunsWithoutWordPress(): void
    {
        $this->expectNotToPerformAssertions();

        (new PluginSetupHook())->register();
    }
}
