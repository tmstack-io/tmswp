<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use ArrayObject;
use BackedEnum;
use ReflectionMethod;
use stdClass;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Option\OptionRepository;
use TMSWP\Core\Option\OptionRepositoryFactory;
use TMSWP\Core\Option\WpOptionStorage;
use TMSWP\Core\Tests\Support\IntegrationTestCase;

final class OptionIntegrationTest extends IntegrationTestCase
{
    private const PREFIX = 'tmswp_fixture_';

    public function testOptionRepositoryReadsDefaultsAndWritesWithSchemaAutoload(): void
    {
        $repository = self::siteOptionRepository();
        self::assertSame('info@example.test', $repository->string('contact_email'));
        self::assertTrue($repository->set('contact_email', 'sales@example.test'));
        self::assertSame('sales@example.test', $repository->string('contact_email'));
        self::assertSame('sales@example.test', get_option(self::PREFIX . 'contact_email'));
        self::assertArrayNotHasKey(self::PREFIX . 'contact_email', wp_load_alloptions());
        self::assertTrue($repository->set('maintenance', true));
        self::assertTrue($repository->bool('maintenance'));
    }

    public function testOptionRepositoryRejectsNullAndValuesThatDoNotMatchTheSchemaType(): void
    {
        $repository = self::siteOptionRepository();
        $repository->set('maintenance', false);

        $this->assertRejectedValue(static fn(): bool => $repository->set('maintenance', '1'), "option 'maintenance' expects boolean, got string");
        $this->assertRejectedValue(static fn(): bool => $repository->set('maintenance', null), "option 'maintenance' cannot be set to null; use delete() to remove it");
        self::assertTrue($repository->has('maintenance'));
        self::assertFalse($repository->bool('maintenance'));
    }

    public function testWpOptionStorageKeepsStoredFalseAndEmptyValuesAndRejectsNull(): void
    {
        $storage = new WpOptionStorage();

        self::assertTrue($storage->set(self::PREFIX . 'false_value', false));
        self::assertTrue($storage->set(self::PREFIX . 'empty_value', ''));
        self::assertTrue($storage->has(self::PREFIX . 'false_value'));
        self::assertTrue($storage->has(self::PREFIX . 'empty_value'));
        self::assertFalse($storage->get(self::PREFIX . 'false_value'));
        self::assertSame('', $storage->get(self::PREFIX . 'empty_value'));

        $this->assertRejectedValue(static fn(): bool => $storage->set(self::PREFIX . 'null_value', null), "option 'tmswp_fixture_null_value' cannot store null; use delete() to remove it");
        self::assertFalse($storage->has(self::PREFIX . 'null_value'));
        self::assertFalse(get_option(self::PREFIX . 'null_value'));
    }

    public function testWritingTheSameValueReturnsFalseWithoutFailing(): void
    {
        $storage = new WpOptionStorage();
        $name = self::PREFIX . 'same_value';

        // 同じ値: update_option() が保存済みの値と一致して false を返す経路
        self::assertTrue($storage->set($name, 'same'));
        self::assertFalse($storage->set($name, 'same'));

        // 同じ値: 保存済みの '5' と int 5 は PHP では一致せず、DB の更新が 0 行で false を返す経路
        self::assertTrue($storage->set($name, '5'));
        self::assertFalse($storage->set($name, 5));

        // 同じ値: サニタイズ後に保存済みの値と一致する経路
        add_filter("sanitize_option_{$name}", static fn(mixed $value): mixed => \is_string($value) ? trim($value) : $value);
        self::assertFalse($storage->set($name, ' 5 '));
        self::assertTrue($storage->set($name, ' 6 '));
        self::assertSame('6', get_option($name));

        self::assertTrue($storage->delete($name));
        self::assertFalse($storage->delete($name));
    }

    public function testPreUpdateFilterThatRestoresTheStoredValueReturnsFalse(): void
    {
        $storage = new WpOptionStorage();
        $name = self::PREFIX . 'pre_update_trim';
        $storage->set($name, 'a');
        add_filter("pre_update_option_{$name}", static fn(mixed $value): mixed => \is_string($value) ? trim($value) : $value);

        // WP の update_option() は名前別の pre_update_option の後の値で比べ、同じ値として false を返す
        self::assertFalse($storage->set($name, ' a '));
        self::assertSame('a', get_option($name));
    }

    public function testSameValueAfterSanitizingDoesNotModifyTheCallersObject(): void
    {
        $storage = new WpOptionStorage();
        $name = self::PREFIX . 'object_value';
        $storage->set($name, (object) ['label' => 'keep']);
        add_filter("sanitize_option_{$name}", static function (mixed $value): mixed {
            if ($value instanceof stdClass && \is_string($value->label)) {
                $value->label = trim($value->label);
            }

            return $value;
        });
        $input = (object) ['label' => ' keep '];

        self::assertFalse($storage->set($name, $input));
        self::assertSame(' keep ', $input->label);
        self::assertEquals((object) ['label' => 'keep'], get_option($name));
    }

    public function testGenericFilterAddedAtTheSamePriorityDuringTheWriteIsRespected(): void
    {
        $storage = new WpOptionStorage();
        $name = self::PREFIX . 'late_filter';
        $storage->set($name, 'a');
        // 名前別の pre_update_option の中から、汎用の pre_update_option に PHP_INT_MAX の trim を後から足す
        add_filter("pre_update_option_{$name}", static function (mixed $value): mixed {
            add_filter('pre_update_option', static fn(mixed $filtered): mixed => \is_string($filtered) ? trim($filtered) : $filtered, PHP_INT_MAX);

            return $value;
        });

        self::assertFalse($storage->set($name, ' a '));
        self::assertSame('a', get_option($name));
    }

    public function testNameWithSurroundingWhitespaceIsNormalizedLikeWordPress(): void
    {
        $storage = new WpOptionStorage();
        $name = self::PREFIX . 'padded_name';
        $storage->set($name, 'old');

        // WP は名前の前後の空白を取り除くため、同じオプションへの同じ値として false になる
        self::assertTrue($storage->has(" {$name} "));
        self::assertFalse($storage->set(" {$name} ", 'old'));
        self::assertSame('old', get_option($name));
    }

    public function testNestedWriteToTheSameOptionDoesNotHideTheOuterDatabaseError(): void
    {
        $storage = new WpOptionStorage();
        $name = self::PREFIX . 'nested';
        $storage->set($name, 'old-value');
        $nested = new ArrayObject();
        // 外側の UPDATE の直前(update_option アクション)に、同じオプションへ保存済みの値を書く
        add_action('update_option', static function (mixed $option) use ($storage, $name, $nested): void {
            if ($option === $name && $nested->count() === 0) {
                $nested->append($storage->set($name, 'old-value'));
            }
        });

        self::withBrokenQueries("'new-value'", 'UPDATE', function () use ($storage, $name): void {
            $this->assertDatabaseWriteFails(static fn(): bool => $storage->set($name, 'new-value'), "failed to update option '{$name}'");
        });

        self::assertSame([false], $nested->getArrayCopy());
        wp_cache_flush();
        self::assertSame('old-value', get_option($name));
    }

    public function testEarlierUnrelatedDatabaseErrorIsNotReportedForAWriteWithoutQueries(): void
    {
        $storage = new WpOptionStorage();
        $name = self::PREFIX . 'stale_error';
        $storage->set($name, 'same');
        $wpdb = self::wpdb();
        $suppressed = $wpdb->suppress_errors(true);

        try {
            $wpdb->query('SELECT * FROM tmswp_missing_table_for_stale_error');
            self::assertNotSame('', $wpdb->last_error);

            // クエリを発行しない false(同じ値)は、前の無関係なエラーを拾わない
            self::assertFalse($storage->set($name, 'same'));
            self::assertStringContainsString('tmswp_missing_table_for_stale_error', $wpdb->last_error);
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }

    public function testReadFailureBeforeDeletingOrWritingAnOptionThrows(): void
    {
        $storage = new WpOptionStorage();
        $name = self::PREFIX . 'read_failure';
        // autoload しないオプションは、キャッシュに無ければ get_option() が1件だけを SELECT する
        $storage->set($name, 'kept', false);

        foreach (['delete' => static fn(): bool => $storage->delete($name), 'set' => static fn(): bool => $storage->set($name, 'changed')] as $operation => $write) {
            wp_cache_delete($name, 'options');
            wp_cache_delete('notoptions', 'options');

            self::withBrokenQueries("'{$name}'", 'SELECT', function () use ($write, $name, $operation): void {
                $this->assertDatabaseWriteFails($write, "failed to read option '{$name}' before " . ($operation === 'delete' ? 'deleting' : 'writing') . ' it');
            });
        }

        wp_cache_flush();
        self::assertSame('kept', get_option($name));
    }

    public function testDatabaseErrorsDuringWritesThrowAndKeepTheStoredValue(): void
    {
        $storage = new WpOptionStorage();
        $name = self::PREFIX . 'failing_write';
        $storage->set($name, 'before');

        self::withBrokenQueries("'{$name}", 'INSERT|UPDATE|DELETE', function () use ($storage, $name): void {
            $this->assertDatabaseWriteFails(static fn(): bool => $storage->set($name, 'after'), "failed to update option '{$name}'");
            $this->assertDatabaseWriteFails(static fn(): bool => $storage->set($name . '_new', 'value'), "failed to add option '{$name}_new'");
            $this->assertDatabaseWriteFails(static fn(): bool => $storage->delete($name), "failed to delete option '{$name}'");
        });

        // delete_option() は DB の削除に失敗してもオブジェクトキャッシュから外すため、DB から読み直して確かめる
        wp_cache_flush();
        self::assertSame('before', $storage->get($name));
        self::assertFalse($storage->has($name . '_new'));
    }

    public function testDeleteAllReturnsOnlyTheOptionsThatExisted(): void
    {
        $repository = self::siteOptionRepository();
        $repository->deleteAll();
        $repository->set('contact_email', 'only@example.test');

        self::assertSame([self::PREFIX . 'contact_email'], $repository->deleteAll());
        self::assertSame([], $repository->deleteAll());
    }

    public function testOptionAccessEnumDeletesAndRewritesGroup(): void
    {
        $probeResult = (new ReflectionMethod(self::THEME_NAMESPACE . '\\Option\\SiteOptionProbe', 'exercise'))->invoke(null);
        self::assertIsArray($probeResult);
        self::assertSame(self::PREFIX . 'contact_email', $probeResult['name']);
        self::assertSame('after-delete@example.test', $probeResult['contact']);
        self::assertFalse($probeResult['maintenanceHas']);
        self::assertSame([self::PREFIX . 'contact_email', self::PREFIX . 'maintenance'], $probeResult['deleted']);
    }

    private static function siteOptionRepository(): OptionRepository
    {
        $optionClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Option\\SiteOption', BackedEnum::class);

        return Kernel::forClass($optionClass)->container()->get(OptionRepositoryFactory::class)->for($optionClass);
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
        $broken = 'UPDATE ' . $wpdb->prefix . 'tmswp_missing_table SET option_value = 1';
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
}
