<?php

declare(strict_types=1);

namespace TMSWP\Testing\Tests\Integration;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionMethod;
use stdClass;
use Stringable;
use Throwable;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\WpdbTestDouble;
use wpdb;

/**
 * ユニット層のwpdbの代役(WpdbTestDouble)が、実wpdbと同じSQLを返すことを確かめる。
 *
 * 同じ入力を実wpdb(MySQLに接続済みのグローバルの$wpdb)と代役のprepare()に通し、それぞれのplaceholder_escape()
 * を同じ印に置き換えて比べる。例外になる入力では、例外のクラスとメッセージを比べる。実wpdbが_doing_it_wrong()を
 * 出す入力では、代役もnoticeを記録する。
 */
final class WpdbTestDoubleParityTest extends IntegrationTestCase
{
    /** @return iterable<string, array{string, list<mixed>, bool}> 実wpdb::prepare()の引数と、実wpdbが_doing_it_wrong()を出すか */
    public static function prepareInputs(): iterable
    {
        yield '%s・%d・%f' => ['SELECT * FROM t WHERE a = %s AND b = %d AND c = %f', ['x', 3, 1.5], false];
        yield '値と一緒の%%' => ['SELECT id %% 2 FROM t WHERE id = %d', [1], false];
        yield '%を含む値' => ['SELECT * FROM t WHERE a LIKE %s', ['%news\_%'], false];
        yield '引用符・バックスラッシュ・制御文字・マルチバイト' => ['SELECT * FROM t WHERE a = %s', ["O'Brien \"q\" \\ \n \r \0 \x1a 日本語"], false];
        yield '%iとバッククォート' => ['SELECT %i FROM t', ['we`ird'], false];
        yield '%iにStringable' => ['SELECT %i, %d', [new WpdbTestDoubleParityText('we`ird'), 2], false];
        yield 'nullとbool' => ['SELECT %s, %s, %s, %d, %d', [null, true, false, true, false], false];
        yield '数でない文字列を%d・%fに' => ['SELECT %d, %f', ['12abc', '1.25'], false];
        yield '引用符で囲んだ%s' => ["SELECT * FROM t WHERE a = '%s' AND b = \"%s\"", ['x', 'y'], false];
        yield '解釈できない%の並び' => ['SELECT 100%, a FROM t WHERE d = %Y-%m AND a = %s', ['x'], false];
        yield '番号付き・書式付き' => ['SELECT %1$s, %2$d, %5s', ['a', 2, 'b'], false];
        yield '配列1つで渡す値' => ['SELECT %s, %d', [['a', 1]], false];
        yield '配列1つで渡す値(連番でないキー)' => ['SELECT %i', [[4 => 'a`b']], false];
        yield '配列1つで渡す値(連番でないキーと%s)' => ['SELECT %i, %s', [[2 => 'a`b', 3 => "O'Brien"]], false];
        yield '配列1つで渡す値(文字列のキー)' => ['SELECT %s, %i', [['a' => "O'Brien", 'b' => 'a`b']], false];
        yield '%%の直後の%s' => ['SELECT * FROM t WHERE a LIKE %%%s', ['x'], false];
        yield '%%の直後の%f(プレースホルダに数えない)' => ['SELECT %%%f, %%%%%f', [1.5, 2.5], true];
        yield 'オブジェクトの値' => ['SELECT %s, %d, %s', [new stdClass(), new DateTimeImmutable('2026-06-13 09:10:11', new DateTimeZone('UTC')), new WpdbTestDoubleParityText('text')], true];
        yield '値が足りない' => ['SELECT %s, %s', ['a'], true];
        yield '値が多い' => ['SELECT %s', ['a', 'b'], true];
        yield 'プレースホルダ1つに配列で複数の値' => ['SELECT %s', [['a', 'b']], true];
        yield '識別子と値に同じ引数' => ['SELECT %1$i, %1$s', ['a'], true];
        yield 'プレースホルダの無いSQL' => ['SELECT 1', [], true];
    }

    /**
     * 統合層のPHPUnit 9は属性のデータプロバイダを読まないため、注釈で指定する。
     *
     * @dataProvider prepareInputs
     *
     * @param list<mixed> $args
     */
    public function testPrepareReturnsTheSameSqlAsRealWpdb(string $query, array $args, bool $doingItWrong): void
    {
        $wpdb = self::wpdb();
        $double = WpdbTestDouble::create();

        if ($doingItWrong) {
            $this->setExpectedIncorrectUsage('wpdb::prepare');
        }

        // スタブのwpdb::prepare()は$queryにliteral-stringを要求するため、データプロバイダのSQLはリフレクションで渡す
        $real = self::outcome($wpdb, static fn(): mixed => (new ReflectionMethod($wpdb, 'prepare'))->invokeArgs($wpdb, [$query, ...$args]));
        $doubled = self::outcome($double, static fn(): mixed => $double->prepare($query, ...$args));

        self::assertSame($real, $doubled);
        self::assertSame($doingItWrong, $double->prepareNotices() !== []);
    }

    /**
     * 実wpdbがPHPの診断(警告・非推奨の通知・TypeError)を出す`%i`の値の入力。代役は再現しない(WpdbTestDoubleの
     * クラスのdocblockの5)。
     *
     * @return iterable<string, array{string, list<mixed>, string}> 実wpdb::prepare()の引数と、get_debug_type()の型の名前
     */
    public static function identifierInputsTheDoubleDoesNotEmulate(): iterable
    {
        $resource = fopen('php://memory', 'r');

        yield '%iに配列' => ['SELECT %i, %d', [['a', 'b'], 2], 'array'];
        yield '%1$iと%dで同じ配列' => ['SELECT %1$i, %d', [['a', 'b'], 2], 'array'];
        yield '%iに入れ子の配列' => ['SELECT %i, %d', [[['a'], 'b'], 2], 'array'];
        yield '%iにresource' => ['SELECT %i', [$resource], 'resource (stream)'];
        yield '%iにnull' => ['SELECT %i', [null], 'null'];
        yield '%iにstdClass' => ['SELECT %i', [new stdClass()], 'stdClass'];
    }

    /**
     * 実wpdbはPHPの診断を出し(PHPUnitが警告・非推奨の通知を変えた例外か、TypeError)、代役は再現せずに
     * InvalidArgumentExceptionで止まる。
     *
     * @dataProvider identifierInputsTheDoubleDoesNotEmulate
     *
     * @param list<mixed> $args
     */
    public function testDoubleRejectsIdentifierInputsForWhichRealWpdbRaisesPhpDiagnostic(string $query, array $args, string $type): void
    {
        $wpdb = self::wpdb();
        $double = WpdbTestDouble::create();

        $real = self::outcome($wpdb, static fn(): mixed => (new ReflectionMethod($wpdb, 'prepare'))->invokeArgs($wpdb, [$query, ...$args]));
        $doubled = self::outcome($double, static fn(): mixed => $double->prepare($query, ...$args));

        // 統合層のPHPUnit 9のクラスは、静的解析の対象のPHPUnitに無いため、名前で比べる
        self::assertIsString($real);
        self::assertContains(strstr($real, ':', true), ['TypeError', 'PHPUnit\Framework\Error\Warning', 'PHPUnit\Framework\Error\Deprecated', 'PHPUnit\Framework\Error\Notice']);
        self::assertSame("InvalidArgumentException: WpdbTestDouble does not emulate %i with {$type}: real wpdb raises a PHP diagnostic for this input", $doubled);
    }

    public function testPlaceholderEscapeHasTheSameShapeAndRoundTrips(): void
    {
        $wpdb = self::wpdb();
        $double = WpdbTestDouble::create();
        $sql = "SELECT '100%' LIKE '%a%'";

        self::assertMatchesRegularExpression('/\A\{[0-9a-f]{64}\}\z/', $wpdb->placeholder_escape());
        self::assertMatchesRegularExpression('/\A\{[0-9a-f]{64}\}\z/', $double->placeholder_escape());
        self::assertSame(self::marked($wpdb, $wpdb->add_placeholder_escape($sql)), self::marked($double, $double->add_placeholder_escape($sql)));
        self::assertSame($sql, $wpdb->remove_placeholder_escape($wpdb->add_placeholder_escape($sql)));
        self::assertSame($sql, $double->remove_placeholder_escape($double->add_placeholder_escape($sql)));
    }

    /**
     * $prepareの結果を比べられる形にする。SQLは$dbのplaceholder_escape()を印に置き換え、例外(PHPの警告をPHPUnitが
     * 変えた例外を含む)はクラスとメッセージにする。
     *
     * @param Closure(): mixed $prepare
     */
    private static function outcome(wpdb $db, Closure $prepare): mixed
    {
        try {
            return self::marked($db, $prepare());
        } catch (Throwable $exception) {
            return $exception::class . ': ' . $exception->getMessage();
        }
    }

    /** $sqlの中の$dbのplaceholder_escape()を、比べられる印に置き換える。 */
    private static function marked(wpdb $db, mixed $sql): mixed
    {
        return \is_string($sql) ? str_replace($db->placeholder_escape(), '{ESCAPE}', $sql) : $sql;
    }
}

/** 文字列にできるオブジェクトの値。実wpdbは%sの値ではStringableも空文字にし、%iの識別子では文字列にする。 */
final readonly class WpdbTestDoubleParityText implements Stringable
{
    public function __construct(
        private string $text,
    ) {}

    public function __toString(): string
    {
        return $this->text;
    }
}
