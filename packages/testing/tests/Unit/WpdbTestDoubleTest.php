<?php

declare(strict_types=1);

namespace TMSWP\Testing\Tests\Unit;

use InvalidArgumentException;
use stdClass;
use Stringable;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * wpdbの代役が、実wpdbと同じ形のSQLを返すこと(実wpdbとの照合は統合層のWpdbTestDoubleParityTest)。
 */
final class WpdbTestDoubleTest extends UnitTestCase
{
    /** 実wpdbと同じく、`%%`から戻した`%`と値の中の`%`を、placeholder_escape()に置き換えて返す。 */
    public function testPrepareReplacesPercentWithPlaceholderEscape(): void
    {
        $wpdb = WpdbTestDouble::create();
        $escape = $wpdb->placeholder_escape();

        $sql = $wpdb->prepare('SELECT id %% 2 FROM wp_contacts WHERE email LIKE %s', '%news%');

        self::assertMatchesRegularExpression('/\A\{[0-9a-f]{64}\}\z/', $escape);
        self::assertSame("SELECT id {$escape} 2 FROM wp_contacts WHERE email LIKE '{$escape}news{$escape}'", $sql);
        self::assertSame("SELECT id % 2 FROM wp_contacts WHERE email LIKE '%news%'", $wpdb->remove_placeholder_escape($sql));
        self::assertSame($sql, $wpdb->add_placeholder_escape("SELECT id % 2 FROM wp_contacts WHERE email LIKE '%news%'"));
    }

    /** 実wpdbと同じく、オブジェクトの値(文字列にできるものも)を空文字にし、noticeを残す。 */
    public function testObjectValuesBecomeEmptyStringWithNotice(): void
    {
        $wpdb = WpdbTestDouble::create();

        self::assertSame("SELECT '', 0", $wpdb->prepare('SELECT %s, %d', new stdClass(), new WpdbTestDoubleTestText('text')));
        self::assertSame(['Unsupported value type (object).', 'Unsupported value type (object).'], $wpdb->prepareNotices());
    }

    /** 実wpdbがPHPの診断を出す`%i`の値(配列・resource・null・Stringableでないオブジェクト)は、再現せずに例外で止まる。 */
    public function testIdentifierValuesThatRealWpdbDiagnosesAreRejected(): void
    {
        $wpdb = WpdbTestDouble::create();
        $messages = [];

        foreach ([[['a'], 2], [null, 2]] as $args) {
            try {
                $wpdb->prepare('SELECT %i, %d', ...$args);
                $messages[] = null;
            } catch (InvalidArgumentException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        self::assertSame([
            'WpdbTestDouble does not emulate %i with array: real wpdb raises a PHP diagnostic for this input',
            'WpdbTestDouble does not emulate %i with null: real wpdb raises a PHP diagnostic for this input',
        ], $messages);
    }

    /** 実wpdbのqueryフィルタと同じく、実行したSQLはplaceholder_escape()を`%`に戻して記録する。 */
    public function testExecutedSqlIsRecordedWithoutPlaceholderEscape(): void
    {
        $wpdb = WpdbTestDouble::create();

        $wpdb->query((string) $wpdb->prepare('DELETE FROM wp_contacts WHERE email LIKE %s', '%@example.test'));

        self::assertSame(["DELETE FROM wp_contacts WHERE email LIKE '%@example.test'"], $wpdb->queries());
    }
}

/** 文字列にできるオブジェクトの値。 */
final readonly class WpdbTestDoubleTestText implements Stringable
{
    public function __construct(
        private string $text,
    ) {}

    public function __toString(): string
    {
        return $this->text;
    }
}
