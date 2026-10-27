<?php

declare(strict_types=1);

namespace TMSWP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Testing\UnitTestCase;

/**
 * bin/coverage-check.php(unit と integration の clover の行単位の合算と閾値判定)を CLI として検証する。
 */
final class CoverageCheckTest extends UnitTestCase
{
    private const CONTAINER_ROOT = '/var/www/html/wp-content/plugins/tmswp';

    public function testMergesHostAndContainerReportsLineByLine(): void
    {
        $file = 'packages/core/src/Example.php';
        $unit = $this->writeClover('unit', [$this->hostRoot() . '/' . $file => [10 => 1, 11 => 0, 12 => 0]]);
        $integration = $this->writeClover('integration', [self::CONTAINER_ROOT . '/' . $file => [10 => 0, 11 => 3, 12 => 0]]);

        $result = $this->runCheck(['66', $unit, $integration]);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Combined coverage 66.67% (2/3 lines in 1 files) (>= 66%)', $result['stdout']);
    }

    public function testFailsWhenCombinedCoverageIsBelowMinimum(): void
    {
        $file = $this->hostRoot() . '/packages/core/src/Example.php';
        $unit = $this->writeClover('unit', [$file => [10 => 1, 11 => 0]]);

        $result = $this->runCheck(['51', $unit]);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('Combined coverage 50% (1/2 lines in 1 files) is below required 51%', $result['stderr']);
    }

    public function testCountsAlternativeNotationsOfTheSameFileOnce(): void
    {
        $root = $this->hostRoot();
        $unit = $this->writeClover('unit', [$root . '/packages/core/src/./Support/Arr.php' => [5 => 1, 6 => 0]]);
        $integration = $this->writeClover('integration', [
            self::CONTAINER_ROOT . '//packages/core/src/Kernel/../Support/Arr.php' => [6 => 1],
        ]);

        $result = $this->runCheck(['100', $unit, $integration]);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Combined coverage 100% (2/2 lines in 1 files)', $result['stdout']);
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidArguments')]
    public function testRejectsInvalidArgumentsWithUsage(array $arguments): void
    {
        $result = $this->runCheck($arguments);

        self::assertSame(2, $result['code']);
        self::assertStringStartsWith('Usage: php bin/coverage-check.php', $result['stderr']);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'minimum only' => [['58']];
        yield 'non-numeric minimum' => [['abc', 'clover.xml']];
        yield 'minimum above 100' => [['101', 'clover.xml']];
        yield 'negative minimum' => [['-1', 'clover.xml']];
        yield 'empty clover path' => [['58', '']];
    }

    #[DataProvider('unreadableReports')]
    public function testRejectsUnreadableReports(string $contents, string $expectedMessage): void
    {
        $path = $this->createTempDirectory('coverage-check') . '/report.xml';
        $this->writeFile($path, str_replace('{root}', $this->hostRoot(), $contents));

        $result = $this->runCheck(['58', $path]);

        self::assertSame(2, $result['code']);
        self::assertSame("{$expectedMessage}: {$path}\n", $result['stderr']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableReports(): iterable
    {
        yield 'broken xml' => ['<coverage><project', 'Cannot read coverage report'];
        yield 'not a clover report' => ['<foo/>', 'Not a clover coverage report'];
        yield 'no files' => ['<coverage><project/></coverage>', 'Coverage report contains no files'];
        yield 'no statements' => [
            '<coverage><project><file name="{root}/packages/core/src/Example.php"><line num="1" type="method" count="1"/></file></project></coverage>',
            'Coverage report contains zero statements',
        ];
    }

    public function testRejectsMissingReport(): void
    {
        $path = $this->createTempDirectory('coverage-check') . '/missing.xml';

        $result = $this->runCheck(['58', $path]);

        self::assertSame(2, $result['code']);
        self::assertSame("Cannot read coverage report: {$path}\n", $result['stderr']);
    }

    #[DataProvider('unnormalizablePaths')]
    public function testRejectsPathsOutsideTheRepository(string $name, string $reason): void
    {
        $path = str_replace('{root}', $this->hostRoot(), $name);
        $report = $this->writeClover('unit', [$path => [1 => 1]]);

        $result = $this->runCheck(['0', $report]);

        self::assertSame(2, $result['code']);
        self::assertStringStartsWith("Cannot normalize path {$path} in {$report} ({$reason}", $result['stderr']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unnormalizablePaths(): iterable
    {
        yield 'relative path' => ['packages/core/src/Example.php', 'not an absolute path'];
        yield 'outside the roots' => ['/srv/other/packages/core/src/Example.php', 'expected under'];
        yield 'parent of the host root' => ['{root}/../outside.php', 'expected under'];
        yield 'parent of the container root' => [self::CONTAINER_ROOT . '/../outside.php', 'expected under'];
        yield 'above the filesystem root' => ['/../outside.php', 'points above the filesystem root'];
    }

    #[DataProvider('invalidLineAttributes')]
    public function testRejectsInvalidLineAttributes(string $number, string $count): void
    {
        $name = $this->hostRoot() . '/packages/core/src/Example.php';
        $path = $this->createTempDirectory('coverage-check') . '/report.xml';
        $this->writeFile(
            $path,
            "<coverage><project><file name=\"{$name}\"><line num=\"{$number}\" type=\"stmt\" count=\"{$count}\"/></file></project></coverage>",
        );

        $result = $this->runCheck(['0', $path]);

        self::assertSame(2, $result['code']);
        self::assertStringStartsWith("Invalid line entry in {$name} of {$path}", $result['stderr']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidLineAttributes(): iterable
    {
        yield 'line number zero' => ['0', '1'];
        yield 'missing line number' => ['', '1'];
        yield 'negative count' => ['1', '-1'];
        yield 'line number beyond PHP_INT_MAX' => ['99999999999999999999', '1'];
        yield 'count beyond PHP_INT_MAX' => ['1', '99999999999999999999'];
        yield 'line number with a leading zero' => ['01', '1'];
        yield 'line number of double zero' => ['00', '1'];
        yield 'count with a leading zero' => ['1', '01'];
        yield 'count of double zero' => ['1', '00'];
    }

    private function hostRoot(): string
    {
        $root = realpath(\dirname(__DIR__, 2));

        if ($root === false) {
            self::fail('リポジトリのルートを解決できません。');
        }

        return $root;
    }

    /**
     * @param array<string, array<int, int>> $files ファイルの絶対パス => [行番号 => 実行回数]
     */
    private function writeClover(string $name, array $files): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<coverage generated=\"0\">\n  <project timestamp=\"0\">\n";

        foreach ($files as $file => $lines) {
            $xml .= '    <file name="' . htmlspecialchars($file, ENT_XML1 | ENT_QUOTES) . "\">\n";

            foreach ($lines as $number => $count) {
                $xml .= "      <line num=\"{$number}\" type=\"stmt\" count=\"{$count}\"/>\n";
            }

            $xml .= "    </file>\n";
        }

        $xml .= "  </project>\n</coverage>\n";
        $path = $this->createTempDirectory('coverage-check') . "/{$name}.xml";
        $this->writeFile($path, $xml);

        return $path;
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            self::fail("テストファイルを書き込めません: {$path}");
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCheck(array $arguments): array
    {
        $script = \dirname(__DIR__, 2) . '/bin/coverage-check.php';
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([PHP_BINARY, $script, ...$arguments], $descriptors, $pipes);

        if (!\is_resource($process)) {
            self::fail('coverage-check プロセスを起動できません。');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => \is_string($stdout) ? $stdout : '',
            'stderr' => \is_string($stderr) ? $stderr : '',
        ];
    }
}
