<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * ユニット層の共通基底。Brain Monkey と Mockery の検証を全テストに保証する。
 *
 * FW のソースは翻訳関数(`__()` 等)を常に呼ぶため、翻訳関数はソース文字列をそのまま返すスタブにする。
 */
abstract class UnitTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;
    use UsesTempDirectories;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\stubTranslationFunctions();
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDirectories();
        Monkey\tearDown();
        parent::tearDown();
    }
}
