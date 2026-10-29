<?php

declare(strict_types=1);

namespace TMSWP\Tools\Release;

use Symfony\Component\Process\Process;

/**
 * 1つのリポジトリのディレクトリでgitのコマンドを実行する。
 *
 * 引数は配列でgitへ渡し、シェルを通さない。
 */
final readonly class Git
{
    /**
     * @param string $directory gitのコマンドを実行するリポジトリのディレクトリ
     */
    public function __construct(private string $directory) {}

    /**
     * gitのコマンドを実行し、標準出力を返す。終了コードが0でなければ、標準エラーを添えてReleaseExceptionを投げる。
     *
     * @param string ...$arguments `git`に続く引数
     */
    public function run(string ...$arguments): string
    {
        $arguments = array_values($arguments);
        $process = $this->start($arguments);

        if ($process->getExitCode() !== 0) {
            throw self::failure($arguments, $process);
        }

        return $process->getOutput();
    }

    /**
     * gitのコマンドを実行し、終了コードが0なら真、`$falseExitCode`なら偽を返す。ほかの終了コードはReleaseExceptionにする。
     *
     * `git show-ref --verify --quiet`(無ければ1)のように、終了コードで有無を答えるコマンドに使う。
     *
     * @param int $falseExitCode 偽とみなす終了コード
     * @param string ...$arguments `git`に続く引数
     */
    public function test(int $falseExitCode, string ...$arguments): bool
    {
        $arguments = array_values($arguments);
        $process = $this->start($arguments);

        return match ($process->getExitCode()) {
            0 => true,
            $falseExitCode => false,
            default => throw self::failure($arguments, $process),
        };
    }

    /**
     * @param list<string> $arguments
     */
    private function start(array $arguments): Process
    {
        $process = new Process(['git', ...$arguments], $this->directory, null, null, 300.0);
        $process->run();

        return $process;
    }

    /**
     * @param list<string> $arguments
     */
    private static function failure(array $arguments, Process $process): ReleaseException
    {
        return new ReleaseException(\sprintf(
            "gitのコマンドが失敗しました(終了コード%s): git %s\n%s",
            (string) $process->getExitCode(),
            implode(' ', $arguments),
            trim($process->getErrorOutput() . $process->getOutput()),
        ));
    }
}
