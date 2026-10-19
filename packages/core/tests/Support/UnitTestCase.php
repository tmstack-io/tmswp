<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * ユニット層の共通基底。Brain Monkey と Mockery の検証を全テストに保証する。
 */
abstract class UnitTestCase extends TestCase
{
    use MockeryPHPUnitIntegration;
    use UsesTempDirectories;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDirectories();
        Monkey\tearDown();
        parent::tearDown();
    }
}
