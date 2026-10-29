<?php

declare(strict_types=1);

namespace TMSWP\Tests\Unit\Release;

use PharIo\Version\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Tools\Release\CheckReleaseReadinessReleaseWorker;
use TMSWP\Tools\Release\ReleaseException;

/**
 * リリースの前の検査のワーカーを、一時ディレクトリのgitのリポジトリ(originは手元のbareのリポジトリ)で確かめる。
 *
 * 各テストは、すべての検査に通る状態(mainで、origin/mainと同じで、版とCHANGELOGがv0.9.0にそろう)から1か所だけ崩し、
 * その項目だけが不合格として報告されることを確かめる。
 */
final class CheckReleaseReadinessReleaseWorkerTest extends UnitTestCase
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
        self::configureIdentity($this->repository);

        foreach (self::releasableFiles() as $file => $contents) {
            $this->write($file, $contents);
        }

        self::git($this->repository, 'add', '--all');
        self::git($this->repository, 'commit', '--quiet', '-m', 'release v0.9.0');
        self::git($this->repository, 'remote', 'add', 'origin', $this->origin);
        self::git($this->repository, 'push', '--quiet', '--set-upstream', 'origin', 'main');
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

    public function testPassesWhenEveryConditionHolds(): void
    {
        $this->worker()->work(new Version('v0.9.0'));

        // 検査はタグを作らない
        self::assertSame('', self::git($this->repository, 'tag', '--list'));
    }

    public function testPassesForAPatchReleaseWhoseConstraintsIncludeThePatch(): void
    {
        $this->replaceAndPush('packages/core/src/Kernel/Kernel.php', "'0.9.0'", "'0.9.1'");
        foreach (['packages/core/composer.json', 'packages/testing/composer.json', 'starters/theme/composer.json', 'examples/sample-site/composer.json'] as $file) {
            $this->replaceAndPush($file, '"^0.9"', '"^0.9.1"');
        }
        $this->replaceAndPush('examples/sample-site/composer.json', '"0.9.0"', '"0.9.1"');
        $this->replaceAndPush('CHANGELOG.md', '## v0.9.0 — ', '## v0.9.1 — ');

        $this->worker()->work(new Version('v0.9.1'));

        $this->addToAssertionCount(1);
    }

    public function testFailsWhenTheCurrentBranchIsNotMain(): void
    {
        self::git($this->repository, 'checkout', '--quiet', '-b', 'rewrite/v0');

        $this->assertFailsWith('今のブランチがrewrite/v0です');
    }

    public function testFailsWhenHeadIsAheadOfOriginMain(): void
    {
        $this->write('README.md', "local only\n");
        self::git($this->repository, 'add', 'README.md');
        self::git($this->repository, 'commit', '--quiet', '-m', 'local only');

        $this->assertFailsWith('がorigin/main(');
    }

    public function testFailsWhenOriginMainHasMovedSinceTheLastFetch(): void
    {
        $this->pushFromAnotherClone();

        $this->assertFailsWith('がorigin/main(');
    }

    /**
     * 設定のfetchのrefspecがmainを含まなくても、originのmainを明示のrefspecで取り直して比べる(手元の古い追跡refは
     * HEADと同じままのため、それと比べると通ってしまう)。
     */
    public function testFailsWhenOriginMainHasMovedAndTheConfiguredFetchExcludesMain(): void
    {
        self::git($this->repository, 'config', 'remote.origin.fetch', '+refs/heads/side:refs/remotes/origin/side');
        $this->pushFromAnotherClone();

        $this->assertFailsWith('がorigin/main(');
    }

    /**
     * originのmainが消えていれば、手元に残った古い追跡ref(HEADと同じ)があっても通さない。
     */
    public function testFailsWhenOriginHasNoMainBranch(): void
    {
        self::git($this->origin, 'update-ref', '-d', 'refs/heads/main');

        $this->assertFailsWith('originにmainのブランチがありません');
    }

    public function testFailsWhenATrackedFileIsModified(): void
    {
        $this->write('CHANGELOG.md', $this->read('CHANGELOG.md') . "\n追記\n");

        $this->assertFailsWith("作業ツリーに変更があります:\n M CHANGELOG.md");
    }

    public function testFailsWhenAnUntrackedFileExists(): void
    {
        $this->write('packages/core/new-file.txt', "untracked\n");

        $this->assertFailsWith("作業ツリーに変更があります:\n?? packages/core/new-file.txt");
    }

    public function testFailsWhenTheTagExistsLocally(): void
    {
        self::git($this->repository, 'tag', 'v0.9.0');

        $this->assertFailsWith('タグv0.9.0が手元にあります');
    }

    public function testFailsWhenTheTagExistsOnOrigin(): void
    {
        self::git($this->repository, 'push', '--quiet', 'origin', 'HEAD:refs/tags/v0.9.0');

        $this->assertFailsWith('タグv0.9.0がoriginにあります');
    }

    public function testFailsWhenKernelVersionDiffers(): void
    {
        $this->replaceAndPush('packages/core/src/Kernel/Kernel.php', "'0.9.0'", "'0.8.0'");

        $this->assertFailsWith('Kernel::VERSIONが0.8.0です。0.9.0にします');
    }

    public function testFailsWhenKernelVersionIsMissing(): void
    {
        $this->replaceAndPush('packages/core/src/Kernel/Kernel.php', 'public const string VERSION', 'public const string OTHER');

        $this->assertFailsWith('Kernel::VERSIONの宣言が見つかりません');
    }

    /**
     * @param string $file 書き換えるcomposer.json
     * @param string $search 書き換える前の文字列
     * @param string $replace 書き換えた後の文字列
     * @param string $expected 報告に含まれる文字列
     */
    #[DataProvider('versionMismatches')]
    public function testFailsWhenAVersionInAComposerJsonDiffers(string $file, string $search, string $replace, string $expected): void
    {
        $this->replaceAndPush($file, $search, $replace);

        $this->assertFailsWith($expected);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function versionMismatches(): iterable
    {
        yield 'require of a package' => [
            'packages/testing/composer.json',
            '"tmswp/core": "^0.9"',
            '"tmswp/core": "^0.8"',
            'packages/testing/composer.jsonのrequireのtmswp/coreが^0.8です。^0.9にします',
        ];
        yield 'require-dev of a package' => [
            'packages/core/composer.json',
            '"tmswp/testing": "^0.9"',
            '"tmswp/testing": "^0.9.0"',
            'packages/core/composer.jsonのrequire-devのtmswp/testingが^0.9.0です。^0.9にします',
        ];
        yield 'require of a starter' => [
            'starters/theme/composer.json',
            '"tmswp/core": "^0.9"',
            '"tmswp/core": "^0.10"',
            'starters/theme/composer.jsonのrequireのtmswp/coreが^0.10です。^0.9にします',
        ];
        yield 'branch-alias of a package' => [
            'packages/testing/composer.json',
            '"dev-main": "0.9.x-dev"',
            '"dev-main": "0.10.x-dev"',
            'packages/testing/composer.jsonのbranch-alias(dev-main)が0.10.x-devです。0.9.x-devにします',
        ];
        yield 'missing branch-alias of a package' => [
            'packages/core/composer.json',
            '"dev-main": "0.9.x-dev"',
            '"dev-feature": "0.9.x-dev"',
            'packages/core/composer.jsonにbranch-alias(dev-main)がありません。0.9.x-devにします',
        ];
        yield 'require of the sample site' => [
            'examples/sample-site/composer.json',
            '"tmswp/core": "^0.9"',
            '"tmswp/core": "^0.8"',
            'examples/sample-site/composer.jsonのrequireのtmswp/coreが^0.8です。^0.9にします',
        ];
        yield 'require-dev of the sample site' => [
            'examples/sample-site/composer.json',
            '"tmswp/testing": "^0.9"',
            '"tmswp/testing": "^0.8"',
            'examples/sample-site/composer.jsonのrequire-devのtmswp/testingが^0.8です。^0.9にします',
        ];
        yield 'versions of the path repository of the sample site' => [
            'examples/sample-site/composer.json',
            '"tmswp/core": "0.9.0"',
            '"tmswp/core": "0.8.0"',
            'examples/sample-site/composer.jsonのpathリポジトリのversionsのtmswp/coreが0.8.0です。0.9.0にします',
        ];
        yield 'no versions of the path repository of the sample site' => [
            'examples/sample-site/composer.json',
            '"versions"',
            '"other"',
            'examples/sample-site/composer.jsonにpathリポジトリのversionsがありません',
        ];
    }

    /**
     * @param string $heading CHANGELOG.mdの見出しの行
     */
    #[DataProvider('invalidChangelogHeadings')]
    public function testFailsWhenTheChangelogHasNoHeadingForTheVersion(string $heading): void
    {
        $this->replaceAndPush('CHANGELOG.md', '## v0.9.0 — 導入できる版', $heading);

        $this->assertFailsWith('CHANGELOG.mdに「## v0.9.0 — 題名」の見出しがありません');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidChangelogHeadings(): iterable
    {
        yield 'still unreleased' => ['## Unreleased'];
        yield 'hyphen instead of em dash' => ['## v0.9.0 - 導入できる版'];
        yield 'no title' => ['## v0.9.0 — '];
        yield 'without v' => ['## 0.9.0 — 導入できる版'];
    }

    public function testReportsEveryFailedConditionAtOnce(): void
    {
        self::git($this->repository, 'checkout', '--quiet', '-b', 'rewrite/v0');
        self::git($this->repository, 'tag', 'v0.9.0');
        $this->write('CHANGELOG.md', "# 変更履歴\n\n## Unreleased\n");

        $this->assertFailsWith(
            '今のブランチがrewrite/v0です',
            "作業ツリーに変更があります:\n M CHANGELOG.md",
            'タグv0.9.0が手元にあります',
            'CHANGELOG.mdに「## v0.9.0 — 題名」の見出しがありません',
        );
    }

    public function testStopsWhenOriginCannotBeRead(): void
    {
        self::git($this->repository, 'remote', 'set-url', 'origin', $this->origin . '-missing');

        $this->expectException(ReleaseException::class);
        $this->expectExceptionMessage('gitのコマンドが失敗しました(終了コード128): git ls-remote --heads origin refs/heads/main');

        $this->worker()->work(new Version('v0.9.0'));
    }

    /**
     * 別のcloneからoriginのmainへ1つコミットを送り、originのmainを手元のHEADより進める。
     */
    private function pushFromAnotherClone(): void
    {
        $other = $this->workspace . '/other';
        self::git($this->workspace, 'clone', '--quiet', '--template=', $this->origin, $other);
        self::configureIdentity($other);
        self::assertNotFalse(file_put_contents($other . '/README.md', "pushed from elsewhere\n"));
        self::git($other, 'add', 'README.md');
        self::git($other, 'commit', '--quiet', '-m', 'pushed from elsewhere');
        self::git($other, 'push', '--quiet', 'origin', 'main');
    }

    private function worker(): CheckReleaseReadinessReleaseWorker
    {
        return new CheckReleaseReadinessReleaseWorker($this->repository);
    }

    /**
     * 検査がReleaseExceptionで止まり、不合格の項目が`$expected`と同じ数だけ、同じ順で並ぶことを確かめる。
     *
     * @param string ...$expected 各項目に含まれる文字列
     */
    private function assertFailsWith(string ...$expected): void
    {
        try {
            $this->worker()->work(new Version('v0.9.0'));
        } catch (ReleaseException $exception) {
            $items = \array_slice(explode("\n- ", $exception->getMessage()), 1);
            self::assertStringStartsWith('リリースの前の検査に通りませんでした(v0.9.0)', $exception->getMessage());
            self::assertCount(\count($expected), $items, $exception->getMessage());

            foreach (array_values($expected) as $index => $text) {
                self::assertStringContainsString($text, $items[$index]);
            }

            return;
        }

        self::fail('ReleaseExceptionが投げられませんでした');
    }

    /**
     * リポジトリのファイルの文字列を書き換え、コミットしてoriginのmainへpushする(gitの検査に通したまま、版の検査だけを崩すため)。
     */
    private function replaceAndPush(string $file, string $search, string $replace): void
    {
        $contents = $this->read($file);
        self::assertStringContainsString($search, $contents, $file);
        $this->write($file, str_replace($search, $replace, $contents));
        self::git($this->repository, 'commit', '--quiet', '--all', '-m', 'change ' . $file);
        self::git($this->repository, 'push', '--quiet', 'origin', 'main');
    }

    private function read(string $file): string
    {
        $contents = file_get_contents($this->repository . '/' . $file);
        self::assertIsString($contents);

        return $contents;
    }

    private function write(string $file, string $contents): void
    {
        $path = $this->repository . '/' . $file;
        if (!is_dir(\dirname($path))) {
            self::assertTrue(mkdir(\dirname($path), 0775, true));
        }

        self::assertNotFalse(file_put_contents($path, $contents));
    }

    /**
     * すべての検査に通る、v0.9.0のリポジトリのファイル(ルートからの相対のパス => 中身)。
     *
     * @return array<string, string>
     */
    private static function releasableFiles(): array
    {
        $json = static fn(array $manifest): string => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        return [
            'packages/core/src/Kernel/Kernel.php' => "<?php\n\nfinal class Kernel\n{\n    public const string VERSION = '0.9.0';\n}\n",
            'packages/core/composer.json' => $json([
                'name' => 'tmswp/core',
                'require-dev' => ['tmswp/testing' => '^0.9'],
                'extra' => ['branch-alias' => ['dev-main' => '0.9.x-dev']],
            ]),
            'packages/testing/composer.json' => $json([
                'name' => 'tmswp/testing',
                'require' => ['php' => '>=8.3', 'tmswp/core' => '^0.9'],
                'extra' => ['branch-alias' => ['dev-main' => '0.9.x-dev']],
            ]),
            'starters/theme/composer.json' => $json([
                'name' => 'tmswp/starter-theme',
                'require' => ['tmswp/core' => '^0.9'],
            ]),
            'examples/sample-site/composer.json' => $json([
                'name' => 'local/sample-site',
                'require' => ['tmswp/core' => '^0.9'],
                'require-dev' => ['tmswp/testing' => '^0.9'],
                'repositories' => [
                    ['type' => 'path', 'url' => '../../packages/*', 'options' => ['versions' => ['tmswp/core' => '0.9.0', 'tmswp/testing' => '0.9.0']]],
                ],
            ]),
            'CHANGELOG.md' => "# 変更履歴\n\n## v0.9.0 — 導入できる版\n\n- 変更\n\n## v0.8.0 — 前の版\n",
        ];
    }

    /**
     * テストのリポジトリでコミットとタグを作れるよう、作者を設定し、利用者の設定の署名を切る。
     */
    private static function configureIdentity(string $directory): void
    {
        self::git($directory, 'config', 'user.name', 'TMSWP Test');
        self::git($directory, 'config', 'user.email', 'test@example.com');
        self::git($directory, 'config', 'commit.gpgSign', 'false');
        self::git($directory, 'config', 'tag.gpgSign', 'false');
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
