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
        self::assertStringContainsString('Combined coverage 66.66% (2/3 lines in 1 files) (>= 66%)', $result['stdout']);
    }

    public function testFailsWhenCombinedCoverageIsBelowMinimum(): void
    {
        $file = $this->hostRoot() . '/packages/core/src/Example.php';
        $unit = $this->writeClover('unit', [$file => [10 => 1, 11 => 0]]);

        $result = $this->runCheck(['51', $unit]);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('Combined coverage 50.00% (1/2 lines in 1 files) is below required 51%', $result['stderr']);
    }

    /**
     * 閾値とは丸める前の値で比べる。77.995%は小数2桁に丸めると78.00%になるが、閾値78を満たさない。
     * 表示は切り捨てるため、閾値を満たさない値が閾値以上に見えることもない。
     */
    #[DataProvider('boundaryCoverages')]
    public function testComparesUnroundedCoverageWithMinimum(int $covered, int $statements, int $expectedCode, string $expectedSummary): void
    {
        $lines = [];

        for ($number = 1; $number <= $statements; ++$number) {
            $lines[$number] = $number <= $covered ? 1 : 0;
        }

        $unit = $this->writeClover('unit', [$this->hostRoot() . '/packages/core/src/Example.php' => $lines]);

        $result = $this->runCheck(['78', $unit]);

        self::assertSame($expectedCode, $result['code'], $result['stderr']);
        self::assertStringContainsString($expectedSummary, $result['stdout'] . $result['stderr']);
    }

    /**
     * @return iterable<string, array{int, int, int, string}>
     */
    public static function boundaryCoverages(): iterable
    {
        yield '77.995% is below 78' => [15599, 20000, 1, 'Combined coverage 77.99% (15599/20000 lines in 1 files) is below required 78%'];
        yield 'exactly 78% meets 78' => [39, 50, 0, 'Combined coverage 78.00% (39/50 lines in 1 files) (>= 78%)'];
    }

    public function testChecksEachPackageInAdditionToTheCombinedCoverage(): void
    {
        $root = $this->hostRoot();
        $unit = $this->writeClover('unit', [
            $root . '/packages/core/src/Example.php' => [1 => 1, 2 => 1, 3 => 1, 4 => 0],
            $root . '/packages/thumbnail/src/Example.php' => [1 => 1, 2 => 0],
        ]);
        $integration = $this->writeClover('integration', [
            self::CONTAINER_ROOT . '/packages/thumbnail/src/Other.php' => [1 => 1, 2 => 1],
        ]);

        $result = $this->runCheck(['75', '--package=thumbnail:75', '--package=core:75', $unit, $integration]);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame(
            "Package core coverage 75.00% (3/4 lines in 1 files) (>= 75%)\n"
            . "Package thumbnail coverage 75.00% (3/4 lines in 2 files) (>= 75%)\n"
            . "Combined coverage 75.00% (6/8 lines in 3 files) (>= 75%)\n",
            substr($result['stdout'], (int) strpos($result['stdout'], 'Package ')),
        );
    }

    /**
     * 合算が閾値を満たしても、閾値を満たさないパッケージがあれば失敗する。結果はすべて出してから終わる。
     */
    public function testFailsWhenAPackageIsBelowItsMinimumEvenIfTheCombinedCoverageMeetsIt(): void
    {
        $root = $this->hostRoot();
        $unit = $this->writeClover('unit', [
            $root . '/packages/core/src/Example.php' => [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1],
            $root . '/packages/ja-toolkit/src/Example.php' => [1 => 1, 2 => 0],
        ]);

        $result = $this->runCheck(['80', '--package=core:80', '--package=ja-toolkit:80', $unit]);

        self::assertSame(1, $result['code']);
        self::assertSame("Package ja-toolkit coverage 50.00% (1/2 lines in 1 files) is below required 80%\n", $result['stderr']);
        self::assertStringContainsString('Package core coverage 100.00% (8/8 lines in 1 files) (>= 80%)', $result['stdout']);
        self::assertStringContainsString('Combined coverage 90.00% (9/10 lines in 2 files) (>= 80%)', $result['stdout']);
    }

    /**
     * パッケージ別の判定では、閾値の無いパッケージ(とpackages/の外)のファイルを、判定せずに通さない。
     */
    #[DataProvider('unmatchedPackages')]
    public function testRejectsPackagesWithoutMinimumOrFiles(string $file, string $option, string $expectedMessage): void
    {
        $unit = $this->writeClover('unit', [$this->hostRoot() . '/' . $file => [1 => 1]]);

        $result = $this->runCheck(['0', $option, $unit]);

        self::assertSame(2, $result['code']);
        self::assertSame("{$expectedMessage}\n", $result['stderr']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unmatchedPackages(): iterable
    {
        yield 'package without minimum' => [
            'packages/thumbnail/src/Example.php',
            '--package=core:80',
            'No package minimum for packages/thumbnail/src/Example.php (pass --package=<name>:<minimum-percent> for every measured package)',
        ];
        yield 'file outside packages' => [
            'bin/example.php',
            '--package=core:80',
            'No package minimum for bin/example.php (pass --package=<name>:<minimum-percent> for every measured package)',
        ];
    }

    /** 閾値を渡したパッケージのファイルが無い場合も、何も比べずに通さない。*/
    public function testRejectsPackageMinimumWithoutFiles(): void
    {
        $unit = $this->writeClover('unit', [$this->hostRoot() . '/packages/core/src/Example.php' => [1 => 1]]);

        $result = $this->runCheck(['0', '--package=core:80', '--package=thumbnail:80', $unit]);

        self::assertSame(2, $result['code']);
        self::assertSame("Coverage reports contain no files of package thumbnail\n", $result['stderr']);
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
        self::assertStringContainsString('Combined coverage 100.00% (2/2 lines in 1 files)', $result['stdout']);
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
        yield 'package option only' => [['58', '--package=core:80']];
        yield 'package option without minimum' => [['58', '--package=core', 'clover.xml']];
        yield 'package option with non-numeric minimum' => [['58', '--package=core:abc', 'clover.xml']];
        yield 'package minimum above 100' => [['58', '--package=core:101', 'clover.xml']];
        yield 'package option with invalid name' => [['58', '--package=Core/src:80', 'clover.xml']];
        yield 'duplicate package option' => [['58', '--package=core:80', '--package=core:70', 'clover.xml']];
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
