<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use ReflectionProperty;
use stdClass;
use TMSWP\Core\Exception\MigrationException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\MigrationAction;
use TMSWP\Core\Model\MigrationResult;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Core\Tests\Support\IntegrationTestCase;

/**
 * fixture テーマの Contact(#[AsModel])を、`wp tmswp migrate` が呼ぶ Migrator::migrateAll() で移行し、CRUD を
 * 確かめる。テーマの有効化・管理画面のリクエストではテーブルを変えず、テーブル定義のハッシュが DB と食い違えば
 * 権限のある管理者に通知すること、dbDelta の失敗でハッシュを更新しないことも、実際の DB で確かめる。
 */
final class ModelIntegrationTest extends IntegrationTestCase
{
    private const TABLE = 'tmswp_fixture_contacts';

    /** dbDelta の失敗を再現するテーブル(このファイルの NoteV1・NoteV2 が宣言する) */
    private const NOTES_TABLE = 'tmswp_it_notes';

    private const OPTION = 'tmswp_schema_hashes';

    /** dbDelta が適用しない変更を再現するテーブル(このファイルの Flag* が宣言する) */
    private const FLAGS_TABLE = 'tmswp_it_flags';

    /** 桁の多い数値の既定値の変更を再現するテーブル(このファイルの Big*・Dec* が宣言する) */
    private const BIG_TABLE = 'tmswp_it_big';

    private const DEC_TABLE = 'tmswp_it_dec';

    /** @var list<string> recordQueries() の後に発行された SQL */
    private array $queries = [];

    /**
     * ALTER TABLE は一時テーブルでも暗黙のコミットを起こすため、前のテストが保存したハッシュがトランザクションの
     * 巻き戻しで消えずに残りうる。各テストは保存済みのハッシュが無い状態から始める。
     */
    public function set_up(): void
    {
        parent::set_up();
        delete_option(self::OPTION);
    }

    public function tear_down(): void
    {
        // テスト中の CREATE TABLE はテストライブラリにより一時テーブルになり、接続が続く限り残るため削除する
        foreach ([self::TABLE, 'tmswp_fixture_spied', self::NOTES_TABLE, self::FLAGS_TABLE, self::BIG_TABLE, self::DEC_TABLE, 'tmswp_it_words', 'tmswp_it_precise', 'tmswp_it_exponent', 'tmswp_it_rounded'] as $table) {
            self::wpdb()->query('DROP TABLE IF EXISTS ' . self::wpdb()->prefix . $table);
        }

        parent::tear_down();
    }

    /** テーマの有効化(tmswp/activated)と管理画面のリクエスト(admin_init)では、テーブルを作らない。 */
    public function testActivationAndAdminRequestDoNotMigrate(): void
    {
        $wpdb = self::wpdb();
        $connection = new Connection($wpdb);
        $migrator = $this->themeMigrator();

        // #[AsModel] は after_setup_theme で Migrator に登録済み。テーブルはまだ無い
        self::assertSame([$this->contactClass()], $migrator->models());

        $this->activateFixtureTheme();
        $this->fireAdminInit();

        self::assertNull($connection->selectVar('SHOW TABLES LIKE %s', [$wpdb->prefix . self::TABLE]));
        self::assertFalse($migrator->status()[0]->current);
        self::assertFalse(get_option(self::OPTION));
    }

    public function testMigrateAllCreatesTableOnceAndModelRunsCrud(): void
    {
        $wpdb = self::wpdb();
        $connection = new Connection($wpdb);
        $contact = $this->contactClass();
        $migrator = $this->themeMigrator();

        self::assertSame([[self::TABLE, MigrationAction::Migrated]], self::actions($migrator->migrateAll()));
        self::assertSame($wpdb->prefix . self::TABLE, $connection->selectVar('SHOW TABLES LIKE %s', [$wpdb->prefix . self::TABLE]));
        self::assertTrue($migrator->status()[0]->current);
        // ハッシュは autoload しない1つのオプションに保存する
        self::assertSame('off', $connection->selectVar("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", [self::OPTION]));

        // 定義が同じなら dbDelta を実行しない(DESCRIBE も CREATE / ALTER も発行しない)
        $this->recordQueries();
        self::assertSame([[self::TABLE, MigrationAction::Skipped]], self::actions($migrator->migrateAll()));
        self::assertSame([], array_values(array_filter($this->queries, static fn(string $query): bool => preg_match('/^\s*(DESCRIBE|CREATE|ALTER)\b/i', $query) === 1)));

        // force は定義が同じでも dbDelta を通す。実際の DB でも、適用後に残る差分は無い
        self::assertSame([[self::TABLE, MigrationAction::Migrated]], self::actions($migrator->migrateAll(force: true)));

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

    /**
     * 前回のデプロイ後の DB と定義が食い違うと、権限のある管理者にだけ管理画面で通知し、管理画面のリクエストでは
     * 適用しない。migrateAll() が列と索引を加え、既存の行を残す。
     */
    public function testChangedDefinitionIsNotifiedToAdministratorAndAppliedByMigrateAll(): void
    {
        $wpdb = self::wpdb();
        $connection = new Connection($wpdb);
        $contact = $this->contactClass();
        $migrator = $this->themeMigrator();

        // 前回のデプロイ後の DB を用意する: 旧定義(phone 列なし)のテーブルと、そのハッシュが保存された状態
        $previous = new Migrator($connection);
        $previous->register(self::fixtureClass(self::THEME_NAMESPACE . '\\Model\\ContactV1', Model::class));
        $previous->migrateAll();
        $id = $contact::insert([
            'email' => 'kept@example.test',
            'active' => false,
            'meta' => '{}',
            'created_at' => '2026-06-13 08:30:00',
            'status' => 'draft',
        ]);

        // Kernel が登録した現在の定義は、保存済みのハッシュと食い違う
        self::assertFalse($migrator->status()[0]->current);

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        self::assertIsInt($subscriber);
        wp_set_current_user($subscriber);
        self::assertStringNotContainsString(self::TABLE, $this->adminNotices());

        $administrator = self::factory()->user->create(['role' => 'administrator']);
        self::assertIsInt($administrator);
        wp_set_current_user($administrator);
        $notices = $this->adminNotices();
        self::assertStringContainsString('The database tables ' . self::TABLE . ' do not match their TMSWP table definitions. Run wp tmswp migrate to apply the definitions.', $notices);

        $this->fireAdminInit();
        self::assertFalse($migrator->status()[0]->current);
        self::assertNull($connection->selectVar('SHOW COLUMNS FROM ' . $wpdb->prefix . self::TABLE . ' LIKE %s', ['phone']));

        self::assertSame([[self::TABLE, MigrationAction::Migrated]], self::actions($migrator->migrateAll()));
        self::assertTrue($migrator->status()[0]->current);
        self::assertSame('phone', $connection->selectVar('SHOW COLUMNS FROM ' . $wpdb->prefix . self::TABLE . ' LIKE %s', ['phone']));
        $phoneIndex = $connection->selectRow('SHOW INDEX FROM ' . $wpdb->prefix . self::TABLE . ' WHERE Key_name = %s', ['phone_idx']);
        self::assertIsArray($phoneIndex);
        self::assertSame('phone_idx', $phoneIndex['Key_name'] ?? null);
        self::assertStringNotContainsString(self::TABLE, $this->adminNotices());

        // 列の追加後も既存の行は残り、削除できる
        self::assertSame(1, $contact::count());
        self::assertSame(1, $contact::delete($id));
        self::assertSame(0, $contact::count());
    }

    /** dbDelta の ALTER がデータベースのエラーで失敗すると、例外にしてハッシュを更新しない。 */
    public function testDatabaseErrorDuringDbDeltaKeepsStoredHash(): void
    {
        $storedHash = $this->migrateNotesV1();
        // 型を変える ALTER を、存在しない列を指す ALTER に差し替えて失敗させる
        $notesTable = self::wpdb()->prefix . self::NOTES_TABLE;
        $this->rewriteNotesAlter("ALTER TABLE {$notesTable} CHANGE COLUMN `missing` `note` varchar(100) NOT NULL");

        $exception = $this->migrateNotesV2Failing();

        self::assertStringStartsWith('dbDelta failed on table ' . self::NOTES_TABLE . ': ', $exception->getMessage());
        self::assertStringContainsString('missing', $exception->getMessage());
        self::assertSame($storedHash, $this->storedHash(self::NOTES_TABLE));
    }

    /** dbDelta がエラーを残さずに変更を適用しなかった場合も、残った差分を検出してハッシュを更新しない。 */
    public function testRemainingDifferenceAfterDbDeltaKeepsStoredHash(): void
    {
        $storedHash = $this->migrateNotesV1();
        // 型を変える ALTER を、何も変えない文に差し替える(エラーは残らない)
        $this->rewriteNotesAlter('SELECT 1');

        $exception = $this->migrateNotesV2Failing();

        self::assertSame(
            'dbDelta left differences on table ' . self::NOTES_TABLE . ': Changed type of ' . self::wpdb()->prefix . self::NOTES_TABLE . '.note from varchar(50) to varchar(100)'
            . '; if the database records a column type in another notation, write the type in the table definition as the database records it',
            $exception->getMessage(),
        );
        self::assertSame($storedHash, $this->storedHash(self::NOTES_TABLE));
    }

    /**
     * dbDelta は数値の既定値の変更を適用せず、残った差分にも出さない。適用後の構造を定義と照らし合わせて食い違いを
     * 検出し、ハッシュを更新しない(手動の ALTER が要る)。
     */
    public function testDefaultChangeThatDbDeltaDoesNotApplyKeepsStoredHash(): void
    {
        $storedHash = $this->migrateModel(ModelIntegrationTestFlagV1::class, self::FLAGS_TABLE);

        $exception = $this->migrateFailing(ModelIntegrationTestFlagDefaultV2::class);

        self::assertSame(
            'table ' . self::FLAGS_TABLE . ': column enabled is expected to have DEFAULT 1 but the database has DEFAULT 0; dbDelta does not apply this change, so apply it with a manual ALTER TABLE'
            . ', or, if the database stored a rounded value, change the definition to the value the database stores',
            $exception->getMessage(),
        );
        self::assertSame($storedHash, $this->storedHash(self::FLAGS_TABLE));
        $column = self::wpdb()->get_row('SHOW COLUMNS FROM ' . self::wpdb()->prefix . self::FLAGS_TABLE . " LIKE 'enabled'", ARRAY_A);
        self::assertIsArray($column);
        self::assertSame('0', $column['Default']);
    }

    /** dbDelta は NULL の可否の変更も適用しない。食い違いを検出してハッシュを更新しない。 */
    public function testNullabilityChangeThatDbDeltaDoesNotApplyKeepsStoredHash(): void
    {
        $storedHash = $this->migrateModel(ModelIntegrationTestFlagV1::class, self::FLAGS_TABLE);

        $exception = $this->migrateFailing(ModelIntegrationTestFlagNotNullV2::class);

        self::assertSame(
            'table ' . self::FLAGS_TABLE . ': column label is expected to be NOT NULL but the database has NULL; dbDelta does not apply this change, so apply it with a manual ALTER TABLE',
            $exception->getMessage(),
        );
        self::assertSame($storedHash, $this->storedHash(self::FLAGS_TABLE));
    }

    /** 桁の多い整数の既定値の変更も、値を丸めずに比べて検出し、ハッシュを更新しない。 */
    public function testLargeIntegerDefaultChangeIsDetectedWithoutLosingPrecision(): void
    {
        $storedHash = $this->migrateModel(ModelIntegrationTestBigV1::class, self::BIG_TABLE);

        $exception = $this->migrateFailing(ModelIntegrationTestBigV2::class);

        self::assertSame(
            'table ' . self::BIG_TABLE . ': column val is expected to have DEFAULT 9007199254740993 but the database has DEFAULT 9007199254740992; dbDelta does not apply this change, so apply it with a manual ALTER TABLE'
            . ', or, if the database stored a rounded value, change the definition to the value the database stores',
            $exception->getMessage(),
        );
        self::assertSame($storedHash, $this->storedHash(self::BIG_TABLE));
    }

    /** 桁の多い DECIMAL の既定値の変更も、値を丸めずに比べて検出し、ハッシュを更新しない。 */
    public function testLargeDecimalDefaultChangeIsDetectedWithoutLosingPrecision(): void
    {
        $storedHash = $this->migrateModel(ModelIntegrationTestDecV1::class, self::DEC_TABLE);

        $exception = $this->migrateFailing(ModelIntegrationTestDecV2::class);

        self::assertSame(
            'table ' . self::DEC_TABLE . ': column val is expected to have DEFAULT 9007199254740993 but the database has DEFAULT 9007199254740992; dbDelta does not apply this change, so apply it with a manual ALTER TABLE'
            . ', or, if the database stored a rounded value, change the definition to the value the database stores',
            $exception->getMessage(),
        );
        self::assertSame($storedHash, $this->storedHash(self::DEC_TABLE));
    }

    /** 文字列の既定値の中の `NOT NULL` は、列の制約ではない。NULL を許す列として作られ、照合を通る。 */
    public function testStringDefaultContainingConstraintWordsIsMigrated(): void
    {
        $this->migrateModel(ModelIntegrationTestConstraintWords::class, 'tmswp_it_words');

        $column = self::wpdb()->get_row('SHOW FULL COLUMNS FROM ' . self::wpdb()->prefix . "tmswp_it_words LIKE 'label'", ARRAY_A);
        self::assertIsArray($column);
        self::assertSame(['YES', 'NOT NULL'], [$column['Null'], $column['Default']]);
    }

    /**
     * 小数秒の精度を付けた CURRENT_TIMESTAMP を、MariaDB は `current_timestamp(6)` と返す。精度を保って同じものとして
     * 扱い、照合を通る(wp-env の DB は MariaDB)。
     */
    public function testCurrentTimestampWithFractionalPrecisionIsMigrated(): void
    {
        $this->migrateModel(ModelIntegrationTestPreciseTimestamp::class, 'tmswp_it_precise');

        $column = self::wpdb()->get_row('SHOW FULL COLUMNS FROM ' . self::wpdb()->prefix . "tmswp_it_precise LIKE 'created'", ARRAY_A);
        self::assertIsArray($column);
        self::assertIsString($column['Default']);
        self::assertSame('current_timestamp(6)', strtolower($column['Default']));
    }

    /**
     * DB は double の大きな既定値を指数の表記(`1e20`)で返す。指数の無い定義の値と同じ値として扱い、適用してハッシュを
     * 保存する。
     */
    public function testLargeDoubleDefaultReturnedInExponentNotationIsMigrated(): void
    {
        $hash = $this->migrateModel(ModelIntegrationTestLargeDouble::class, 'tmswp_it_exponent');

        self::assertSame(ModelIntegrationTestLargeDouble::table()->hash(new Connection(self::wpdb())), $hash);
        $column = self::wpdb()->get_row('SHOW FULL COLUMNS FROM ' . self::wpdb()->prefix . "tmswp_it_exponent LIKE 'valuecol'", ARRAY_A);
        self::assertIsArray($column);
        self::assertSame('1e20', $column['Default']);
    }

    /** 定義の指数の表記(`1E-3`)を、DB が返す指数の無い表記(`0.001`)と同じ値として扱い、適用してハッシュを保存する。 */
    public function testExponentDefaultInDefinitionIsMigrated(): void
    {
        $hash = $this->migrateModel(ModelIntegrationTestSmallDouble::class, 'tmswp_it_exponent');

        self::assertSame(ModelIntegrationTestSmallDouble::table()->hash(new Connection(self::wpdb())), $hash);
        $column = self::wpdb()->get_row('SHOW FULL COLUMNS FROM ' . self::wpdb()->prefix . "tmswp_it_exponent LIKE 'valuecol'", ARRAY_A);
        self::assertIsArray($column);
        self::assertSame('0.001', $column['Default']);
    }

    /**
     * DB は double で表せない桁の既定値を丸めて記録する(`100000000000000000001` を `1e20`)。食い違いとして例外にして
     * ハッシュを保存せず、ALTER TABLE では直らないため、定義の既定値を DB が記録する値に合わせる案内を含める。
     */
    public function testRoundedDoubleDefaultFailsWithGuidanceToMatchTheDefinition(): void
    {
        $exception = $this->migrateFailing(ModelIntegrationTestRoundedDouble::class);

        self::assertSame(
            'table tmswp_it_rounded: column valuecol is expected to have DEFAULT 100000000000000000001 but the database has DEFAULT 1e20; dbDelta does not apply this change, so apply it with a manual ALTER TABLE'
            . ', or, if the database stored a rounded value, change the definition to the value the database stores',
            $exception->getMessage(),
        );
        $hashes = get_option(self::OPTION);
        self::assertFalse(\is_array($hashes) && \array_key_exists('tmswp_it_rounded', $hashes));
    }

    /**
     * 保存済みのハッシュの読み取りがデータベースのエラーになったら、「ハッシュが無い」として扱わずに例外にする。
     * 失敗した読み取りで WP が書いた「オプションが無い」のキャッシュも残さない。
     */
    public function testHashReadFailureIsAnExceptionAndLeavesNoNotOptionsCache(): void
    {
        $this->migrateNotesV1();
        $wpdb = self::wpdb();
        $migrator = new Migrator(new Connection($wpdb));
        $migrator->register(ModelIntegrationTestNoteV1::class);
        // autoload しないオプションは、オプションごとのキャッシュから消すと、次の読み取りで SELECT を発行する
        wp_cache_delete(self::OPTION, 'options');
        $missingTable = $wpdb->prefix . 'tmswp_no_such_table';
        $failRead = static fn(string $query): string => str_starts_with($query, 'SELECT') && str_contains($query, "'" . self::OPTION . "'")
            ? str_replace($wpdb->options, $missingTable, $query)
            : $query;
        add_filter('query', $failRead);
        $suppressed = $wpdb->suppress_errors();

        try {
            $migrator->status();
            self::fail('MigrationException was not thrown');
        } catch (MigrationException $exception) {
            self::assertStringStartsWith('failed to read the schema hashes: ', $exception->getMessage());
            self::assertStringContainsString('tmswp_no_such_table', $exception->getMessage());
        } finally {
            $wpdb->suppress_errors($suppressed);
            remove_filter('query', $failRead);
        }

        // 読み取りが直れば、保存済みのハッシュを読める
        self::assertTrue($migrator->status()[0]->current);
    }

    /** NoteV1 のテーブルを作り、保存されたハッシュを返す。 */
    private function migrateNotesV1(): string
    {
        return $this->migrateModel(ModelIntegrationTestNoteV1::class, self::NOTES_TABLE);
    }

    /** NoteV2(note 列を varchar(100) に広げた定義)の移行が MigrationException になることを確かめ、その例外を返す。 */
    private function migrateNotesV2Failing(): MigrationException
    {
        return $this->migrateFailing(ModelIntegrationTestNoteV2::class);
    }

    /**
     * $model のテーブルを作り(または定義を適用し)、保存されたハッシュを返す。
     *
     * @param class-string<Model> $model
     */
    private function migrateModel(string $model, string $table): string
    {
        $migrator = new Migrator(new Connection(self::wpdb()));
        $migrator->register($model);
        self::assertSame([[$table, MigrationAction::Migrated]], self::actions($migrator->migrateAll()));

        return $this->storedHash($table);
    }

    /**
     * $model の移行が MigrationException になり、$model の定義が保存済みのハッシュと食い違ったままであることを
     * 確かめ、その例外を返す。
     *
     * @param class-string<Model> $model
     */
    private function migrateFailing(string $model): MigrationException
    {
        $migrator = new Migrator(new Connection(self::wpdb()));
        $migrator->register($model);
        // 失敗させた ALTER のエラーを wpdb が画面に出さないようにする(last_error には残る)
        $suppressed = self::wpdb()->suppress_errors();

        try {
            $migrator->migrateAll();
        } catch (MigrationException $exception) {
            self::assertFalse($migrator->status()[0]->current);

            return $exception;
        } finally {
            self::wpdb()->suppress_errors($suppressed);
        }

        self::fail('MigrationException was not thrown');
    }

    /** NOTES_TABLE への ALTER を $replacement に差し替える(フィルタは tear_down で WP のテストライブラリが戻す)。 */
    private function rewriteNotesAlter(string $replacement): void
    {
        $notesTable = self::wpdb()->prefix . self::NOTES_TABLE;

        add_filter('query', static fn(string $query): string => str_starts_with($query, "ALTER TABLE {$notesTable} ") ? $replacement : $query);
    }

    private function storedHash(string $table): string
    {
        $hashes = get_option(self::OPTION);
        self::assertIsArray($hashes);
        $hash = $hashes[$table] ?? null;
        self::assertIsString($hash);

        return $hash;
    }

    /**
     * @param list<MigrationResult> $results
     *
     * @return list<array{string, MigrationAction}>
     */
    private static function actions(array $results): array
    {
        return array_map(static fn(MigrationResult $result): array => [$result->table, $result->action], $results);
    }

    /** これから発行される SQL を $this->queries に控える。 */
    private function recordQueries(): void
    {
        add_filter('query', function (string $query): string {
            $this->queries[] = $query;

            return $query;
        });
    }

    /** @return class-string<Model> */
    private function contactClass(): string
    {
        return self::fixtureClass(self::THEME_NAMESPACE . '\\Model\\Contact', Model::class);
    }

    /** 管理画面の admin_notices を発火し、出力を返す。 */
    private function adminNotices(): string
    {
        ob_start();
        do_action('admin_notices');

        return (string) ob_get_clean();
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

/** dbDelta の失敗の再現に使う、前回のデプロイ時点の定義。 */
final class ModelIntegrationTestNoteV1 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_notes')->id()->string('note', 50);
    }
}

/** NoteV1 の note 列を広げた定義。dbDelta は列の型を変える ALTER を発行する。 */
final class ModelIntegrationTestNoteV2 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_notes')->id()->string('note', 100);
    }
}

/** dbDelta が適用しない変更の再現に使う、前回のデプロイ時点の定義。 */
final class ModelIntegrationTestFlagV1 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_flags')->id()->bool('enabled')->string('label', 20, nullable: true);
    }
}

/** FlagV1 の enabled 列の既定値だけを変えた定義。 */
final class ModelIntegrationTestFlagDefaultV2 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_flags')->id()->bool('enabled', true)->string('label', 20, nullable: true);
    }
}

/** FlagV1 の label 列を NOT NULL にした定義。 */
final class ModelIntegrationTestFlagNotNullV2 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_flags')->id()->bool('enabled')->string('label', 20);
    }
}

/** 桁の多い整数の既定値の変更の再現に使う、前回のデプロイ時点の定義。 */
final class ModelIntegrationTestBigV1 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_big')->id()->bigint('val', default: 9007199254740992);
    }
}

/** BigV1 の既定値を1だけ増やした定義(float では区別できない)。 */
final class ModelIntegrationTestBigV2 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_big')->id()->bigint('val', default: 9007199254740993);
    }
}

/** 桁の多い DECIMAL の既定値の変更の再現に使う、前回のデプロイ時点の定義。 */
final class ModelIntegrationTestDecV1 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_dec')->id()->decimal('val', 30, 0, default: '9007199254740992');
    }
}

/** DecV1 の既定値を1だけ増やした定義(float では区別できない)。 */
final class ModelIntegrationTestDecV2 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_dec')->id()->decimal('val', 30, 0, default: '9007199254740993');
    }
}

/** 既定値の文字列に列の制約の語を含む定義。 */
final class ModelIntegrationTestConstraintWords extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_words')->id()->string('label', 20, nullable: true, default: 'NOT NULL');
    }
}

/** 小数秒の精度を付けた CURRENT_TIMESTAMP を既定値に持つ定義。 */
final class ModelIntegrationTestPreciseTimestamp extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_precise')->id()->rawColumn('created', 'timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)');
    }
}

/** 大きな既定値を指数の無い表記で書いた double の列。DB は `1e20` と返す。 */
final class ModelIntegrationTestLargeDouble extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_exponent')->id()->rawColumn('valuecol', 'double NOT NULL DEFAULT 100000000000000000000');
    }
}

/** 既定値を指数の表記で書いた double の列。DB は `0.001` と返す。 */
final class ModelIntegrationTestSmallDouble extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_exponent')->id()->rawColumn('valuecol', 'double DEFAULT 1E-3');
    }
}

/** double で表せない桁の既定値を書いた列。DB は丸めて `1e20` と記録する。 */
final class ModelIntegrationTestRoundedDouble extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_rounded')->id()->rawColumn('valuecol', 'double NOT NULL DEFAULT 100000000000000000001');
    }
}
