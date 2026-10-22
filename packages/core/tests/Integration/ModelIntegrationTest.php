<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use ReflectionProperty;
use stdClass;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Tests\Support\IntegrationTestCase;

/**
 * fixture テーマの Contact(#[AsModel])を、テーマの有効化と管理画面のリクエスト(admin_init のハッシュ比較)
 * という Migrator の実際の契機で移行し、CRUD を確かめる。
 */
final class ModelIntegrationTest extends IntegrationTestCase
{
    private const TABLE = 'tmswp_fixture_contacts';

    public function tear_down(): void
    {
        // テスト中の CREATE TABLE はテストライブラリにより一時テーブルになり、接続が続く限り残るため削除する
        self::wpdb()->query('DROP TABLE IF EXISTS ' . self::wpdb()->prefix . self::TABLE);
        // admin_init はプラグインの fixture の SpiedModel(Initializer の記録用)のテーブルも作る
        self::wpdb()->query('DROP TABLE IF EXISTS ' . self::wpdb()->prefix . 'tmswp_fixture_spied');

        parent::tear_down();
    }

    public function testThemeActivationMigratesTableAndModelRunsCrud(): void
    {
        $wpdb = self::wpdb();
        $connection = new Connection($wpdb);
        $contact = self::fixtureClass(self::THEME_NAMESPACE . '\\Model\\Contact', Model::class);
        $migrator = $this->themeMigrator();

        // #[AsModel] は after_setup_theme で Migrator に登録済み。テーブルはまだ無い
        self::assertSame([$contact], $migrator->models());
        self::assertNull($connection->selectVar('SHOW TABLES LIKE %s', [$wpdb->prefix . self::TABLE]));

        $this->activateFixtureTheme();

        self::assertSame($wpdb->prefix . self::TABLE, $connection->selectVar('SHOW TABLES LIKE %s', [$wpdb->prefix . self::TABLE]));
        self::assertTrue($migrator->status()[0]->current);

        $id = $contact::insert([
            'email' => 'first@example.test',
            'active' => true,
            'meta' => '{"tags":["news"]}',
            'created_at' => '2026-06-13 08:30:00',
            'status' => 'published',
        ]);

        self::assertGreaterThan(0, $id);
        self::assertSame(1, $contact::count());

        $found = $contact::find($id);
        self::assertIsObject($found);
        self::assertSame('first@example.test', $this->objectProperty($found, 'email'));
        // rowClass() を上書きしない Model の行は stdClass で、値は DB 由来の文字列のまま
        self::assertInstanceOf(stdClass::class, $found);
        self::assertSame('{"tags":["news"]}', $this->objectProperty($found, 'meta'));

        self::assertSame(1, $contact::update(['email' => 'updated@example.test'], $id));
        $updated = $contact::find($id);
        self::assertIsObject($updated);
        self::assertSame('updated@example.test', $this->objectProperty($updated, 'email'));

        self::assertSame(1, $contact::delete($id));
        self::assertSame(0, $contact::count());
    }

    public function testAdminRequestMigratesChangedSchemaByHashComparison(): void
    {
        $wpdb = self::wpdb();
        $connection = new Connection($wpdb);
        $contact = self::fixtureClass(self::THEME_NAMESPACE . '\\Model\\Contact', Model::class);
        $migrator = $this->themeMigrator();

        // 前回のデプロイ後の DB を用意する: 旧定義(phone 列なし)のテーブルと、そのハッシュが保存された状態
        $previous = new Migrator($connection);
        $previous->register(self::fixtureClass(self::THEME_NAMESPACE . '\\Model\\ContactV1', Model::class));
        $previous->migrateAll();
        self::assertNull($connection->selectVar('SHOW COLUMNS FROM ' . $wpdb->prefix . self::TABLE . ' LIKE %s', ['phone']));
        $id = $contact::insert([
            'email' => 'kept@example.test',
            'active' => false,
            'meta' => '{}',
            'created_at' => '2026-06-13 08:30:00',
            'status' => 'draft',
        ]);

        // Kernel が登録した現在の定義は、保存済みのハッシュと食い違う
        self::assertFalse($migrator->status()[0]->current);

        $this->fireAdminInit();

        self::assertTrue($migrator->status()[0]->current);
        self::assertSame('phone', $connection->selectVar('SHOW COLUMNS FROM ' . $wpdb->prefix . self::TABLE . ' LIKE %s', ['phone']));
        $phoneIndex = $connection->selectRow('SHOW INDEX FROM ' . $wpdb->prefix . self::TABLE . ' WHERE Key_name = %s', ['phone_idx']);
        self::assertIsArray($phoneIndex);
        self::assertSame('phone_idx', $phoneIndex['Key_name'] ?? null);

        // 列の追加後も既存の行は残り、削除できる
        self::assertSame(1, $contact::count());
        self::assertSame(1, $contact::delete($id));
        self::assertSame(0, $contact::count());
    }

    /**
     * テーマを有効化した直後のリクエストを再現する。switch_theme() が前のリクエストで残す theme_switched を置き、
     * WordPress がそのリクエストの init で呼ぶ check_theme_switched() から after_switch_theme を発火させる。
     */
    private function activateFixtureTheme(): void
    {
        update_option('theme_switched', 'twentytwentyfive');
        check_theme_switched();
    }

    /**
     * 管理画面のリクエストを再現して admin_init を発火する。wp-admin/admin.php と同じく管理画面用の関数を読み込み、
     * 現在の画面を管理画面にする(is_admin() が真になる)。管理画面用の HTTP ヘッダーを送る WP コアの
     * コールバック2つは、出力を始めた後の CLI ではヘッダーを送れないため外す(外したフックは tear_down で元に戻る)。
     */
    private function fireAdminInit(): void
    {
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        set_current_screen('dashboard');
        remove_action('admin_init', 'wp_admin_headers');
        remove_action('admin_init', 'send_frame_options_header', 10);

        do_action('admin_init');
    }

    private function themeMigrator(): Migrator
    {
        return self::themeKernel()->container()->get(Migrator::class);
    }

    private function objectProperty(object $object, string $name): mixed
    {
        if (!property_exists($object, $name)) {
            self::fail("object property was not found: {$name}");
        }

        return (new ReflectionProperty($object, $name))->getValue($object);
    }
}
