<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Tests\Support\UnitTestCase;

final class TmsCheckTest extends UnitTestCase
{
    public function testTmsCheckPassesWhenActionsHaveMatchingTemplates(): void
    {
        $theme = $this->createTempDirectory('tms-check-ok');
        $actions = $theme . '/actions';

        $this->writeFile($theme . '/page-contact.php', "<?php\n");
        $this->writeFile($actions . '/page-contact.php', "<?php\n");

        $result = $this->runTmsCheck($actions, $theme);

        self::assertSame(0, $result['code']);
        self::assertSame('', $result['stderr']);
    }

    public function testTmsCheckReportsOrphanActions(): void
    {
        $theme = $this->createTempDirectory('tms-check-orphan');
        $actions = $theme . '/actions';

        $this->writeFile($theme . '/page-contact.php', "<?php\n");
        $this->writeFile($actions . '/page-contact.php', "<?php\n");
        $this->writeFile($actions . '/orphan.php', "<?php\n");

        $result = $this->runTmsCheck($actions, $theme);

        self::assertSame(1, $result['code']);
        self::assertSame($actions . '/orphan.php:1: orphan actions file has no matching template' . PHP_EOL, $result['stderr']);
    }

    public function testTmsCheckRejectsMissingActionsDirectory(): void
    {
        $theme = $this->createTempDirectory('tms-check-missing');
        $missing = $theme . '/actions';

        $result = $this->runTmsCheck($missing, $theme);

        self::assertSame(2, $result['code']);
        self::assertSame("actions directory does not exist: {$missing}" . PHP_EOL, $result['stderr']);
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("テストファイル用ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("テストファイルを書き込めません: {$path}");
        }
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runTmsCheck(string $actionsDir, string $templateRoot): array
    {
        $script = \dirname(__DIR__, 2) . '/bin/tms-check';
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([PHP_BINARY, $script, $actionsDir, $templateRoot], $descriptors, $pipes);

        if (!\is_resource($process)) {
            self::fail('tms-check プロセスを起動できません。');
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
