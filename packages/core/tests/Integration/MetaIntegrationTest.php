<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use ArrayObject;
use stdClass;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\RuntimeException;
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
        self::assertSame(['alpha', 'beta'], $bag->all()[self::CODES]);

        $storedValues = $storage->getMultiple(ObjectType::Post, $postId, [self::PRICE, self::CODES]);
        self::assertArrayHasKey(self::PRICE, $storedValues);
        self::assertArrayHasKey(self::CODES, $storedValues);
        self::assertSame('alpha', $storedValues[self::CODES]);
    }

    public function testBackslashesAndJsonStringsRoundTripThroughMetaWrites(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $postId = self::createPost('tmswp_meta_post');
        $bag = $postClass::meta($postId);
        $decoded = ['path' => 'C:\\Users\\tmswp', 'pattern' => '/^\\d+\\.\\d+$/', 'quote' => '"quoted"', 'text' => "改行\nあり"];
        $json = json_encode($decoded, JSON_THROW_ON_ERROR);
        $windowsPath = 'C:\\Program Files\\tmswp\\new';

        self::assertStringContainsString('\\\\', $json);
        self::assertTrue($bag->set('tmswp_json', $json));
        self::assertTrue($bag->set('tmswp_path', $windowsPath));
        self::assertTrue($bag->set('tmswp_paths', ['share' => '\\\\server\\share', 'nested' => ['regex' => '\\w+']]));
        self::assertTrue($bag->set(self::CODES, ['a\\b', '{"k":"v\\\\n"}']));

        self::assertSame($json, $bag->get('tmswp_json'));
        self::assertSame($decoded, $bag->json('tmswp_json'));
        self::assertSame($windowsPath, $bag->string('tmswp_path'));
        self::assertSame(['share' => '\\\\server\\share', 'nested' => ['regex' => '\\w+']], $bag->get('tmswp_paths'));
        self::assertSame(['a\\b', '{"k":"v\\\\n"}'], $bag->list(self::CODES));
        // WP の読み出し関数からも同じ値になる(DB に slash の外れた値が書かれていない)
        self::assertSame($json, get_post_meta($postId, 'tmswp_json', true));
        self::assertSame(['a\\b', '{"k":"v\\\\n"}'], get_post_meta($postId, self::CODES));
    }

    public function testWritingTheSameValueReturnsFalseWithoutFailing(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\MetaPost', PostType::class);
        $postId = self::createPost('tmswp_meta_post');
        $bag = $postClass::meta($postId);
        $storage = new WpMetaStorage();

        // 同じ値: update_metadata() が保存済みの値と === で一致して false を返す経路
        self::assertTrue($bag->set('tmswp_same', 'same'));
        self::assertFalse($bag->set('tmswp_same', 'same'));

        // 同じ値: 保存済みの '2500' と int 2500 は === で一致せず、DB の更新が 0 行で false を返す経路
        self::assertTrue($bag->set(self::PRICE, 2500));
        self::assertFalse($bag->set(self::PRICE, 2500));
        self::assertSame(['2500'], get_post_meta($postId, self::PRICE));

        // 同じ値: サニタイズ(MetaPost::sanitizePrice の int 変換)後に保存済みの値と一致する経路
        self::assertNotFalse(has_filter('sanitize_post_meta_' . self::PRICE . '_for_tmswp_meta_post'));
        self::assertFalse($storage->set(ObjectType::Post, $postId, self::PRICE, '2500.0'));
        self::assertTrue($storage->set(ObjectType::Post, $postId, self::PRICE, '2600.0'));
        self::assertSame(['2600'], get_post_meta($postId, self::PRICE));

        self::assertTrue($bag->set(self::CODES, ['alpha', 'beta']));
        self::assertFalse($bag->set(self::CODES, ['alpha', 'beta']));
        self::assertTrue($bag->set(self::CODES, ['beta', 'alpha']));
        self::assertSame(['beta', 'alpha'], $bag->list(self::CODES));

        self::assertTrue($bag->delete(self::CODES));
        self::assertFalse($bag->delete(self::CODES));
        self::assertFalse($bag->has(self::CODES));
    }

    public function testSameValueAfterSanitizingReturnsFalseAndSanitizesOnce(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage();
        $sanitized = new ArrayObject();
        add_filter('sanitize_post_meta_tmswp_counted_for_tmswp_meta_post', static function (mixed $value) use ($sanitized): mixed {
            $sanitized->append($value);

            return \is_string($value) ? trim($value) : $value;
        });
        $storage->set(ObjectType::Post, $postId, 'tmswp_counted', 'kept');
        $sanitized->exchangeArray([]);

        // サニタイズ後に保存済みの値と同じになる入力は「同じ値」。サニタイズは WP の update_metadata() の1回だけ
        self::assertFalse($storage->set(ObjectType::Post, $postId, 'tmswp_counted', ' kept '));
        self::assertSame([' kept '], $sanitized->getArrayCopy());
        self::assertSame(['kept'], get_post_meta($postId, 'tmswp_counted'));
    }

    public function testReplacingRowsWithValuesThatSanitizeToTheStoredRowsReturnsFalse(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage();
        add_filter('sanitize_post_meta_' . self::CODES . '_for_tmswp_meta_post', static fn(mixed $value): mixed => \is_string($value) ? trim($value) : $value);

        self::assertTrue($storage->setAll(ObjectType::Post, $postId, self::CODES, ['alpha', 'beta']));
        self::assertFalse($storage->setAll(ObjectType::Post, $postId, self::CODES, [' alpha', 'beta ']));
        self::assertTrue($storage->setAll(ObjectType::Post, $postId, self::CODES, ['alpha']));
        self::assertSame(['alpha'], $storage->getAll(ObjectType::Post, $postId, self::CODES));
    }

    public function testWritesStoppedByAnotherFilterReturnFalseAndKeepTheStoredValue(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage();
        $storage->set(ObjectType::Post, $postId, 'tmswp_guarded', 'before');
        $storage->setAll(ObjectType::Post, $postId, 'tmswp_guarded_rows', ['one', 'two']);
        $stop = static fn(mixed $check, int $objectId, string $key): mixed => str_starts_with($key, 'tmswp_guarded') ? false : $check;
        add_filter('update_post_metadata', $stop, 10, 3);
        add_filter('add_post_metadata', $stop, 10, 3);
        add_filter('delete_post_metadata', $stop, 10, 3);

        // 短絡フィルタが書き込みを止めた場合は、保存値が変わっていないので false(例外にしない)
        self::assertFalse($storage->set(ObjectType::Post, $postId, 'tmswp_guarded', 'after'));
        self::assertFalse($storage->set(ObjectType::Post, $postId, 'tmswp_guarded_new', 'value'));
        self::assertFalse($storage->setAll(ObjectType::Post, $postId, 'tmswp_guarded_rows', ['three']));
        self::assertFalse($storage->delete(ObjectType::Post, $postId, 'tmswp_guarded'));

        self::assertSame(['before'], get_post_meta($postId, 'tmswp_guarded'));
        self::assertFalse($storage->has(ObjectType::Post, $postId, 'tmswp_guarded_new'));
        self::assertSame(['one', 'two'], get_post_meta($postId, 'tmswp_guarded_rows'));
    }

    public function testDatabaseErrorsDuringWritesThrowAndKeepTheStoredValue(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage();
        $storage->set(ObjectType::Post, $postId, 'tmswp_blocked', 'before');
        $storage->setAll(ObjectType::Post, $postId, 'tmswp_blocked_rows', ['one', 'two']);

        self::withBrokenQueries("'tmswp_blocked", 'UPDATE|INSERT', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->set(ObjectType::Post, $postId, 'tmswp_blocked', 'after'),
                "failed to update post meta 'tmswp_blocked' for object {$postId}",
            );
            // 行の無いキーへの set() は update_metadata() から add_metadata() に進み、INSERT が失敗する
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->set(ObjectType::Post, $postId, 'tmswp_blocked_new', 'value'),
                "failed to update post meta 'tmswp_blocked_new' for object {$postId}",
            );
        });

        // delete_metadata() の DELETE は meta_id で行を指すため、メタのテーブルへの DELETE を失敗させる
        self::withBrokenQueries(self::wpdb()->postmeta, 'DELETE', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->setAll(ObjectType::Post, $postId, 'tmswp_blocked_rows', ['three']),
                "failed to delete post meta 'tmswp_blocked_rows' for object {$postId} before replacing it",
            );
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->delete(ObjectType::Post, $postId, 'tmswp_blocked'),
                "failed to delete post meta 'tmswp_blocked' for object {$postId}",
            );
        });

        self::assertSame(['before'], get_post_meta($postId, 'tmswp_blocked'));
        self::assertFalse($storage->has(ObjectType::Post, $postId, 'tmswp_blocked_new'));
        self::assertSame(['one', 'two'], get_post_meta($postId, 'tmswp_blocked_rows'));

        // setAll() の削除の後の追加が失敗した場合も例外にする(巻き戻さないため、行は削除された状態で残る)
        self::withBrokenQueries("'tmswp_blocked", 'INSERT', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->setAll(ObjectType::Post, $postId, 'tmswp_blocked_rows', ['three']),
                "failed to add post meta 'tmswp_blocked_rows' for object {$postId}",
            );
        });
    }

    public function testReadFailureBeforeDeletingMetaThrowsAndKeepsTheStoredValue(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage();
        $storage->set(ObjectType::Post, $postId, 'tmswp_read_delete', 'old');
        wp_cache_delete($postId, 'post_meta');

        // 存在の確認(メタキャッシュの読み込みの SELECT)を DB のエラーにする
        self::withBrokenQueries(self::wpdb()->postmeta, 'SELECT', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->delete(ObjectType::Post, $postId, 'tmswp_read_delete'),
                "failed to read post meta 'tmswp_read_delete' for object {$postId} before deleting it",
            );
        });

        wp_cache_delete($postId, 'post_meta');
        self::assertSame(['old'], get_post_meta($postId, 'tmswp_read_delete'));
    }

    public function testReadFailureBeforeReplacingRowsThrowsAndKeepsTheStoredRows(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage();
        $storage->setAll(ObjectType::Post, $postId, 'tmswp_read_before', ['old']);
        wp_cache_delete($postId, 'post_meta');

        self::withBrokenQueries(self::wpdb()->postmeta, 'SELECT', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->setAll(ObjectType::Post, $postId, 'tmswp_read_before', ['new']),
                "failed to read post meta 'tmswp_read_before' for object {$postId} before replacing it",
            );
        });

        wp_cache_delete($postId, 'post_meta');
        self::assertSame(['old'], get_post_meta($postId, 'tmswp_read_before'));
    }

    public function testReadFailureAfterReplacingRowsThrows(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage();
        // 置き換えの前の読み取りはキャッシュから行い、追加の後の読み直し(SELECT)だけを DB のエラーにする
        self::assertSame([], $storage->getAll(ObjectType::Post, $postId, 'tmswp_read_after'));

        self::withBrokenQueries(self::wpdb()->postmeta, 'SELECT', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->setAll(ObjectType::Post, $postId, 'tmswp_read_after', ['new']),
                "failed to read post meta 'tmswp_read_after' for object {$postId} after replacing it",
            );
        });

        wp_cache_delete($postId, 'post_meta');
        self::assertSame(['new'], get_post_meta($postId, 'tmswp_read_after'));
    }

    public function testNestedWriteToTheSameMetaDoesNotHideTheOuterDatabaseError(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage();
        $storage->set(ObjectType::Post, $postId, 'tmswp_nested', 'old-value');
        $nested = new ArrayObject();
        // 外側の UPDATE の直前(update_post_meta アクション)に、同じメタへ保存済みの値を書く
        add_action('update_post_meta', static function (mixed $metaId, mixed $objectId, mixed $metaKey) use ($storage, $postId, $nested): void {
            if ($metaKey === 'tmswp_nested' && $nested->count() === 0) {
                $nested->append($storage->set(ObjectType::Post, $postId, 'tmswp_nested', 'old-value'));
            }
        }, 10, 3);

        self::withBrokenQueries("'new-value'", 'UPDATE', function () use ($storage, $postId): void {
            $this->assertDatabaseWriteFails(
                static fn(): bool => $storage->set(ObjectType::Post, $postId, 'tmswp_nested', 'new-value'),
                "failed to update post meta 'tmswp_nested' for object {$postId}",
            );
        });

        self::assertSame([false], $nested->getArrayCopy());
        wp_cache_delete($postId, 'post_meta');
        self::assertSame(['old-value'], get_post_meta($postId, 'tmswp_nested'));
    }

    public function testEarlierUnrelatedDatabaseErrorIsNotReportedForAWriteWithoutQueries(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage();
        $storage->set(ObjectType::Post, $postId, 'tmswp_stale', 'same');
        self::assertSame('same', $storage->get(ObjectType::Post, $postId, 'tmswp_stale'));
        $wpdb = self::wpdb();
        $suppressed = $wpdb->suppress_errors(true);

        try {
            $wpdb->query('SELECT * FROM tmswp_missing_table_for_stale_error');
            self::assertNotSame('', $wpdb->last_error);
            add_filter('update_post_metadata', static fn(mixed $check, int $objectId, string $key): mixed => $key === 'tmswp_stale_stopped' ? false : $check, 10, 3);

            // クエリを発行しない false(同じ値・短絡)は、前の無関係なエラーを拾わない
            self::assertFalse($storage->set(ObjectType::Post, $postId, 'tmswp_stale', 'same'));
            self::assertFalse($storage->set(ObjectType::Post, $postId, 'tmswp_stale_stopped', 'value'));
            self::assertStringContainsString('tmswp_missing_table_for_stale_error', $wpdb->last_error);
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }

    public function testWpMetaStorageKeepsStoredFalseAndEmptyValuesAndRejectsNull(): void
    {
        $postId = self::createPost('tmswp_meta_post');
        $storage = new WpMetaStorage();

        self::assertTrue($storage->set(ObjectType::Post, $postId, 'tmswp_false', false));
        self::assertTrue($storage->set(ObjectType::Post, $postId, 'tmswp_empty', ''));
        self::assertTrue($storage->has(ObjectType::Post, $postId, 'tmswp_false'));
        self::assertTrue($storage->has(ObjectType::Post, $postId, 'tmswp_empty'));
        self::assertSame('', $storage->get(ObjectType::Post, $postId, 'tmswp_empty'));
        self::assertTrue($storage->delete(ObjectType::Post, $postId, 'tmswp_empty'));
        self::assertFalse($storage->delete(ObjectType::Post, $postId, 'tmswp_empty'));

        $this->assertRejectedValue(
            static fn(): bool => $storage->set(ObjectType::Post, $postId, 'tmswp_null', null),
            "meta 'tmswp_null' cannot store null; use delete() to remove it",
        );
        $this->assertRejectedValue(
            static fn(): bool => $storage->setAll(ObjectType::Post, $postId, 'tmswp_null', ['a', null]),
            "meta 'tmswp_null' cannot store null; use delete() to remove it",
        );
        $this->assertRejectedValue(
            static fn(): bool => $storage->set(ObjectType::Post, $postId, 'tmswp_object', ['nested' => new stdClass()]),
            "meta 'tmswp_object' cannot store an object (stdClass)",
        );
        self::assertFalse($storage->has(ObjectType::Post, $postId, 'tmswp_null'));
        self::assertFalse($storage->has(ObjectType::Post, $postId, 'tmswp_object'));
    }

    public function testUseMetaStorageRoutesMetaBagToConfiguredStorage(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\StoredPost', PostType::class);
        $postId = self::createPost('tmswp_stored_post');
        $bag = $postClass::meta($postId);

        self::assertTrue($bag->set(self::CUSTOM, 'custom-storage'));
        self::assertSame('custom-storage', $bag->string(self::CUSTOM));
        self::assertTrue($bag->set('custom_false', false));
        self::assertTrue($bag->set('custom_empty', ''));
        self::assertTrue($bag->has('custom_false'));
        self::assertTrue($bag->has('custom_empty'));
        self::assertFalse($bag->get('custom_false'));
        self::assertSame('', $bag->get('custom_empty'));
        // null は差し替え先に関係なく MetaBag が拒否する
        $this->assertRejectedValue(static fn(): bool => $bag->set('custom_null', null), "meta 'custom_null' cannot be set to null; use delete() to remove it");
        self::assertFalse($bag->has('custom_null'));
        // 差し替え先にだけ保存され、WP のメタには書かれていない
        self::assertFalse((new WpMetaStorage())->has(ObjectType::Post, $postId, self::CUSTOM));
    }

    public function testObjectTypeRejectsInvalidIdsAndMismatchedObjects(): void
    {
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Post->resolveId(0));
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Post->resolveId(new WP_Post((object) ['ID' => 0])));
        $this->assertInvalidMetaObject(static fn(): int => ObjectType::Term->resolveId(new WP_Post((object) ['ID' => 1])));
    }

    /**
     * DB の書き込みのエラーで例外になり、メッセージにデータベースのエラーが入ることを確かめる。
     *
     * @param callable(): bool $write
     */
    private function assertDatabaseWriteFails(callable $write, string $failure): void
    {
        try {
            $write();
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith("{$failure}: ", $exception->getMessage());
            self::assertStringContainsString('tmswp_missing_table', $exception->getMessage());

            return;
        }

        self::fail("データベースのエラーが例外になりませんでした: {$failure}");
    }

    /**
     * $needle を含む SQL のうち $verbs の文を存在しないテーブルへの SQL に書き換え、DB のエラーにした状態で $callback を実行する。
     *
     * @param callable(): void $callback
     */
    private static function withBrokenQueries(string $needle, string $verbs, callable $callback): void
    {
        $wpdb = self::wpdb();
        $broken = 'UPDATE ' . $wpdb->prefix . 'tmswp_missing_table SET meta_value = 1';
        $breakQueries = static fn(string $query): string => str_contains($query, $needle) && preg_match('/^\s*(' . $verbs . ')\b/i', $query) === 1 ? $broken : $query;
        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $breakQueries);

        try {
            $callback();
        } finally {
            remove_filter('query', $breakQueries);
            $wpdb->suppress_errors($suppressed);
        }
    }

    /** @param callable(): bool $write */
    private function assertRejectedValue(callable $write, string $message): void
    {
        try {
            $write();
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail("保存できない値が拒否されませんでした: {$message}");
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
