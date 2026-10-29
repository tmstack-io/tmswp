<?php

declare(strict_types=1);

namespace TMSWP\Tools\Release;

use PharIo\Version\Version;
use Symplify\MonorepoBuilder\Release\Contract\ReleaseWorker\ReleaseWorkerInterface;

/**
 * `monorepo-builder release vX.Y.Z`の2つ目のワーカー。HEADに注釈付きのタグ`vX.Y.Z`を作り、そのタグだけをoriginへpushする(D-54)。
 *
 * `git push --tags`のように手元のすべてのタグを送らない。mainへのcommit・pushはしない(mainへの反映はPRのマージで行う)。
 * gitのコマンドが失敗すれば、ReleaseExceptionで止める。
 */
final readonly class CreateAnnotatedTagReleaseWorker implements ReleaseWorkerInterface
{
    /**
     * @param string $repositoryDirectory monorepoのルート(gitのリポジトリ)のディレクトリ
     */
    public function __construct(private string $repositoryDirectory) {}

    public function getDescription(Version $version): string
    {
        return \sprintf('注釈付きのタグ"v%1$s"を作り、そのタグだけをoriginへpushする(git push origin refs/tags/v%1$s)', $version->getVersionString());
    }

    public function work(Version $version): void
    {
        $tag = 'v' . $version->getVersionString();
        $git = new Git($this->repositoryDirectory);

        $git->run('tag', '-a', $tag, '-m', $tag);

        try {
            $git->run('push', 'origin', 'refs/tags/' . $tag);
        } catch (ReleaseException $exception) {
            throw new ReleaseException(\sprintf(
                "タグ%1\$sを手元に作りましたが、pushに失敗しました。原因を直してから`git push origin refs/tags/%1\$s`で送るか、`git tag -d %1\$s`で消してからやり直します。\n%2\$s",
                $tag,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }
}
