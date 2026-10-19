<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpMetaStorage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Tests\Support\IntegrationTestCase;
use WP_Post;

final class MetaIntegrationTest extends IntegrationTestCase
{
    private const PRICE = 'tmswp_price';

    private const CODES = 'tmswp_codes';

    private const CUSTOM = 'tmswp_custom';

    public function testMetaSchemaIsRegisteredOnInit(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $registered = get_registered_meta_keys('post', 'tmswp_meta_post');

        self::assertArrayHasKey(self::PRICE, $registered);
        self::assertSame('integer', $registered[self::PRICE]['type']);
        self::assertSame(1200, $registered[self::PRICE]['default']);
        self::assertTrue($registered[self::PRICE]['single']);
        self::assertTrue($registered[self::PRICE]['show_in_rest']);
        self::assertSame('価格', $registered[self::PRICE]['description']);
        self::assertSame([$postClass, 'sanitizePrice'], $registered[self::PRICE]['sanitize_callback']);
        self::assertTrue($registered[self::PRICE]['revisions_enabled'] ?? false);
        self::assertArrayHasKey(self::CODES, $registered);
        self::assertFalse($registered[self::CODES]['single']);
    }

    public function testMetaBagReadsDefaultsAndWritesThroughWpMetaStorage(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $postId = self::createPost('tmswp_meta_post');
        $bag = $postClass::meta($postId);
        $storage = new WpMetaStorage();

        self::assertNull($storage->get(ObjectType::Post, $postId, self::PRICE));
        self::assertSame([], $storage->getAll(ObjectType::Post, $postId, self::CODES));
        self::assertSame([], $storage->getMultiple(ObjectType::Post, $postId, [self::PRICE, self::CODES]));
        self::assertSame(1200, $bag->int(self::PRICE));
        self::assertTrue($bag->set(self::PRICE, 2500));
        self::assertSame(2500, $bag->int(self::PRICE));
        self::assertTrue($bag->set(self::CODES, ['alpha', 'beta']));
        self::assertSame(['alpha', 'beta'], $bag->list(self::CODES));

        $storedValues = $storage->getMultiple(ObjectType::Post, $postId, [self::PRICE, self::CODES]);
        self::assertArrayHasKey(self::PRICE, $storedValues);
        self::assertArrayHasKey(self::CODES, $storedValues);
        self::assertSame('alpha', $storedValues[self::CODES]);
    }

    public function testWpMetaStorageKeepsStoredNullFalseAndEmptyValues(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage();

        self::assertTrue($storage->set(ObjectType::Post, $postId, 'tmswp_null', null));
        self::assertTrue($storage->set(ObjectType::Post, $postId, 'tmswp_false', false));
        self::assertTrue($storage->set(ObjectType::Post, $postId, 'tmswp_empty', ''));
        self::assertTrue($storage->has(ObjectType::Post, $postId, 'tmswp_null'));
        self::assertTrue($storage->has(ObjectType::Post, $postId, 'tmswp_false'));
        self::assertTrue($storage->has(ObjectType::Post, $postId, 'tmswp_empty'));
        self::assertSame('', $storage->get(ObjectType::Post, $postId, 'tmswp_empty'));
        self::assertTrue($storage->delete(ObjectType::Post, $postId, 'tmswp_empty'));
        self::assertTrue($storage->delete(ObjectType::Post, $postId, 'tmswp_empty'));
    }

    public function testUseMetaStorageRoutesMetaBagToConfiguredStorage(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\StoredPost', PostType::class);
        $postId = self::createPost('tmswp_stored_post');
        $bag = $postClass::meta($postId);

        self::assertTrue($bag->set(self::CUSTOM, 'custom-storage'));
        self::assertSame('custom-storage', $bag->string(self::CUSTOM));
        self::assertTrue($bag->set('custom_null', null));
        self::assertTrue($bag->set('custom_false', false));
        self::assertTrue($bag->set('custom_empty', ''));
        self::assertTrue($bag->has('custom_null'));
        self::assertTrue($bag->has('custom_false'));
        self::assertTrue($bag->has('custom_empty'));
        self::assertNull($bag->get('custom_null'));
        self::assertFalse($bag->get('custom_false'));
        self::assertSame('', $bag->get('custom_empty'));
        // 差し替え先にだけ保存され、WP のメタには書かれていない
        self::assertFalse((new WpMetaStorage())->has(ObjectType::Post, $postId, self::CUSTOM));
    }

    public function testObjectTypeRejectsInvalidIdsAndMismatchedObjects(): void
    {
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Post->resolveId(0));
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Post->resolveId(new WP_Post((object) ['ID' => 0])));
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Term->resolveId(new WP_Post((object) ['ID' => 1])));
    }

    /** @param callable(): mixed $callback */
    private function assertInvalidMetaObject(callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            self::assertSame(InvalidArgumentException::class, $exception::class);

            return;
        }

        self::fail('InvalidArgumentException が送出されませんでした');
    }
}
