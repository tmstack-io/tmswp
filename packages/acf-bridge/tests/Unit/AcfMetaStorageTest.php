<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\AcfBridge\Exception\AcfNotActiveException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Tests\Support\UnitTestCase;

/**
 * ACFを読み込まないユニットテストの環境で、AcfMetaStorageの組み立てとACFの有無の検査を確かめる。ACFへの委譲の振る舞いは、
 * 実物の無料版ACFの統合テスト(AcfMetaStorageContractTest・AcfBridgeIntegrationTest)で確かめる。
 */
final class AcfMetaStorageTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        parent::tearDown();
    }

    /**
     * ACFが有効でなければ(get_fieldが無ければ)、全メソッドがAcfNotActiveExceptionになる。#[UseMetaStorage]の解決と
     * 同じく、AcfMetaStorageはKernelのContainerが、そのKernelを渡して組み立てる。
     *
     * @param Closure(AcfMetaStorage): mixed $call
     */
    #[DataProvider('storageCalls')]
    public function testEveryMethodRequiresActiveAcf(string $method, Closure $call): void
    {
        $appDir = $this->createTempDirectory('acf-meta-storage') . '/app';
        self::assertTrue(mkdir($appDir));
        $kernel = Kernel::boot(KernelConfig::theme($appDir, 'Example\Tests\AcfMetaStorage' . uniqid(), true));
        $storage = $kernel->container()->get(AcfMetaStorage::class);
        self::assertInstanceOf(AcfMetaStorage::class, $storage);
        self::assertFalse(\function_exists('get_field'));

        $this->expectException(AcfNotActiveException::class);
        $this->expectExceptionMessage(AcfMetaStorage::class . "::{$method} requires Advanced Custom Fields (ACF) 6.2+");

        $call($storage);
    }

    /** @return iterable<string, array{string, Closure(AcfMetaStorage): mixed}> */
    public static function storageCalls(): iterable
    {
        yield 'has' => ['has', static fn(AcfMetaStorage $storage): bool => $storage->has(ObjectType::Post, 10, 'price')];
        yield 'get' => ['get', static fn(AcfMetaStorage $storage): mixed => $storage->get(ObjectType::Post, 10, 'price')];
        yield 'getAll' => ['getAll', static fn(AcfMetaStorage $storage): array => $storage->getAll(ObjectType::Post, 10, 'price')];
        yield 'getMultiple' => ['getMultiple', static fn(AcfMetaStorage $storage): array => $storage->getMultiple(ObjectType::Post, 10, ['price'])];
        yield 'set' => ['set', static fn(AcfMetaStorage $storage): bool => $storage->set(ObjectType::Post, 10, 'price', '1200')];
        yield 'setAll' => ['setAll', static fn(AcfMetaStorage $storage): bool => $storage->setAll(ObjectType::Post, 10, 'price', ['1200'])];
        yield 'delete' => ['delete', static fn(AcfMetaStorage $storage): bool => $storage->delete(ObjectType::Post, 10, 'price')];
    }
}
