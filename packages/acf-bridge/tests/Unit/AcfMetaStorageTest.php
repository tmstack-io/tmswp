<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\AcfBridge\Exception\AcfNotActiveException;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Tests\Support\UnitTestCase;

final class AcfMetaStorageTest extends UnitTestCase
{
    public function testItDelegatesRawReadsAndWritesToAcf(): void
    {
        Functions\expect('get_field')
            ->once()
            ->with('price', 10, false)
            ->andReturn('1200');
        Functions\expect('update_field')
            ->once()
            ->with('price', 1300, 10)
            ->andReturn(true);
        Functions\expect('delete_field')
            ->once()
            ->with('price', 10)
            ->andReturn(true);

        $storage = new AcfMetaStorage();

        self::assertSame('1200', $storage->get(ObjectType::Post, 10, 'price'));
        self::assertTrue($storage->set(ObjectType::Post, 10, 'price', 1300));
        self::assertTrue($storage->delete(ObjectType::Post, 10, 'price'));
    }

    public function testItUsesAcfObjectIdsForTermsAndUsers(): void
    {
        Functions\expect('get_field')
            ->once()
            ->with('genre_color', 'term_7', false)
            ->andReturn('#111111');
        Functions\expect('update_field')
            ->once()
            ->with('profile_image', 99, 'user_5')
            ->andReturn(true);

        $storage = new AcfMetaStorage();

        self::assertSame('#111111', $storage->get(ObjectType::Term, 7, 'genre_color'));
        self::assertTrue($storage->set(ObjectType::User, 5, 'profile_image', 99));
    }
}

final class AcfMetaStorageInactiveTest extends TestCase
{
    public function testItThrowsWhenAcfIsNotActive(): void
    {
        $this->expectException(AcfNotActiveException::class);
        $this->expectExceptionMessage('requires Advanced Custom Fields (ACF) 6.0+');

        (new AcfMetaStorage())->get(ObjectType::Post, 10, 'price');
    }
}
