<?php

declare(strict_types=1);

namespace TMSWP\Testing\Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Testing\UnitTestCase;

/**
 * テストごとの一時ディレクトリ(UsesTempDirectories)が、テストごとの親ディレクトリの下に作られ、探索結果ファイルを
 * 含めて親ディレクトリごと片付けられること。
 */
final class UsesTempDirectoriesTest extends UnitTestCase
{
    /** 一時ディレクトリは空で、`sys_get_temp_dir()`の下の、このテスト専用の1つの親ディレクトリにまとまる。 */
    public function testCreatesEmptyDirectoriesUnderOneParentPerTest(): void
    {
        $first = $this->createTempDirectory('alpha');
        $second = $this->createTempDirectory('beta');
        $third = $this->createTempDirectory('alpha');
        $parent = \dirname($first);

        foreach ([$first, $second, $third] as $dir) {
            self::assertSame(['.', '..'], scandir($dir), $dir);
            self::assertSame($parent, \dirname($dir));
        }

        self::assertSame(sys_get_temp_dir(), \dirname($parent));
        self::assertStringStartsWith('tmswp-test-', basename($parent));
        self::assertStringStartsWith('alpha-', basename($first));
        self::assertStringStartsWith('beta-', basename($second));
        // 同じprefixでも別のディレクトリになる
        self::assertNotSame($first, $third);
    }

    /** 別のテスト(テストケースの別のインスタンス)は別の親ディレクトリを使い、その片付けは、このテストのディレクトリに触れない。 */
    public function testEachTestHasItsOwnParentDirectory(): void
    {
        $mine = $this->createTempDirectory('mine');
        $other = new self('other');

        // まだ何も作っていないテストの片付けは、何も消さない
        $other->cleanupTempDirectories();
        self::assertDirectoryExists($mine);

        try {
            $theirs = $other->createTempDirectory('theirs');
            self::assertNotSame(\dirname($mine), \dirname($theirs));

            $other->cleanupTempDirectories();
            self::assertDirectoryDoesNotExist(\dirname($theirs));
            self::assertDirectoryExists($mine);
        } finally {
            $other->cleanupTempDirectories();
        }
    }

    /**
     * KernelConfigがappDirの親に書く探索結果ファイルも親ディレクトリの中に収まり、片付けで中身ごと消える。
     * appDirが一時ディレクトリの下にある場合(テーマのルートを一時ディレクトリにする)と、一時ディレクトリそのものの
     * 場合(探索結果ファイルは親ディレクトリの直下に書かれる)の両方を確かめる。
     */
    public function testCleanupRemovesTheParentIncludingDiscoveryFiles(): void
    {
        $root = $this->createTempDirectory('theme');
        $parent = \dirname($root);
        $realParent = realpath($parent);
        self::assertIsString($realParent);
        self::assertTrue(mkdir($root . '/app'));
        // 入れ子のディレクトリとファイルも消えることを確かめる
        self::assertTrue(mkdir($root . '/assets/css', 0775, true));
        self::assertNotFalse(file_put_contents($root . '/assets/css/style.css', "body {}\n"));

        $files = [];

        foreach ([$root . '/app', $this->createTempDirectory('app')] as $appDir) {
            $config = KernelConfig::theme($appDir, 'Example\\Tests\\UsesTempDirectories', true);
            $discoveryFile = new DiscoveryFile($config);
            $discoveryFile->write((new Discovery($config, $discoveryFile))->scan());

            self::assertFileExists($config->discoveryFile());
            self::assertStringStartsWith($realParent . DIRECTORY_SEPARATOR, $config->discoveryFile());
            $files[] = $config->discoveryFile();
        }

        self::assertSame($realParent . DIRECTORY_SEPARATOR . 'tmswp-discovery.php', $files[1]);

        $this->cleanupTempDirectories();

        self::assertDirectoryDoesNotExist($parent);

        foreach ($files as $file) {
            self::assertFileDoesNotExist($file);
        }

        // 片付けの後は、新しい親ディレクトリを作る。2回目の片付けは何もしない
        $next = $this->createTempDirectory('next');
        self::assertNotSame($parent, \dirname($next));
        $this->cleanupTempDirectories();
        $this->cleanupTempDirectories();
        self::assertDirectoryDoesNotExist(\dirname($next));
    }

    /** テストが親ディレクトリを自分で消していても、片付けは失敗しない。 */
    public function testCleanupToleratesAParentAlreadyRemovedByTheTest(): void
    {
        $dir = $this->createTempDirectory('removed');
        $parent = \dirname($dir);
        self::assertTrue(rmdir($dir));
        self::assertTrue(rmdir($parent));

        $this->cleanupTempDirectories();

        self::assertDirectoryDoesNotExist($parent);
        self::assertNotSame($parent, \dirname($this->createTempDirectory('again')));
    }

    /** ディレクトリを作れないときは、黙って続けずにテストを失敗させる。 */
    public function testFailsWhenTheDirectoryCannotBeCreated(): void
    {
        $parent = \dirname($this->createTempDirectory('seed'));
        // 通常のファイルの下には、ディレクトリを作れない
        self::assertNotFalse(file_put_contents($parent . '/blocker', ''));
        $warnings = [];
        $failure = null;

        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        }, E_WARNING);

        try {
            $this->createTempDirectory('blocker/inner');
        } catch (AssertionFailedError $exception) {
            $failure = $exception->getMessage();
        } finally {
            restore_error_handler();
        }

        self::assertIsString($failure, 'createTempDirectory() did not fail');
        self::assertStringStartsWith("一時ディレクトリを作成できません: {$parent}/blocker/inner-", $failure);
        self::assertCount(1, $warnings);
        self::assertStringStartsWith('mkdir(): ', $warnings[0]);
    }
}
