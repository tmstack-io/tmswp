<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use JsonException;
use TMSWP\Core\Tests\Support\UnitTestCase;

/**
 * QueryBuilderのraw断片の引数(whereRaw・orWhereRawの$sql、selectRawの$expression、innerJoinRaw・leftJoinRawの
 * $on)にPHPの変数を連結した断片を渡すと、`composer analyse`と同じ設定(phpstan.neon.dist。level max)のPHPStanが
 * エラーにすることを確かめる(docs/06 R2の規約4)。リテラルの断片はエラーにならない。
 *
 * エラーを出すのはPHPStan本体のメソッド呼び出しの規則で、その規則のクラスはPHPStanの後方互換の約束の対象外のため、
 * RuleTestCaseではなくPHPStanのコマンドでfixtureを解析する。fixtureはcomposer analyseの解析対象から外れている
 * (excludePaths)ため、除外だけを空にした使い捨ての設定を一時ディレクトリに書いて使う。
 */
final class PHPStanRawFragmentLiteralStringTest extends UnitTestCase
{
    private const string FIXTURE = __DIR__ . '/../fixtures/phpstan/raw-fragment-literal-string.php';

    public function testVariableFragmentsAreReportedAndLiteralFragmentsPass(): void
    {
        $fixture = realpath(self::FIXTURE);
        self::assertIsString($fixture);

        $result = $this->analyse($fixture);

        self::assertSame([], $result['errors']);
        self::assertSame([$fixture], array_keys($result['files']));
        self::assertSame([
            [14, 'argument.type', 'Parameter #1 $sql of method TMSWP\Core\Model\QueryBuilder::whereRaw() expects literal-string, non-falsy-string given.'],
            [15, 'argument.type', 'Parameter #1 $sql of method TMSWP\Core\Model\QueryBuilder::orWhereRaw() expects literal-string, non-falsy-string given.'],
            [16, 'argument.type', 'Parameter #1 $expression of method TMSWP\Core\Model\QueryBuilder::selectRaw() expects literal-string, non-falsy-string given.'],
            [17, 'argument.type', 'Parameter #2 $on of method TMSWP\Core\Model\QueryBuilder::innerJoinRaw() expects literal-string, non-falsy-string given.'],
            [18, 'argument.type', 'Parameter #2 $on of method TMSWP\Core\Model\QueryBuilder::leftJoinRaw() expects literal-string, non-falsy-string given.'],
        ], $result['files'][$fixture]);
    }

    /**
     * $fileをcomposer analyseの設定で解析し、ファイルごとの[行, 識別子, メッセージ]とファイルに属さないエラーを返す。
     *
     * @return array{files: array<string, list<array{int, string, string}>>, errors: list<string>}
     */
    private function analyse(string $file): array
    {
        $root = \dirname(__DIR__, 4);
        $dir = $this->createTempDirectory('phpstan-literal-string');
        $config = $dir . '/phpstan.neon';
        // 除外(excludePaths)だけを空にし、ほかはcomposer analyseの設定のまま使う。結果のキャッシュは一時ディレクトリに置く
        $neon = 'includes:' . "\n"
            . '  - ' . $root . '/phpstan.neon.dist' . "\n"
            . 'parameters:' . "\n"
            . '  excludePaths!:' . "\n"
            . '    analyse: []' . "\n"
            . '    analyseAndScan: []' . "\n"
            . '  tmpDir: ' . $dir . '/tmp' . "\n";
        self::assertNotFalse(file_put_contents($config, $neon));

        $process = proc_open(
            [PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--memory-limit=1G', '-c', $config, $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertIsString($stdout);
        // エラーがあればPHPStanは1で終わる(設定の誤り等で解析できなければ別の値になる)
        self::assertSame(1, $exitCode, (string) $stderr);

        try {
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('PHPStan did not output JSON: ' . $exception->getMessage() . "\n" . $stdout . "\n" . $stderr);
        }

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['files'] ?? null);
        self::assertIsArray($decoded['errors'] ?? null);
        $files = [];

        foreach ($decoded['files'] as $path => $entry) {
            self::assertIsString($path);
            self::assertIsArray($entry);
            self::assertIsArray($entry['messages'] ?? null);
            $files[$path] = [];

            foreach ($entry['messages'] as $message) {
                self::assertIsArray($message);
                self::assertIsInt($message['line'] ?? null);
                self::assertIsString($message['identifier'] ?? null);
                self::assertIsString($message['message'] ?? null);
                $files[$path][] = [$message['line'], $message['identifier'], $message['message']];
            }
        }

        $errors = [];

        foreach ($decoded['errors'] as $error) {
            self::assertIsString($error);
            $errors[] = $error;
        }

        return ['files' => $files, 'errors' => $errors];
    }
}
