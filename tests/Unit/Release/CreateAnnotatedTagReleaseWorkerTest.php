<?php

declare(strict_types=1);

namespace TMSWP\Tests\Unit\Release;

use PharIo\Version\Version;
use Symfony\Component\Process\Process;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Tools\Release\CreateAnnotatedTagReleaseWorker;
use TMSWP\Tools\Release\ReleaseException;

/**
 * タグのワーカーを、一時ディレクトリのgitのリポジトリ(originは手元のbareのリポジトリ)で確かめる。
 */
final class CreateAnnotatedTagReleaseWorkerTest extends UnitTestCase
{
    /** origin(bare)と手元のリポジトリを置く、テストごとの作業用のディレクトリ。 */
    private string $workspace;

    private string $repository;

    private string $origin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->createTempDirectory('release');
        $this->origin = $this->workspace . '/origin.git';
        self::git($this->workspace, 'init', '--quiet', '--template=', '--bare', '--initial-branch=main', $this->origin);

        $this->repository = $this->workspace . '/repository';
        self::git($this->workspace, 'init', '--quiet', '--template=', '--initial-branch=main', $this->repository);
        self::git($this->repository, 'config', 'user.name', 'TMSWP Test');
        self::git($this->repository, 'config', 'user.email', 'test@example.com');
        self::git($this->repository, 'config', 'commit.gpgSign', 'false');
        self::git($this->repository, 'config', 'tag.gpgSign', 'false');
        self::assertNotFalse(file_put_contents($this->repository . '/README.md', "release\n"));
        self::git($this->repository, 'add', 'README.md');
        self::git($this->repository, 'commit', '--quiet', '-m', 'release');
        self::git($this->repository, 'remote', 'add', 'origin', $this->origin);
        self::git($this->repository, 'push', '--quiet', 'origin', 'main');
    }

    /**
     * gitのリポジトリを置いた作業用のディレクトリを、`rm -rf`で消す。
     *
     * UnitTestCaseの片付け(PHPでの再帰の削除)は、消すエントリの数に比例してメモリがテストの実行の終わりまで残り、
     * gitのリポジトリ(.git/の多数のファイル)をそれで消すと、ユニットテスト全体がmemory_limitの128MBを超えたため。
     * 空になった親のディレクトリは、UnitTestCaseが消す。
     */
    protected function tearDown(): void
    {
        $process = new Process(['rm', '-rf', $this->workspace]);
        $process->run();
        parent::tearDown();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function testCreatesAnAnnotatedTagAtHeadAndPushesOnlyThatTag(): void
    {
        $head = trim(self::git($this->repository, 'rev-parse', 'HEAD'));
        // 手元にだけある別のタグと、pushしていないmainのコミット(どちらも送られないことを確かめる)
        self::git($this->repository, 'tag', 'local-only');
        self::assertNotFalse(file_put_contents($this->repository . '/README.md', "not pushed\n"));
        self::git($this->repository, 'commit', '--quiet', '--all', '-m', 'not pushed');
        self::git($this->repository, 'checkout', '--quiet', $head);

        (new CreateAnnotatedTagReleaseWorker($this->repository))->work(new Version('v0.9.0'));

        self::assertSame('tag', trim(self::git($this->repository, 'cat-file', '-t', 'refs/tags/v0.9.0')));
        self::assertSame('v0.9.0', trim(self::git($this->repository, 'tag', '--list', '--format=%(contents:subject)', 'v0.9.0')));
        self::assertSame($head, trim(self::git($this->origin, 'rev-parse', 'refs/tags/v0.9.0^{commit}')));
        self::assertSame('tag', trim(self::git($this->origin, 'cat-file', '-t', 'refs/tags/v0.9.0')));
        self::assertSame("v0.9.0\n", self::git($this->origin, 'tag', '--list'));
        self::assertSame($head, trim(self::git($this->origin, 'rev-parse', 'refs/heads/main')));
    }

    public function testStopsAndKeepsTheLocalTagWhenThePushIsRejected(): void
    {
        // テンプレート無しで作ったbareのリポジトリには、hooks/が無い
        self::assertTrue(mkdir($this->origin . '/hooks'));
        $hook = $this->origin . '/hooks/pre-receive';
        self::assertNotFalse(file_put_contents($hook, "#!/bin/sh\necho 'rejected by test' >&2\nexit 1\n"));
        self::assertTrue(chmod($hook, 0755));

        try {
            (new CreateAnnotatedTagReleaseWorker($this->repository))->work(new Version('v0.9.0'));
            self::fail('ReleaseExceptionが投げられませんでした');
        } catch (ReleaseException $exception) {
            self::assertStringStartsWith('タグv0.9.0を手元に作りましたが、pushに失敗しました。', $exception->getMessage());
            self::assertStringContainsString('git push origin refs/tags/v0.9.0', $exception->getMessage());
            self::assertStringContainsString('rejected by test', $exception->getMessage());
        }

        self::assertSame("v0.9.0\n", self::git($this->repository, 'tag', '--list'));
        self::assertSame('', self::git($this->origin, 'tag', '--list'));
    }

    public function testStopsWhenTheTagCannotBeCreated(): void
    {
        self::git($this->repository, 'tag', 'v0.9.0');

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('gitのコマンドが失敗しました(終了コード128): git tag -a v0.9.0 -m v0.9.0');

        (new CreateAnnotatedTagReleaseWorker($this->repository))->work(new Version('v0.9.0'));
    }

    /**
     * テストの準備と確認のためにgitを実行し、標準出力を返す。失敗すればテストを失敗にする。
     */
    private static function git(string $directory, string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $directory);
        $process->run();
        if ($process->getExitCode() !== 0) {
            self::fail('git ' . implode(' ', $arguments) . "\n" . $process->getErrorOutput());
        }

        return $process->getOutput();
    }
}
