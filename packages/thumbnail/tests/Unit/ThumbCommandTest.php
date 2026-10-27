<?php

declare(strict_types=1);

namespace TMSWP\Thumbnail\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Tests\Support\UnitTestCase;
use TMSWP\Thumbnail\Cli\ThumbCommand;
use TMSWP\Thumbnail\Tests\Support\BootsThumbnailKernel;
use TMSWP\Thumbnail\Thumbnail;
use TMSWP\Thumbnail\ThumbnailConfig;

/**
 * `wp tmswp thumb clear`・`wp tmswp thumb status`と、WP-CLIの環境でのコマンドの登録。
 *
 * コマンドの実行と登録は、WP_CLIの代役クラスをテスト用の一時ファイルに書いて読み込むため、ほかのテストにWP_CLIの
 * クラス(と定数)を残さないよう別プロセスで動かす。代役は呼び出しを`$GLOBALS['tmswp_test_wp_cli']`に記録する。
 */
final class ThumbCommandTest extends UnitTestCase
{
    use BootsThumbnailKernel;

    private const string KEY = 'ab0123456789abcdef0123456789abcdef012345';

    /** KEYと同じshardの、別のキー */
    private const string OTHER_KEY = 'ab99999999999999999999999999999999999999';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        $GLOBALS['tmswp_test_wp_cli'] = [];
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions'], $GLOBALS['tmswp_test_wp_cli']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidDaysProvider(): iterable
    {
        yield '負の値' => ['-1'];
        yield '0' => ['0'];
        yield '整数でない' => ['1.5'];
        yield '値の無いフラグ' => [true];
        yield '302のキャッシュの期間(2日)以下' => ['2'];
    }

    #[DataProvider('invalidDaysProvider')]
    public function testClearRejectsInvalidDays(mixed $days): void
    {
        $command = new ThumbCommand(new Thumbnail(new ThumbnailConfig(redirectMaxAge: 2 * 86400)));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wp tmswp thumb clear --days must be');

        $command->clear([], ['days' => $days]);
    }

    /**
     * `wp tmswp thumb clear --days=30`は、更新日時が30日より前のファイルだけを消し、index.htmlを残す(30日より前の
     * index.htmlも)。`--days`の無い`clear`は、すべてのサムネイルを消し、index.htmlを残す。
     */
    #[RunInSeparateProcess]
    public function testClearDeletesOnlyFilesOlderThanTheDaysAndKeepsIndexHtml(): void
    {
        $this->loadWpCliDouble();
        [$thumbnail, $cacheDir] = $this->thumbnailWithCache();
        $old = $cacheDir . '/ab/' . self::KEY . '.jpg';
        $recent = $cacheDir . '/ab/' . self::OTHER_KEY . '.jpg';
        self::assertNotFalse(file_put_contents($old, 'old'));
        self::assertNotFalse(file_put_contents($recent, 'recent'));
        self::assertTrue(touch($old, time() - 31 * 86400));
        self::assertTrue(touch($recent, time() - 29 * 86400));
        self::assertTrue(touch($cacheDir . '/index.html', time() - 31 * 86400));
        self::assertTrue(touch($cacheDir . '/ab/index.html', time() - 31 * 86400));
        $command = new ThumbCommand($thumbnail);

        $command->clear([], ['days' => '30']);

        self::assertSame([['success', 'deleted 1 thumbnail cache file(s)']], $GLOBALS['tmswp_test_wp_cli']);
        self::assertFileDoesNotExist($old);
        self::assertFileExists($recent);
        self::assertFileExists($cacheDir . '/index.html');
        self::assertFileExists($cacheDir . '/ab/index.html');

        $GLOBALS['tmswp_test_wp_cli'] = [];
        $command->clear([], []);

        self::assertSame([['success', 'deleted 1 thumbnail cache file(s)']], $GLOBALS['tmswp_test_wp_cli']);
        self::assertFileDoesNotExist($recent);
        self::assertFileExists($cacheDir . '/index.html');
        self::assertFileExists($cacheDir . '/ab/index.html');
    }

    /** `wp tmswp thumb status`は、サムネイルの数・合計の大きさ・失敗の印の数を出し、index.html・ロック・一時ファイルを数えない。 */
    #[RunInSeparateProcess]
    public function testStatusPrintsCountsWithoutIndexLockAndTemporaryFiles(): void
    {
        $this->loadWpCliDouble();
        [$thumbnail, $cacheDir] = $this->thumbnailWithCache();
        $cache = $thumbnail->cache();
        self::assertNotFalse(file_put_contents($cacheDir . '/ab/' . self::KEY . '.jpg', '12345'));
        self::assertNotFalse(file_put_contents($cacheDir . '/ab/' . self::OTHER_KEY . '.png', '123'));
        self::assertNotFalse(file_put_contents($cache->temporaryPath(self::KEY, 'jpg'), 'temporary'));
        self::assertNotFalse(file_put_contents($cacheDir . '/ab/' . self::KEY . '.lock', ''));
        $cache->markFailed('ab55555555555555555555555555555555555555');

        (new ThumbCommand($thumbnail))->status([], []);

        self::assertSame([['line', 'files: 2'], ['line', 'bytes: 8'], ['line', 'failed: 1']], $GLOBALS['tmswp_test_wp_cli']);
    }

    /** WP-CLIの環境では、Kernelへの登録で`wp tmswp thumb`を、登録したThumbnailのコマンドとして追加する。 */
    #[RunInSeparateProcess]
    public function testRegisterAddsTheCommandInWpCli(): void
    {
        $this->loadWpCliDouble();
        \define('WP_CLI', true);
        $thumbnail = new Thumbnail(new ThumbnailConfig(redirectMaxAge: 2 * 86400));

        $this->bootThumbnailKernel('Cli', $thumbnail);

        self::assertIsArray($GLOBALS['tmswp_test_wp_cli']);
        // coreのKernelが登録するコマンド(wp tmswp discover等)を除く
        $calls = array_values(array_filter($GLOBALS['tmswp_test_wp_cli'], static fn(mixed $call): bool => \is_array($call) && ($call[1] ?? null) === 'tmswp thumb'));
        self::assertCount(1, $calls);
        $call = $calls[0];
        self::assertIsArray($call);
        self::assertSame('add_command', $call[0] ?? null);
        $command = $call[2] ?? null;
        self::assertInstanceOf(ThumbCommand::class, $command);

        // 追加したコマンドは、登録したThumbnailの設定(redirectMaxAgeが2日)で動く
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be longer than the cache lifetime of the REST redirects (172800 seconds)');

        $command->clear([], ['days' => '2']);
    }

    /**
     * uploadsを一時ディレクトリにしたThumbnailと、キャッシュのディレクトリ(印・index.html・KEYのshardを作ったもの)。
     *
     * @return array{Thumbnail, string} Thumbnailと、キャッシュのディレクトリの実パス
     */
    private function thumbnailWithCache(): array
    {
        $uploads = realpath($this->createTempDirectory('thumb-command-uploads'));
        self::assertIsString($uploads);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $uploads, 'baseurl' => 'https://example.test/wp-content/uploads']);
        Functions\when('wp_mkdir_p')->alias(static fn(string $dir): bool => is_dir($dir) || mkdir($dir, 0755, true));
        $thumbnail = new Thumbnail(new ThumbnailConfig(redirectMaxAge: 86400));
        $thumbnail->cache()->ensureDirectory(self::KEY);

        return [$thumbnail, $uploads . '/tmswp-thumb'];
    }

    private function loadWpCliDouble(): void
    {
        $file = $this->createTempDirectory('wp-cli-double') . '/WP_CLI.php';
        self::assertNotFalse(file_put_contents($file, <<<'PHP'
<?php

declare(strict_types=1);

final class WP_CLI
{
    public static function add_command(string $name, object $command): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['add_command', $name, $command];
    }

    public static function line(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['line', $message];
    }

    public static function success(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['success', $message];
    }
}
PHP));

        require $file;
    }
}
