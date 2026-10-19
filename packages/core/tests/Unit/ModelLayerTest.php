<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Exception\HydrationException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\MigrationException;
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

    public function testQueryBuilderBuildsSqlAndKeepsValuePluckRaw(): void
    {
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

    public function testQueryBuilderHydratesStrictConstructorValues(): void
    {
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

    public function testQueryBuilderRejectsInvalidHydrationAndUnsafeRawSql(): void
    {
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

    public function testModelFacadeUsesConfiguredConnection(): void
    {
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('UTC'));

        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        TestContactModel::useConnection($connection);
        TestCounterModel::useConnection($connection);

        self::assertSame(1, TestContactModel::insert(['email' => 'a@example.test', 'active' => true]));
        self::assertSame([
            'table' => 'wp_contacts',
            'data' => ['email' => 'a@example.test', 'active' => true],
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
