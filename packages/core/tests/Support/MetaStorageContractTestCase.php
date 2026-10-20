<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\ValueCaster;

/**
 * MetaStorageInterfaceの実装が満たす契約(docs/04 §4.5)を確かめる抽象テストケース。
 *
 * 実装ごとに、このクラスを継承した具体的なテストクラスを置き、storage()・objectType()・createObject()を実装する。
 * 実装が扱えるキーに制約がある場合(フィールドとして登録したキーだけを保存する実装等)は、key()を上書きして、
 * 契約のテストが使うキーの名前を実装の扱えるキーに対応させる。
 *
 * 契約:
 * - has()は行の有無で、値の真偽ではない。get()は行が無ければnull、getAll()は空配列、getMultiple()は行のある
 *   キーだけを含む。
 * -書き込み(set・setAll・delete)は、書き込んだらtrue、書き込まなかった(同じ値・行が無い)らfalseを返し、
 *   データベースのエラーはRuntimeExceptionにする。nullは保存せずInvalidArgumentExceptionにする。
 * -受け取った値を元の値として保存する(バックスラッシュを含む文字列が往復する)。
 * - setAll()は同じキーの全行を置き換え、delete()は全行を消す。delete()は冪等。
 *
 * データベースのエラーは、そのキーの書き込みのSQLを存在しないテーブルへのSQLに書き換えて起こす。WordPressの
 * データベース($wpdb)に保存しない実装は、そのテストを上書きして、その実装でのデータベースのエラーを起こす。
 */
abstract class MetaStorageContractTestCase extends IntegrationTestCase
{
    abstract protected function storage(): MetaStorageInterface;

    abstract protected function objectType(): ObjectType;

    /** メタを保存する対象を作り、そのIDを返す。 */
    abstract protected function createObject(): int;

    /** 契約のテストが使うキーの名前を、実装の扱えるキーに対応させる。 */
    protected function key(string $name): string
    {
        return 'tmswp_contract_' . $name;
    }

    public function testRowlessKeyIsAbsentFromEveryRead(): void
    {
        $storage = $this->storage();
        $id = $this->createObject();
        $key = $this->key('absent');

        self::assertFalse($storage->has($this->objectType(), $id, $key));
        self::assertNull($storage->get($this->objectType(), $id, $key));
        self::assertSame([], $storage->getAll($this->objectType(), $id, $key));
        self::assertSame([], $storage->getMultiple($this->objectType(), $id, [$key]));
    }

    /**
     * has()とget()は分かれており、false・空文字・'0'を保存した行も「ある」になる(nullを「行なし」の印に使わない)。
     * getMultiple()は、値が偽でも行のあるキーを結果に含める。
     */
    public function testFalsyValuesAreStoredRowsThatHasAndGetMultipleReport(): void
    {
        $storage = $this->storage();
        $id = $this->createObject();
        $empty = $this->key('empty');
        $zero = $this->key('zero');
        $false = $this->key('false');
        $absent = $this->key('absent');

        self::assertTrue($storage->set($this->objectType(), $id, $empty, ''));
        self::assertTrue($storage->set($this->objectType(), $id, $zero, '0'));
        self::assertTrue($storage->set($this->objectType(), $id, $false, false));

        foreach ([$empty, $zero, $false] as $key) {
            self::assertTrue($storage->has($this->objectType(), $id, $key), $key);
        }

        self::assertSame('', $storage->get($this->objectType(), $id, $empty));
        self::assertSame('0', $storage->get($this->objectType(), $id, $zero));
        // 真偽値の保存の形は実装による(WordPressのメタはfalseを空文字で保存する)。nullではなく、偽として読める
        $storedFalse = $storage->get($this->objectType(), $id, $false);
        self::assertNotNull($storedFalse);
        self::assertFalse(ValueCaster::toBool($storedFalse));

        $multiple = $storage->getMultiple($this->objectType(), $id, [$empty, $zero, $false, $absent]);
        self::assertSame([$empty, $zero, $false], array_keys($multiple));
        self::assertSame('', $multiple[$empty]);
        self::assertSame('0', $multiple[$zero]);
    }

    /** set()は、書き込んだらtrue、同じ値で書き込まなかったらfalseを返し、行を置き換える。 */
    public function testSetReportsWhetherItWrote(): void
    {
        $storage = $this->storage();
        $id = $this->createObject();
        $key = $this->key('single');

        self::assertTrue($storage->set($this->objectType(), $id, $key, 'first'));
        self::assertFalse($storage->set($this->objectType(), $id, $key, 'first'));
        self::assertTrue($storage->set($this->objectType(), $id, $key, 'second'));

        self::assertSame('second', $storage->get($this->objectType(), $id, $key));
        self::assertSame(['second'], $storage->getAll($this->objectType(), $id, $key));
    }

    /** nullは保存せずInvalidArgumentExceptionにする(set・setAllとも、何も保存しない)。 */
    public function testNullIsRejectedWithoutStoringAnything(): void
    {
        $storage = $this->storage();
        $id = $this->createObject();
        $type = $this->objectType();
        $key = $this->key('null');

        foreach (['set' => static fn(): bool => $storage->set($type, $id, $key, null), 'setAll' => static fn(): bool => $storage->setAll($type, $id, $key, ['kept', null])] as $name => $write) {
            try {
                $write();
                self::fail("InvalidArgumentException was not thrown for {$name}");
            } catch (InvalidArgumentException) {
                self::assertFalse($storage->has($type, $id, $key), $name);
            }
        }
    }

    /**
     * setAll()は同じキーの全行を置き換え、行が変わったらtrue、置き換えの前と同じ行ならfalseを返す。get()・getMultiple()は
     * 先頭の行を返す。
     */
    public function testSetAllReplacesEveryRowAndReportsWhetherTheRowsChanged(): void
    {
        $storage = $this->storage();
        $id = $this->createObject();
        $key = $this->key('rows');

        self::assertTrue($storage->setAll($this->objectType(), $id, $key, ['alpha', 'beta']));
        self::assertSame(['alpha', 'beta'], $storage->getAll($this->objectType(), $id, $key));
        self::assertFalse($storage->setAll($this->objectType(), $id, $key, ['alpha', 'beta']));
        self::assertTrue($storage->setAll($this->objectType(), $id, $key, ['gamma']));

        self::assertSame(['gamma'], $storage->getAll($this->objectType(), $id, $key));
        self::assertSame('gamma', $storage->get($this->objectType(), $id, $key));
        self::assertSame([$key => 'gamma'], $storage->getMultiple($this->objectType(), $id, [$key]));
    }

    /** delete()は全行を消してtrueを返し、行が無ければ何もせずfalseを返す(冪等)。 */
    public function testDeleteRemovesEveryRowAndIsIdempotent(): void
    {
        $storage = $this->storage();
        $id = $this->createObject();
        $key = $this->key('deleted');
        $storage->setAll($this->objectType(), $id, $key, ['one', 'two']);

        self::assertTrue($storage->delete($this->objectType(), $id, $key));
        self::assertFalse($storage->has($this->objectType(), $id, $key));
        self::assertSame([], $storage->getAll($this->objectType(), $id, $key));
        self::assertFalse($storage->delete($this->objectType(), $id, $key));
    }

    /** バックスラッシュを含む文字列(json_encode()の出力・Windowsのパス・正規表現)と、それを含む配列・行が往復する。 */
    public function testValuesWithBackslashesRoundTrip(): void
    {
        $storage = $this->storage();
        $id = $this->createObject();
        $json = json_encode(['path' => 'C:\\Users\\tmswp', 'pattern' => '/^\\d+$/'], JSON_THROW_ON_ERROR);
        $array = ['share' => '\\\\server\\share', 'nested' => ['regex' => '\\w+']];
        $rows = ['a\\b', '{"k":"v\\\\n"}'];

        $storage->set($this->objectType(), $id, $this->key('json'), $json);
        $storage->set($this->objectType(), $id, $this->key('array'), $array);
        $storage->setAll($this->objectType(), $id, $this->key('slashed_rows'), $rows);

        self::assertSame($json, $storage->get($this->objectType(), $id, $this->key('json')));
        self::assertSame($array, $storage->get($this->objectType(), $id, $this->key('array')));
        self::assertSame($rows, $storage->getAll($this->objectType(), $id, $this->key('slashed_rows')));
    }

    /**
     * 書き込みの間のデータベースのエラーは、false(書き込まなかった)ではなく、データベースのエラーをメッセージに含む
     * RuntimeExceptionになり、保存されていた値は変わらない。
     */
    public function testDatabaseErrorDuringWriteThrowsRuntimeException(): void
    {
        $storage = $this->storage();
        $id = $this->createObject();
        $key = $this->key('broken');
        $storage->set($this->objectType(), $id, $key, 'before');
        $wpdb = self::wpdb();
        $broken = 'UPDATE ' . $wpdb->prefix . 'tmswp_missing_table SET meta_value = 1';
        $breakQueries = static fn(string $query): string => str_contains($query, $key) && preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $query) === 1 ? $broken : $query;
        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $breakQueries);

        try {
            $storage->set($this->objectType(), $id, $key, 'after');
            self::fail('RuntimeException was not thrown');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tmswp_missing_table', $exception->getMessage());
        } finally {
            remove_filter('query', $breakQueries);
            $wpdb->suppress_errors($suppressed);
        }

        wp_cache_flush();
        self::assertSame('before', $storage->get($this->objectType(), $id, $key));
    }
}
