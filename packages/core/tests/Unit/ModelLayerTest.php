<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;
use Stringable;
use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Exception\HydrationException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\MigrationException;
use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Exception\SchemaException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\MigrationResult;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\ModelInitializer;
use TMSWP\Core\Model\QueryBuilder;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Core\Tests\Support\WpdbTestDouble;

final class ModelLayerTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        TestContactModel::resetConnections();
        TestCounterModel::resetConnections();
        parent::tearDown();
    }

    public function testTableDefinitionBuildsDbDeltaCompatibleSql(): void
    {
        $connection = new Connection(WpdbTestDouble::create());
        $definition = TableDefinition::create('contacts')
            ->id()
            ->string('email', default: '')
            ->text('body')
            ->bool('active', true)
            ->datetime('created_at', default: 'CURRENT_TIMESTAMP')
            ->foreignId('user_id')
            ->unique('email', 'email_unique')
            ->charset('utf8mb4')
            ->collation('utf8mb4_unicode_ci');

        self::assertSame(<<<'SQL'
CREATE TABLE wp_contacts (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
email varchar(191) NOT NULL DEFAULT '',
body text DEFAULT NULL,
active tinyint(1) NOT NULL DEFAULT 1,
created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
user_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (id),
KEY user_id (user_id),
UNIQUE KEY email_unique (email)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL, $definition->toSql($connection));
        self::assertSame(['id', 'email', 'body', 'active', 'created_at', 'user_id'], $definition->columns());
        self::assertSame(['id'], $definition->primaryColumns());
    }

    public function testTableDefinitionRejectsInvalidSchema(): void
    {
        $this->expectException(SchemaException::class);

        TableDefinition::create('contacts')
            ->id()
            ->text('body')
            ->index('body');
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testQueryBuilderBuildsSqlAndKeepsValuePluckRaw(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);

        $wpdb->queueRow(['email' => 'a@example.test']);
        $email = (new QueryBuilder($connection, 'contacts', TestContactRow::class))
            ->where('id', 10)
            ->value('email');

        self::assertSame('a@example.test', $email);
        self::assertSame('SELECT email FROM wp_contacts WHERE id = 10 LIMIT 1', $wpdb->queries()[0]);

        $wpdb->queueResultSet([
            ['id' => '1', 'email' => 'a@example.test'],
            ['id' => '2', 'email' => 'b@example.test'],
        ]);
        $emails = (new QueryBuilder($connection, 'contacts', TestContactRow::class))
            ->orderBy('id')
            ->pluck('email', 'id');

        self::assertSame([1 => 'a@example.test', 2 => 'b@example.test'], $emails);
        self::assertSame('SELECT email, id FROM wp_contacts ORDER BY id ASC', $wpdb->queries()[1]);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testQueryBuilderHydratesStrictConstructorValues(): void
    {
        WpdbTestDouble::defineOutputConstants();
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('Asia/Tokyo'));

        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet([[
            'id' => '10',
            'email' => 'a@example.test',
            'active' => '1',
            'meta' => '{"tags":["news"]}',
            'created_at' => '2026-06-13 09:10:11',
            'status' => 'published',
        ]]);
        $rows = (new QueryBuilder(new Connection($wpdb), 'contacts'))
            ->as(TestContactRow::class)
            ->get();

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertInstanceOf(TestContactRow::class, $row);
        self::assertSame(10, $row->id);
        self::assertTrue($row->active);
        self::assertSame(['tags' => ['news']], $row->meta);
        self::assertSame('2026-06-13 09:10:11', $row->createdAt->format('Y-m-d H:i:s'));
        self::assertSame(TestStatus::Published, $row->status);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testQueryBuilderRejectsInvalidHydrationAndUnsafeRawSql(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet([[
            'id' => 'not-int',
            'email' => 'a@example.test',
            'active' => '1',
            'meta' => '{}',
            'created_at' => '2026-06-13 09:10:11',
            'status' => 'published',
        ]]);

        $this->expectException(HydrationException::class);
        (new QueryBuilder(new Connection($wpdb), 'contacts'))->as(TestContactRow::class)->get();
    }

    public function testQueryBuilderRejectsInvalidQueryShapes(): void
    {
        $connection = new Connection(WpdbTestDouble::create());

        self::assertSame(
            'SELECT * FROM wp_contacts WHERE 0 = 1',
            (new QueryBuilder($connection, 'contacts'))->whereIn('id', [])->toSql(),
        );

        $this->expectException(InvalidQueryException::class);
        (new QueryBuilder($connection, 'contacts'))->offset(10)->toSql();
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testModelFacadeUsesConfiguredConnection(): void
    {
        WpdbTestDouble::defineOutputConstants();
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('UTC'));

        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        TestContactModel::useConnection($connection);
        TestCounterModel::useConnection($connection);

        self::assertSame(1, TestContactModel::insert(['email' => 'a@example.test', 'active' => true]));
        self::assertSame([
            'table' => 'wp_contacts',
            'data' => ['email' => 'a@example.test', 'active' => 1],
            'format' => ['%s', '%d'],
        ], $wpdb->insertCalls()[0]);

        self::assertSame(2, TestCounterModel::insert([]));
        self::assertSame('INSERT INTO wp_counters () VALUES ()', $wpdb->queries()[0]);

        $wpdb->queueResultSet([[
            'id' => '10',
            'email' => 'b@example.test',
            'active' => '0',
            'meta' => '{}',
            'created_at' => '2026-06-13 00:00:00',
            'status' => 'draft',
        ]]);
        $found = TestContactModel::find(10);

        self::assertInstanceOf(TestContactRow::class, $found);
        self::assertSame('b@example.test', $found->email);
        self::assertSame(1, TestContactModel::update(10, ['email' => 'c@example.test']));
        self::assertSame(1, TestContactModel::delete(10));
        self::assertSame('UPDATE wp_contacts SET email = \'c@example.test\' WHERE id = 10', $wpdb->queries()[2]);
        self::assertSame('DELETE FROM wp_contacts WHERE id = 10', $wpdb->queries()[3]);
    }

    /**
     * @param Closure(Connection): int $write
     */
    #[DataProvider('failingWrites')]
    public function testConnectionThrowsQueryExceptionWhenWpdbWriteReturnsFalse(Closure $write, string $expectedMessage): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->failNextWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($expectedMessage);

        $write($connection);
    }

    /**
     * @return iterable<string, array{Closure(Connection): int, string}>
     */
    public static function failingWrites(): iterable
    {
        yield 'execute' => [
            static fn(Connection $connection): int => $connection->execute('UPDATE wp_contacts SET active = %d', [0]),
            'query failed: UPDATE wp_contacts SET active = 0',
        ];
        yield 'insert' => [
            static fn(Connection $connection): int => $connection->insert('contacts', ['email' => 'a@example.test']),
            'insert failed: wp_contacts',
        ];
        yield 'update' => [
            static fn(Connection $connection): int => $connection->update('contacts', ['email' => 'b@example.test'], ['id' => 10]),
            'update failed: wp_contacts',
        ];
        yield 'delete' => [
            static fn(Connection $connection): int => $connection->delete('contacts', ['id' => 10]),
            'delete failed: wp_contacts',
        ];
    }

    public function testConnectionReportsWpdbLastErrorAndRecoversOnNextWrite(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->failNextWrite("Duplicate entry 'a@example.test' for key 'email_unique'");

        try {
            $connection->insert('contacts', ['email' => 'a@example.test']);
            self::fail('wpdb の失敗が QueryException になっていません。');
        } catch (QueryException $exception) {
            self::assertSame(
                "database error: Duplicate entry 'a@example.test' for key 'email_unique'; SQL: INSERT INTO wp_contacts",
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $connection->insert('contacts', ['email' => 'b@example.test']));
        self::assertSame('', $connection->lastError());
        self::assertCount(2, $wpdb->insertCalls());
    }

    public function testConnectionWritesNormalizeValuesByR3(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $createdAt = new DateTimeImmutable('2026-06-13 09:10:11', new DateTimeZone('Asia/Tokyo'));

        $connection->insert('contacts', [
            'email' => new TestEmail('a@example.test'),
            'active' => true,
            'created_at' => $createdAt,
            'status' => TestStatus::Published,
            'priority' => TestPriority::High,
            'deleted_at' => null,
        ]);
        $connection->update('contacts', ['status' => TestStatus::Draft, 'deleted_at' => null], ['created_at' => $createdAt, 'priority' => TestPriority::High]);
        $connection->delete('contacts', ['status' => TestStatus::Draft, 'deleted_at' => null]);

        self::assertSame([
            'table' => 'wp_contacts',
            'data' => [
                'email' => 'a@example.test',
                'active' => 1,
                'created_at' => '2026-06-13 09:10:11',
                'status' => 'published',
                'priority' => 2,
                'deleted_at' => null,
            ],
            'format' => ['%s', '%d', '%s', '%s', '%d', '%s'],
        ], $wpdb->insertCalls()[0]);
        self::assertSame([
            'table' => 'wp_contacts',
            'data' => ['status' => 'draft', 'deleted_at' => null],
            'where' => ['created_at' => '2026-06-13 09:10:11', 'priority' => 2],
            'format' => ['%s', '%s'],
            'where_format' => ['%s', '%d'],
        ], $wpdb->updateCalls()[0]);
        self::assertSame([
            'table' => 'wp_contacts',
            'where' => ['status' => 'draft', 'deleted_at' => null],
            'where_format' => ['%s', '%s'],
        ], $wpdb->deleteCalls()[0]);
    }

    /**
     * @param Closure(Connection): int $write
     */
    #[DataProvider('writesWithNonBareTableName')]
    public function testConnectionWritesAcceptOnlyBareTableNames(Closure $write): void
    {
        $wpdb = WpdbTestDouble::create();

        try {
            $write(new Connection($wpdb));
            self::fail('ベア名でないテーブル名が InvalidQueryException になっていません。');
        } catch (InvalidQueryException $exception) {
            self::assertSame('invalid bare table name: Wp_contacts', $exception->getMessage());
        }

        self::assertSame([], $wpdb->insertCalls());
        self::assertSame([], $wpdb->updateCalls());
        self::assertSame([], $wpdb->deleteCalls());
    }

    /**
     * @return iterable<string, array{Closure(Connection): int}>
     */
    public static function writesWithNonBareTableName(): iterable
    {
        yield 'insert' => [static fn(Connection $connection): int => $connection->insert('Wp_contacts', ['email' => 'a@example.test'])];
        yield 'update' => [static fn(Connection $connection): int => $connection->update('Wp_contacts', ['email' => 'a@example.test'], ['id' => 10])];
        yield 'delete' => [static fn(Connection $connection): int => $connection->delete('Wp_contacts', ['id' => 10])];
    }

    public function testConnectionPrepareTurnsEscapedPercentIntoLiteralWithoutBindings(): void
    {
        $connection = new Connection(WpdbTestDouble::create());

        self::assertSame('SELECT id % 2 FROM wp_contacts', $connection->prepare('SELECT id %% 2 FROM wp_contacts'));
        self::assertSame(
            'SELECT id % 2 AS odd FROM wp_contacts',
            (new QueryBuilder($connection, 'contacts'))->selectRaw('id %% 2 AS odd')->toSql(),
        );
    }

    /**
     * @param list<mixed> $bindings
     */
    #[DataProvider('invalidPlaceholderSql')]
    public function testConnectionPrepareRejectsUnsupportedPercentSequences(string $sql, array $bindings, string $expectedMessage): void
    {
        $connection = new Connection(WpdbTestDouble::create());

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage($expectedMessage);

        $connection->prepare($sql, $bindings);
    }

    /**
     * @return iterable<string, array{string, list<mixed>, string}>
     */
    public static function invalidPlaceholderSql(): iterable
    {
        yield 'placeholder right after an escaped percent' => [
            'SELECT id FROM wp_contacts WHERE email LIKE %%%s',
            [],
            'a placeholder must not directly follow %%',
        ];
        yield 'numbered placeholder' => [
            'SELECT id FROM wp_contacts WHERE email = %1$s',
            ['a@example.test'],
            "unsupported percent sequence '%1'",
        ];
        yield 'uppercase float placeholder' => [
            'SELECT id FROM wp_contacts WHERE score = %F',
            [1.5],
            "unsupported percent sequence '%F'",
        ];
        yield 'unescaped literal percent' => [
            'SELECT DATE_FORMAT(created_at, %Y) FROM wp_contacts',
            [],
            "unsupported percent sequence '%Y'",
        ];
        yield 'trailing percent' => [
            'SELECT id % FROM wp_contacts',
            [],
            "unsupported percent sequence '% '",
        ];
    }

    public function testQueryBuilderUpdateAssignsNull(): void
    {
        $wpdb = WpdbTestDouble::create();

        $affected = (new QueryBuilder(new Connection($wpdb), 'contacts'))
            ->where('id', 10)
            ->update(['deleted_at' => null, 'email' => 'a@example.test']);

        self::assertSame(1, $affected);
        self::assertSame("UPDATE wp_contacts SET deleted_at = NULL, email = 'a@example.test' WHERE id = 10", $wpdb->queries()[0]);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testValueAndPluckKeepExistingSelectClause(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);

        $wpdb->queueRow(['next_id' => '11']);
        $nextId = (new QueryBuilder($connection, 'contacts'))
            ->selectRaw('MAX(id) + %d AS next_id', [1])
            ->value('next_id');

        self::assertSame('11', $nextId);
        self::assertSame('SELECT MAX(id) + 1 AS next_id FROM wp_contacts LIMIT 1', $wpdb->queries()[0]);

        $wpdb->queueResultSet([
            ['status' => 'draft', 'total' => '2'],
            ['status' => 'published', 'total' => '5'],
        ]);
        $totals = (new QueryBuilder($connection, 'contacts'))
            ->alias('c')
            ->select('c.status')
            ->selectRaw('COUNT(*) AS total')
            ->where('c.active', true)
            ->groupBy('c.status')
            ->pluck('total', 'c.status');

        self::assertSame(['draft' => '2', 'published' => '5'], $totals);
        self::assertSame('SELECT c.status, COUNT(*) AS total FROM wp_contacts AS c WHERE c.active = 1 GROUP BY c.status', $wpdb->queries()[1]);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testValueAndPluckRejectMissingResultColumns(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $thrown = [];

        $wpdb->queueRow(['total' => '3']);
        $wpdb->queueResultSet([['total' => '3']]);
        $wpdb->queueResultSet([['email' => 'a@example.test']]);
        $wpdb->queueResultSet([['email' => 'a@example.test', 'id' => null]]);
        $cases = [
            'value の列が結果に無い' => static fn(): mixed => (new QueryBuilder($connection, 'contacts'))->selectRaw('COUNT(*) AS total')->value('email'),
            'pluck の値の列が結果に無い' => static fn(): mixed => (new QueryBuilder($connection, 'contacts'))->selectRaw('COUNT(*) AS total')->pluck('email'),
            'pluck のキーの列が結果に無い' => static fn(): mixed => (new QueryBuilder($connection, 'contacts'))->select('email')->pluck('email', 'id'),
            'pluck のキーが NULL' => static fn(): mixed => (new QueryBuilder($connection, 'contacts'))->pluck('email', 'id'),
        ];

        foreach ($cases as $label => $case) {
            try {
                $case();
                $thrown[$label] = null;
            } catch (QueryException $exception) {
                $thrown[$label] = $exception::class;
            }
        }

        self::assertSame(array_fill_keys(array_keys($cases), QueryException::class), $thrown);
    }

    public function testCountDiscardsOrderLimitAndOffset(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);

        $wpdb->queueVar('3');
        $grouped = (new QueryBuilder($connection, 'contacts'))
            ->where('active', true)
            ->groupBy('status')
            ->orderByDesc('status')
            ->limit(10)
            ->offset(20)
            ->count();

        $wpdb->queueVar('7');
        $offsetOnly = (new QueryBuilder($connection, 'contacts'))->orderBy('id')->offset(5)->count();

        self::assertSame(3, $grouped);
        self::assertSame(7, $offsetOnly);
        self::assertSame([
            'SELECT COUNT(*) FROM (SELECT 1 FROM wp_contacts WHERE active = 1 GROUP BY status) AS tmswp_count',
            'SELECT COUNT(*) FROM wp_contacts',
        ], $wpdb->queries());
    }

    public function testWhereInRejectsNonListValues(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('whereIn values must be a list');

        // list でない配列は PHPStan が呼び出し側で弾くため、静的解析を通らないアプリのコードを再現するリフレクションで渡す
        (new ReflectionMethod(QueryBuilder::class, 'whereIn'))
            ->invoke(new QueryBuilder(new Connection(WpdbTestDouble::create()), 'contacts'), 'id', ['first' => 1, 'second' => 2]);
    }

    public function testJoinRejectsNonBareTableNameWithPrefix(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('invalid bare table name: Users');

        (new QueryBuilder(new Connection(WpdbTestDouble::create()), 'contacts'))->innerJoin('Users AS u', 'u.ID', '=', 'contacts.user_id');
    }

    public function testWhereGroupRejectsAlias(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('where group can contain only where clauses');

        (new QueryBuilder(new Connection(WpdbTestDouble::create()), 'contacts'))
            ->where(static fn(QueryBuilder $group): QueryBuilder => $group->alias('c')->where('c.id', 1));
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHydrationUsesConstructorDefaultForMissingColumn(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet([['id' => '10']]);

        $rows = (new QueryBuilder(new Connection($wpdb), 'contacts'))->as(TestNoteRow::class)->get();

        self::assertEquals([new TestNoteRow(10, 'unset')], $rows);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHydrationCastsStringValueForIntBackedEnum(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet([['id' => '10', 'priority' => '2']]);

        $rows = (new QueryBuilder(new Connection($wpdb), 'contacts'))->as(TestPriorityRow::class)->get();

        self::assertEquals([new TestPriorityRow(10, TestPriority::High)], $rows);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHydrationRejectsIntegersOutsidePhpRange(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet([['id' => '9223372036854775808', 'note' => 'big']]);

        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage("integer out of range for 'id'");

        (new QueryBuilder(new Connection($wpdb), 'contacts'))->as(TestNoteRow::class)->get();
    }

    public function testMigratorRegistersModelsAndBuildsDryRunPlan(): void
    {
        Functions\when('get_option')->justReturn(false);

        $migrator = new Migrator(new Connection(WpdbTestDouble::create()));
        $migrator->register(TestContactModel::class);
        $results = $migrator->migrateAll(dryRun: true);

        self::assertContainsOnlyInstancesOf(MigrationResult::class, $results);
        self::assertSame('contacts', $results[0]->table);
        self::assertSame('pending', $results[0]->action);
        self::assertSame([TestContactModel::class], $migrator->models());
    }

    public function testMigratorRejectsDuplicateTables(): void
    {
        $migrator = new Migrator(new Connection(WpdbTestDouble::create()));
        $migrator->register(TestContactModel::class);

        $this->expectException(MigrationException::class);
        $migrator->register(DuplicateContactModel::class);
    }

    public function testAsModelUsesModelInitializer(): void
    {
        self::assertSame(ModelInitializer::class, (new AsModel())->initializerClass());
    }
}

enum TestStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

enum TestPriority: int
{
    case Low = 1;
    case High = 2;
}

final readonly class TestEmail implements Stringable
{
    public function __construct(
        private string $address,
    ) {}

    public function __toString(): string
    {
        return $this->address;
    }
}

final readonly class TestPriorityRow
{
    public function __construct(
        public int $id,
        public TestPriority $priority,
    ) {}
}

final readonly class TestNoteRow
{
    public function __construct(
        public int $id,
        public string $note = 'unset',
    ) {}
}

final readonly class TestContactRow
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public int $id,
        public string $email,
        public bool $active,
        public array $meta,
        public DateTimeImmutable $createdAt,
        public TestStatus $status,
    ) {}
}

final class TestContactModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('contacts')
            ->id()
            ->string('email')
            ->bool('active')
            ->json('meta')
            ->datetime('created_at')
            ->string('status')
            ->unique('email', 'email_unique');
    }

    public static function rowClass(): string
    {
        return TestContactRow::class;
    }
}

final class DuplicateContactModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('contacts')
            ->id()
            ->string('code');
    }
}

final class TestCounterModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('counters')->id();
    }

    public static function rowClass(): ?string
    {
        return null;
    }
}
