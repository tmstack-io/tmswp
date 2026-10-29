<?php

declare(strict_types=1);

namespace TMSWP\Tools\Release;

use JsonException;
use PharIo\Version\Version;
use Symplify\MonorepoBuilder\Release\Contract\ReleaseWorker\ReleaseWorkerInterface;

/**
 * `monorepo-builder release vX.Y.Z`の1つ目のワーカー。タグを作る前に、リリースしてよい状態かを検査する(D-54)。
 *
 * 次のどれか1つでも満たさなければ、満たさない項目をすべて並べたReleaseExceptionで止める。
 * - 今のブランチがmainである
 * - originにmainのブランチがあり、`git fetch --no-tags origin +refs/heads/main:refs/remotes/origin/main`で取り直した
 *   origin/mainと、HEADが同じコミットである(設定のfetchのrefspecと、手元に残った古い追跡refには頼らない)
 * - 作業ツリーが空である(追跡していないファイルも無い)
 * - タグ`vX.Y.Z`が、手元にもoriginにも無い
 * - `Kernel::VERSION`、packages/*とstarters/*のcomposer.jsonの`tmswp/*`の制約とbranch-alias、
 *   examples/sample-siteのcomposer.jsonの`tmswp/*`の制約とpathリポジトリの`versions`が、タグの版と一致する
 * - CHANGELOG.mdに`## vX.Y.Z — 題名`の見出しがある
 *
 * 制約は`^X.Y`、PATCHが0より大きければ`^X.Y.Z`を正とする(monorepo-builderの`VersionUtils::getRequiredFormat()`と同じ規則)。
 * branch-aliasは`X.Y.x-dev`を正とし、packages/*は`dev-main`のbranch-aliasを必ず持つ。
 */
final readonly class CheckReleaseReadinessReleaseWorker implements ReleaseWorkerInterface
{
    private const string RELEASE_BRANCH = 'main';

    /**
     * @param string $repositoryDirectory monorepoのルート(gitのリポジトリ)のディレクトリ
     */
    public function __construct(private string $repositoryDirectory) {}

    public function getDescription(Version $version): string
    {
        return \sprintf(
            'リリースの前の検査: mainであること・origin/mainと同じであること・作業ツリーが空であること・タグ"v%s"が無いこと・版の一致・CHANGELOGの見出し',
            $version->getVersionString(),
        );
    }

    public function work(Version $version): void
    {
        $tag = 'v' . $version->getVersionString();
        $failures = [...$this->gitFailures($tag), ...$this->versionFailures($version), ...$this->changelogFailures($tag)];

        if ($failures !== []) {
            throw new ReleaseException(\sprintf(
                "リリースの前の検査に通りませんでした(%s)。タグを作らずに止めます。\n- %s",
                $tag,
                implode("\n- ", $failures),
            ));
        }
    }

    /**
     * ブランチ・origin/main・作業ツリー・タグの検査。gitのコマンド自体の失敗は、その場でReleaseExceptionにする。
     *
     * @return list<string> 満たさない項目の説明
     */
    private function gitFailures(string $tag): array
    {
        $git = new Git($this->repositoryDirectory);
        $failures = [];

        $branch = trim($git->run('rev-parse', '--abbrev-ref', 'HEAD'));
        if ($branch !== self::RELEASE_BRANCH) {
            $failures[] = \sprintf('今のブランチが%sです。%sで実行します', $branch, self::RELEASE_BRANCH);
        }

        // 設定のfetchのrefspecと、手元に残った古い追跡refに頼らず、originのmainを明示のrefspecで取り直して比べる
        $remoteBranch = 'refs/heads/' . self::RELEASE_BRANCH;
        $trackingRef = 'refs/remotes/origin/' . self::RELEASE_BRANCH;
        $remoteHeads = $git->run('ls-remote', '--heads', 'origin', $remoteBranch);
        if (preg_match('/^\S+\t' . preg_quote($remoteBranch, '/') . '$/m', $remoteHeads) !== 1) {
            $failures[] = \sprintf('originに%sのブランチがありません', self::RELEASE_BRANCH);
        } else {
            $git->run('fetch', '--quiet', '--no-tags', 'origin', '+' . $remoteBranch . ':' . $trackingRef);
            $head = trim($git->run('rev-parse', '--verify', 'HEAD^{commit}'));
            $originMain = trim($git->run('rev-parse', '--verify', $trackingRef . '^{commit}'));
            if ($head !== $originMain) {
                $failures[] = \sprintf('HEAD(%s)がorigin/%s(%s)と違います', $head, self::RELEASE_BRANCH, $originMain);
            }
        }

        $status = rtrim($git->run('status', '--porcelain', '--untracked-files=all'));
        if ($status !== '') {
            $failures[] = "作業ツリーに変更があります:\n" . $status;
        }

        if ($git->test(1, 'show-ref', '--verify', '--quiet', 'refs/tags/' . $tag)) {
            $failures[] = \sprintf('タグ%sが手元にあります', $tag);
        }

        if (trim($git->run('ls-remote', '--tags', 'origin', 'refs/tags/' . $tag)) !== '') {
            $failures[] = \sprintf('タグ%sがoriginにあります', $tag);
        }

        return $failures;
    }

    /**
     * `Kernel::VERSION`・制約・branch-alias・sample-siteの版の検査。
     *
     * @return list<string> 満たさない項目の説明
     */
    private function versionFailures(Version $version): array
    {
        $versionString = $version->getVersionString();
        $constraint = self::requiredConstraint($version);
        $alias = \sprintf('%d.%d.x-dev', $version->getMajor()->getValue() ?? 0, $version->getMinor()->getValue() ?? 0);
        $failures = [];

        $kernel = $this->read('packages/core/src/Kernel/Kernel.php');
        if (preg_match("/public const string VERSION = '([^']*)';/", $kernel, $matches) !== 1) {
            $failures[] = 'packages/core/src/Kernel/Kernel.phpにKernel::VERSIONの宣言が見つかりません';
        } elseif ($matches[1] !== $versionString) {
            $failures[] = \sprintf('Kernel::VERSIONが%sです。%sにします', $matches[1], $versionString);
        }

        foreach ($this->distributedPackageDirectories() as $directory) {
            $manifest = $this->manifest($directory . '/composer.json');
            $failures = [...$failures, ...self::constraintFailures($directory . '/composer.json', $manifest, $constraint)];

            $aliases = self::stringMap(self::stringMap($manifest['extra'] ?? [])['branch-alias'] ?? []);
            if (str_starts_with($directory, 'packages/') && !\array_key_exists('dev-main', $aliases)) {
                $failures[] = \sprintf('%s/composer.jsonにbranch-alias(dev-main)がありません。%sにします', $directory, $alias);
            }

            foreach ($aliases as $branch => $value) {
                if ($value !== $alias) {
                    $failures[] = \sprintf('%s/composer.jsonのbranch-alias(%s)が%sです。%sにします', $directory, $branch, self::describe($value), $alias);
                }
            }
        }

        $sampleSite = 'examples/sample-site/composer.json';
        $manifest = $this->manifest($sampleSite);
        $failures = [...$failures, ...self::constraintFailures($sampleSite, $manifest, $constraint)];
        $pathVersions = 0;

        foreach (self::listOf($manifest['repositories'] ?? []) as $repository) {
            $repository = self::stringMap($repository);
            if (($repository['type'] ?? null) !== 'path') {
                continue;
            }

            foreach (self::stringMap(self::stringMap($repository['options'] ?? [])['versions'] ?? []) as $package => $value) {
                ++$pathVersions;
                if ($value !== $versionString) {
                    $failures[] = \sprintf('%sのpathリポジトリのversionsの%sが%sです。%sにします', $sampleSite, $package, self::describe($value), $versionString);
                }
            }
        }

        if ($pathVersions === 0) {
            $failures[] = \sprintf('%sにpathリポジトリのversionsがありません', $sampleSite);
        }

        return $failures;
    }

    /**
     * CHANGELOG.mdの見出しの検査。
     *
     * @return list<string> 満たさない項目の説明
     */
    private function changelogFailures(string $tag): array
    {
        if (preg_match('/^## ' . preg_quote($tag, '/') . ' — \S/mu', $this->read('CHANGELOG.md')) === 1) {
            return [];
        }

        return [\sprintf('CHANGELOG.mdに「## %s — 題名」の見出しがありません', $tag)];
    }

    /**
     * 1つのcomposer.jsonのrequire・require-devの`tmswp/*`の制約の検査。
     *
     * @param array<string, mixed> $manifest
     *
     * @return list<string> 満たさない項目の説明
     */
    private static function constraintFailures(string $file, array $manifest, string $constraint): array
    {
        $failures = [];

        foreach (['require', 'require-dev'] as $section) {
            foreach (self::stringMap($manifest[$section] ?? []) as $package => $value) {
                if (str_starts_with($package, 'tmswp/') && $value !== $constraint) {
                    $failures[] = \sprintf('%sの%sの%sが%sです。%sにします', $file, $section, $package, self::describe($value), $constraint);
                }
            }
        }

        return $failures;
    }

    /**
     * 版から求める`tmswp/*`の制約。`^X.Y`、PATCHが0より大きければ`^X.Y.Z`。
     */
    private static function requiredConstraint(Version $version): string
    {
        $constraint = \sprintf('^%d.%d', $version->getMajor()->getValue() ?? 0, $version->getMinor()->getValue() ?? 0);
        $patch = $version->getPatch()->getValue() ?? 0;

        return $patch > 0 ? $constraint . '.' . $patch : $constraint;
    }

    /**
     * 配布するパッケージ(packages/*とstarters/*)の、ルートからの相対のディレクトリ。
     *
     * @return list<string>
     */
    private function distributedPackageDirectories(): array
    {
        $directories = [];

        foreach (['packages', 'starters'] as $parent) {
            $found = glob($this->repositoryDirectory . '/' . $parent . '/*/composer.json');
            if ($found === false || $found === []) {
                throw new ReleaseException(\sprintf('%s/*/composer.jsonが見つかりません: %s', $parent, $this->repositoryDirectory));
            }

            foreach ($found as $file) {
                $directories[] = $parent . '/' . basename(\dirname($file));
            }
        }

        return $directories;
    }

    /**
     * ルートからの相対のパスのcomposer.jsonを読む。読めない・JSONでない・オブジェクトでなければReleaseExceptionにする。
     *
     * @return array<string, mixed>
     */
    private function manifest(string $file): array
    {
        try {
            $manifest = json_decode($this->read($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ReleaseException(\sprintf('%sをJSONとして読めません: %s', $file, $exception->getMessage()), previous: $exception);
        }

        if (!\is_array($manifest)) {
            throw new ReleaseException(\sprintf('%sがJSONのオブジェクトではありません', $file));
        }

        return self::stringMap($manifest);
    }

    /**
     * ルートからの相対のパスのファイルを読む。読めなければReleaseExceptionにする。
     */
    private function read(string $file): string
    {
        $path = $this->repositoryDirectory . '/' . $file;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new ReleaseException(\sprintf('ファイルを読めません: %s', $path));
        }

        return $contents;
    }

    /**
     * JSONのオブジェクトだった値を、キーが文字列の配列として返す。配列でなければ空の配列とみなす。
     *
     * @return array<string, mixed>
     */
    private static function stringMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * JSONの配列だった値を、listとして返す。配列でなければ空のlistとみなす。
     *
     * @return list<mixed>
     */
    private static function listOf(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [];
    }

    /**
     * 照合に失敗した値を、メッセージに入れる文字列にする。
     */
    private static function describe(mixed $value): string
    {
        return \is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
