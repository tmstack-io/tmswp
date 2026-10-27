<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Log;

use Brain\Monkey\Functions;
use LogicException;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use stdClass;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Log\SimpleLogger;
use TMSWP\Testing\UnitTestCase;

/**
 * SimpleLoggerのファイルへの書き込み・ローテーション・失敗時の扱い(docs/07 §5.5)。旧iwf2bのSimpleLoggerTestの移植先
 * (docs/10 §8.1の20)。
 */
final class SimpleLoggerTest extends UnitTestCase
{
    public function testSimpleLoggerWritesOneLinePerRecordToUnguessableFileWithProtectionFiles(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();

        $logger = new SimpleLogger($dir, '{level}/{date}');
        $exception = new RuntimeException("outer\nforged", 0, new LogicException('inner'));
        $logger->info("created {id} {missing}\r\n2026-06-13 09:00:00 [ERROR] forged", ['id' => 10, 'object' => new stdClass(), 'note' => "a\nb", 'exception' => $exception]);

        $file = $dir . '/info/20260613-' . self::suffix('{level}/{date}') . '.log';
        self::assertFileExists($dir . '/.htaccess');
        self::assertFileExists($dir . '/index.php');
        self::assertFileExists($dir . '/info/index.php');
        self::assertFileExists($file);
        self::assertFileDoesNotExist($dir . '/info/20260613.log');

        $contents = file_get_contents($file);
        self::assertIsString($contents);
        self::assertSame(1, substr_count($contents, "\n"));
        self::assertStringEndsWith("\n", $contents);
        self::assertStringNotContainsString("\r", $contents);
        self::assertStringStartsWith('2026-06-13 09:00:00 [INFO] created 10 {missing}\r\n2026-06-13 09:00:00 [ERROR] forged {"id":10,"object":{},"note":"a\\nb"} ', $contents);
        self::assertStringContainsString(RuntimeException::class . ': outer\nforged in ' . __FILE__ . ':', $contents);
        self::assertStringContainsString('\nCaused by: ' . LogicException::class . ': inner in ' . __FILE__ . ':', $contents);
    }

    public function testSimpleLoggerRotatesOnlyItsOwnFiles(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();
        $own = self::suffix('{date}');

        $foreign = [
            'mail-20260601-' . self::suffix('mail-{date}') . '.log' => 100,
            'mail-20260602-' . self::suffix('mail-{date}') . '.log' => 200,
            '20260601.log' => 300,
            '20260601-' . self::suffix('{level}') . '.log' => 400,
        ];
        self::createFiles($dir, ["20260610-{$own}.log" => 1000, "20260611-{$own}.log" => 2000] + $foreign);

        (new SimpleLogger($dir, maxFiles: 2))->info('new day');

        self::assertFileDoesNotExist("{$dir}/20260610-{$own}.log");
        self::assertFileExists("{$dir}/20260611-{$own}.log");
        self::assertFileExists("{$dir}/20260613-{$own}.log");

        foreach (array_keys($foreign) as $name) {
            self::assertFileExists($dir . '/' . $name);
        }
    }

    public function testSimpleLoggerRotationKeepsFilesOfLoggerWithOverlappingFormat(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();
        $levelLogger = self::suffix('{level}-{date}');
        $errorLogger = self::suffix('error-{date}');

        // error-{date} のファイルは {level}-{date} の名前の形にも当てはまるが、接尾辞で区別する。
        self::createFiles($dir, [
            "error-20200101-{$errorLogger}.log" => 100,
            "error-20200102-{$errorLogger}.log" => 200,
            "error-20260610-{$levelLogger}.log" => 1000,
            "info-20260611-{$levelLogger}.log" => 2000,
        ]);

        (new SimpleLogger($dir, '{level}-{date}', maxFiles: 2))->info('level logger');
        (new SimpleLogger($dir, 'error-{date}', maxFiles: 3))->error('error logger');

        self::assertFileDoesNotExist("{$dir}/error-20260610-{$levelLogger}.log");
        self::assertFileExists("{$dir}/info-20260611-{$levelLogger}.log");
        self::assertFileExists("{$dir}/info-20260613-{$levelLogger}.log");
        self::assertFileExists("{$dir}/error-20200101-{$errorLogger}.log");
        self::assertFileExists("{$dir}/error-20200102-{$errorLogger}.log");
        self::assertFileExists("{$dir}/error-20260613-{$errorLogger}.log");
    }

    public function testSimpleLoggerRotationListsDirectoryWithGlobCharactersLiterally(): void
    {
        $base = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();
        $own = self::suffix('{date}');

        // glob() なら logs[ab] は logsa にも一致する。
        mkdir("{$base}/logs[ab]");
        mkdir("{$base}/logsa");
        self::createFiles("{$base}/logs[ab]", ["20260611-{$own}.log" => 2000]);
        self::createFiles("{$base}/logsa", ["20260601-{$own}.log" => 100, "20260602-{$own}.log" => 200, "20260603-{$own}.log" => 300]);

        (new SimpleLogger("{$base}/logs[ab]", maxFiles: 2))->info('bracket');

        self::assertFileExists("{$base}/logs[ab]/20260611-{$own}.log");
        self::assertFileExists("{$base}/logs[ab]/20260613-{$own}.log");
        self::assertFileExists("{$base}/logsa/20260601-{$own}.log");
        self::assertFileExists("{$base}/logsa/20260602-{$own}.log");
        self::assertFileExists("{$base}/logsa/20260603-{$own}.log");
    }

    /** @return iterable<string, array{int, list<string>}> */
    public static function nonRegularEntryProvider(): iterable
    {
        yield 'maxFiles 1' => [1, ['20260613']];
        yield 'maxFiles 2' => [2, ['20260611', '20260613']];
    }

    /** @param list<string> $kept 残る通常のログの日付 */
    #[DataProvider('nonRegularEntryProvider')]
    public function testSimpleLoggerRotationCountsOnlyRegularFiles(int $maxFiles, array $kept): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();
        $own = self::suffix('{date}');

        self::createFiles($dir, ["20260611-{$own}.log" => 2000, 'target.txt' => 100]);
        // 名前が自分のファイルと一致するディレクトリ(更新日時は未来)と、実在するファイルを指すシンボリックリンク。
        mkdir("{$dir}/20990101-{$own}.log");
        touch("{$dir}/20990101-{$own}.log", 4102444800);
        symlink("{$dir}/target.txt", "{$dir}/20200101-{$own}.log");

        (new SimpleLogger($dir, maxFiles: $maxFiles))->info('new day');

        foreach (['20260611', '20260613'] as $date) {
            self::assertSame(\in_array($date, $kept, true), is_file("{$dir}/{$date}-{$own}.log"), $date);
        }

        self::assertDirectoryExists("{$dir}/20990101-{$own}.log");
        self::assertTrue(is_link("{$dir}/20200101-{$own}.log"));
        self::assertFileExists("{$dir}/target.txt");
    }

    public function testSimpleLoggerReportsRotationFailureToErrorLogOnlyOnce(): void
    {
        $own = self::suffix('{date}');
        // ファイルの作成と追記はできるが、ファイルを削除できないディレクトリ。
        $logs = (new UndeletableDirectory('logs'))->at(vfsStream::setup('root'));
        vfsStream::newFile("20260611-{$own}.log")->withContent('old')->lastModified(2000)->at($logs);
        $this->stubLoggerFunctions();
        $reports = $this->recordTriggeredErrors();
        $logger = new SimpleLogger($logs->url(), maxFiles: 1);

        $this->withCapturedErrorLog(function (string $errorLog) use ($logger, $logs, $reports, $own): void {
            self::captureWarnings(static fn() => $logger->info('first'));

            $message = 'ログのローテーションに失敗しました。古いログファイルを削除できません: ' . $logs->url() . "/20260611-{$own}.log";
            self::assertSame([$message], $reports->messages);
            self::assertSame([SimpleLogger::class . ': ' . $message], self::errorLogMessages($errorLog));

            // 次の日のファイルを作るときも削除に失敗するが、可視化は初回だけ。
            Functions\when('wp_date')->alias(static fn(string $format): string => $format === 'Ymd' ? '20260614' : '2026-06-14 09:00:00');
            self::captureWarnings(static fn() => $logger->info('second'));

            self::assertSame([$message], $reports->messages);
            self::assertCount(2, self::errorLogMessages($errorLog));
            self::assertStringContainsString("/20260613-{$own}.log", self::errorLogMessages($errorLog)[1]);
        });

        $contents = file_get_contents($logs->url() . "/20260613-{$own}.log");
        self::assertIsString($contents);
        self::assertStringContainsString('[INFO] first', $contents);
    }

    public function testSimpleLoggerReportsRotationFailureWhenDirectoryCannotBeListed(): void
    {
        // 他のユーザーが所有する 0733 のディレクトリは、書き込めるが列挙できない。
        $unlistable = vfsStream::newDirectory('logs', 0733)->chown(vfsStream::OWNER_USER_1)->at(vfsStream::setup('root'));
        $this->stubLoggerFunctions();
        $reports = $this->recordTriggeredErrors();

        $this->withCapturedErrorLog(function (string $errorLog) use ($unlistable, $reports): void {
            self::captureWarnings(static fn() => (new SimpleLogger($unlistable->url()))->info('unlistable'));

            $message = 'ログのローテーションに失敗しました。ログディレクトリを列挙できません: ' . $unlistable->url();
            self::assertSame([$message], $reports->messages);
            self::assertSame([SimpleLogger::class . ': ' . $message], self::errorLogMessages($errorLog));
        });

        self::assertTrue($unlistable->hasChild('20260613-' . self::suffix('{date}') . '.log'));
    }

    public function testSimpleLoggerFallsBackToErrorLogForUnwritableDirectoryAndReportsOnlyFirstFailure(): void
    {
        // 他のユーザーが所有する 0755 のディレクトリは、テストを実行するユーザーから書き込めない。
        $readOnly = vfsStream::newDirectory('logs', 0755)->chown(vfsStream::OWNER_USER_1)->at(vfsStream::setup('root'));
        $this->stubLoggerFunctions();
        $reports = $this->recordTriggeredErrors();

        $this->withCapturedErrorLog(function (string $errorLog) use ($readOnly, $reports): void {
            $logger = new SimpleLogger($readOnly->url());

            $logger->error('first failure');
            self::assertCount(1, $reports->messages);
            self::assertStringContainsString('ログディレクトリに書き込めません: ' . $readOnly->url(), $reports->messages[0]);
            self::assertSame(['2026-06-13 09:00:00 [ERROR] first failure'], self::errorLogMessages($errorLog));

            $logger->error('second failure');
            self::assertCount(1, $reports->messages);
            self::assertSame(['2026-06-13 09:00:00 [ERROR] first failure', '2026-06-13 09:00:00 [ERROR] second failure'], self::errorLogMessages($errorLog));
        });

        self::assertSame([], $readOnly->getChildren());
    }

    public function testSimpleLoggerFallsBackToErrorLogWhenDirectoryCannotBeCreated(): void
    {
        $dir = $this->createTempDirectory('simple-logger') . '/missing';
        $this->stubLoggerFunctions(mkdirSucceeds: false);
        $reports = $this->recordTriggeredErrors();

        $this->withCapturedErrorLog(function (string $errorLog) use ($dir, $reports): void {
            (new SimpleLogger($dir))->warning("line\nbreak");

            self::assertSame(['2026-06-13 09:00:00 [WARNING] line\nbreak'], self::errorLogMessages($errorLog));
            self::assertCount(1, $reports->messages);
            self::assertStringContainsString('ログディレクトリを作成できません: ' . $dir, $reports->messages[0]);
        });

        self::assertDirectoryDoesNotExist($dir);
    }

    #[RunInSeparateProcess]
    public function testSimpleLoggerFallsBackToErrorLogBeforePluggableFunctionsAreLoaded(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        Functions\when('wp_date')->justReturn('2026-06-13 09:00:00');
        $reports = $this->recordTriggeredErrors();

        $this->withCapturedErrorLog(function (string $errorLog) use ($dir, $reports): void {
            self::assertFalse(\function_exists('wp_hash'));
            (new SimpleLogger($dir))->notice('early');

            self::assertSame(['2026-06-13 09:00:00 [NOTICE] early'], self::errorLogMessages($errorLog));
            self::assertCount(1, $reports->messages);
            self::assertStringContainsString('wp_hash() が未定義', $reports->messages[0]);
        });

        self::assertSame(['.', '..'], scandir($dir));
    }

    public function testSimpleLoggerRejectsParentSegmentsInFileNameFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SimpleLogger('/tmp/logs', '../{date}');
    }

    private function stubLoggerFunctions(bool $mkdirSucceeds = true): void
    {
        Functions\when('wp_mkdir_p')->alias(static function (string $path) use ($mkdirSucceeds): bool {
            return $mkdirSucceeds && (is_dir($path) || mkdir($path, 0775, true));
        });
        Functions\when('wp_date')->alias(static fn(string $format): string => $format === 'Ymd' ? '20260613' : '2026-06-13 09:00:00');
        Functions\when('wp_hash')->alias(static fn(string $data): string => md5('salt|' . $data));
    }

    /** stubLoggerFunctions() の wp_hash() による、fileNameFormat ごとのファイル名の接尾辞 */
    private static function suffix(string $fileNameFormat): string
    {
        return md5('salt|tmswp-simple-logger:' . $fileNameFormat);
    }

    /** @param array<string, int> $files ファイル名 => 更新日時 */
    private static function createFiles(string $dir, array $files): void
    {
        foreach ($files as $name => $mtime) {
            file_put_contents("{$dir}/{$name}", 'old');
            touch("{$dir}/{$name}", $mtime);
        }
    }

    /**
     * 呼び出し中の PHP の警告を受け取って返す(unlink() などの失敗が出す警告をテストの失敗にしない)。
     *
     * @return list<string>
     */
    private static function captureWarnings(callable $call): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_WARNING | E_USER_WARNING);

        try {
            $call();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    private function recordTriggeredErrors(): TriggeredErrors
    {
        $reports = new TriggeredErrors();
        Functions\when('wp_trigger_error')->alias(static function (string $function, string $message, int $level) use ($reports): void {
            self::assertSame(SimpleLogger::class . '::log', $function);
            self::assertSame(E_USER_WARNING, $level);
            $reports->messages[] = $message;
        });

        return $reports;
    }

    /** @param callable(string): void $test */
    private function withCapturedErrorLog(callable $test): void
    {
        $errorLog = $this->createTempDirectory('error-log') . '/php-error.log';
        $previous = ini_set('error_log', $errorLog);

        try {
            $test($errorLog);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
    }

    /** @return list<string> PHP の error_log() が各行の先頭に付ける日時を除いたメッセージ */
    private static function errorLogMessages(string $errorLog): array
    {
        $lines = file($errorLog, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        return array_map(static fn(string $line): string => preg_replace('/\A\[[^\]]+\] /', '', $line) ?? $line, $lines);
    }
}

/** ファイルを削除できない vfsStream のディレクトリ(unlink() の失敗を作る) */
final class UndeletableDirectory extends vfsStreamDirectory
{
    public function removeChild($name): bool
    {
        return false;
    }
}

final class TriggeredErrors
{
    /** @var list<string> */
    public array $messages = [];
}
