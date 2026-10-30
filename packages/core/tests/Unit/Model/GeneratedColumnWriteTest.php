<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\GeneratedColumnStorage;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\QueryBuilder;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * 生成カラムへの書き込みの拒否(Model層の設計書の「生成カラム」)。Model::insert()・update()とModel::query()のupdate()は、DBに送る前に
 * InvalidQueryExceptionを投げる。テーブルの定義を持たないConnectionと、定義を渡さずに作ったQueryBuilderは検査しない。
 */
final class GeneratedColumnWriteTest extends UnitTestCase
{
    private WpdbTestDouble $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = WpdbTestDouble::create();
        Model::useConnection(new Connection($this->wpdb));
    }

    protected function tearDown(): void
    {
        Model::useConnection(null);
        parent::tearDown();
    }

    /** @return iterable<string, array{Closure(): int, string}> */
    public static function rejectedWrites(): iterable
    {
        yield 'Model::insert()' => [
            static fn(): int => GeneratedColumnWriteTestAccountModel::insert(['account_type' => 'corp_admin', 'active_key' => 1]),
            'insert() cannot write to the generated column active_key; the database computes its value',
        ];
        yield 'Model::insert()のNULL' => [
            static fn(): int => GeneratedColumnWriteTestAccountModel::insert(['active_key' => null]),
            'insert() cannot write to the generated column active_key; the database computes its value',
        ];
        yield 'Model::update()' => [
            static fn(): int => GeneratedColumnWriteTestAccountModel::update(['status' => 'active', 'label' => 'x'], 1),
            'update() cannot assign the generated column label; the database computes its value',
        ];
        yield 'Model::update()の条件のマップ' => [
            static fn(): int => GeneratedColumnWriteTestAccountModel::update(['active_key' => 2], ['status' => 'active']),
            'update() cannot assign the generated column active_key; the database computes its value',
        ];
        yield 'Model::query()->update()' => [
            static fn(): int => GeneratedColumnWriteTestAccountModel::query()->where('id', 1)->update(['active_key' => null]),
            'update() cannot assign the generated column active_key; the database computes its value',
        ];
        yield 'Model::insert()の大文字の列名' => [
            static fn(): int => GeneratedColumnWriteTestAccountModel::insert(['Active_Key' => 1]),
            'insert() cannot write to the generated column active_key; the database computes its value',
        ];
        yield 'Model::update()の大文字の列名' => [
            static fn(): int => GeneratedColumnWriteTestAccountModel::update(['LABEL' => 'x'], 1),
            'update() cannot assign the generated column label; the database computes its value',
        ];
        yield '定義を渡したQueryBuilder' => [
            static fn(): int => (new QueryBuilder(Model::connection(), 'accounts', null, GeneratedColumnWriteTestAccountModel::table()))->where('id', 1)->update(['label' => 'x']),
            'update() cannot assign the generated column label; the database computes its value',
        ];
    }

    /** 生成カラムへの書き込みはInvalidQueryExceptionになり、DBに何も送らない。 */
    #[DataProvider('rejectedWrites')]
    public function testWriteToGeneratedColumnIsRejectedBeforeTheDatabase(Closure $write, string $message): void
    {
        try {
            $write();
            self::fail('expected InvalidQueryException');
        } catch (InvalidQueryException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame([[], []], [$this->wpdb->queries(), $this->wpdb->insertCalls()]);
    }

    /** 条件の生成カラムは拒否しない。通常の列だけの書き込みは、そのままDBに送る。 */
    public function testGeneratedColumnInConditionsAndRegularWritesAreAllowed(): void
    {
        $this->wpdb->queueInsertId(7);
        $this->wpdb->queueAffectedRows(1);
        $this->wpdb->queueAffectedRows(2);
        $this->wpdb->queueAffectedRows(1);

        self::assertSame(7, GeneratedColumnWriteTestAccountModel::insert(['account_type' => 'corp_admin', 'corp_id' => 3]));
        self::assertSame(1, GeneratedColumnWriteTestAccountModel::update(['status' => 'expired'], ['active_key' => 3]));
        self::assertSame(2, GeneratedColumnWriteTestAccountModel::query()->where('active_key', 3)->update(['status' => 'deleted']));
        self::assertSame(1, GeneratedColumnWriteTestAccountModel::delete(['label' => 'corp_admin:3']));
        self::assertSame([
            "UPDATE wp_accounts SET status = 'expired' WHERE active_key = 3",
            "UPDATE wp_accounts SET status = 'deleted' WHERE active_key = 3",
            "DELETE FROM wp_accounts WHERE label = 'corp_admin:3'",
        ], $this->wpdb->queries());
    }

    /** テーブルの定義を持たないConnectionの書き込みと、定義を渡さずに作ったQueryBuilderは検査しない。 */
    public function testConnectionAndQueryBuilderWithoutDefinitionAreNotChecked(): void
    {
        $connection = Model::connection();
        $this->wpdb->queueInsertId(1);
        $this->wpdb->queueAffectedRows(1);
        $this->wpdb->queueAffectedRows(1);

        $connection->insert('accounts', ['active_key' => 1]);
        $connection->update('accounts', ['active_key' => 1], ['id' => 1]);
        (new QueryBuilder($connection, 'accounts'))->where('id', 1)->update(['active_key' => 1]);

        self::assertSame(['active_key' => 1], $this->wpdb->insertCalls()[0]['data']);
        self::assertSame(['active_key' => 1], $this->wpdb->updateCalls()[0]['data']);
        self::assertSame(['UPDATE wp_accounts SET active_key = 1 WHERE id = 1'], $this->wpdb->queries());
    }
}

final class GeneratedColumnWriteTestAccountModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('accounts')
            ->id()
            ->string('account_type', 20, default: '')
            ->string('status', 20, default: 'active')
            ->bigint('corp_id', nullable: true, unsigned: true)
            ->generated('active_key', 'bigint(20) unsigned', "CASE WHEN status = 'active' THEN corp_id ELSE NULL END", GeneratedColumnStorage::Stored)
            ->generated('label', 'varchar(40)', "CONCAT(account_type, ':', corp_id)", GeneratedColumnStorage::Virtual);
    }
}
