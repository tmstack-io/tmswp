<?php

declare(strict_types=1);

/*
 * `composer analyse`のPHPStanを、CIと同じ条件(4ワーカー・結果のキャッシュなし)で実行し、プロセスごとの
 * メモリの最大量(memory_get_peak_usage(true))と、composer.jsonの`analyse`の`--memory-limit`に対する余裕を出す。
 *
 * PHPStanのワーカーは別のプロセスで、PHPStanはワーカーごとのメモリを出力しない。そのため、追加のiniの
 * ディレクトリ(環境変数PHP_INI_SCAN_DIR。ワーカーにも引き継がれる)でauto_prepend_fileを設定し、各プロセスの
 * 終了時に最大量を記録させる。作業ファイルはvar/phpstan-memory/に置き、実行のたびに作り直す。
 *
 * 使い方: php tools/phpstan-memory.php
 * PHPStanが失敗した場合(エラーの検出・メモリの上限への到達)は、記録できた値を出したうえで非0で終わる。
 */

/** CIのStatic Analysisのジョブのワーカーの数(GitHub ActionsのubuntuのランナーのCPUの数) */
const WORKERS = 4;

$root = dirname(__DIR__);
$workDir = $root . '/var/phpstan-memory';
$logFile = $workDir . '/memory.log';
$memoryLimit = analyseMemoryLimit($root . '/composer.json');

clearDirectory($workDir);
ensureDirectory($workDir . '/ini');
writeTextFile($workDir . '/record.php', <<<'PHP'
<?php

declare(strict_types=1);

register_shutdown_function(static function (): void {
    $log = getenv('TMSWP_PHPSTAN_MEMORY_LOG');
    $argv = $_SERVER['argv'] ?? [];

    if (!is_string($log) || $log === '' || !is_array($argv)) {
        return;
    }

    $role = in_array('worker', $argv, true) ? 'worker' : 'main';
    file_put_contents($log, $role . ' ' . getmypid() . ' ' . memory_get_peak_usage(true) . "\n", FILE_APPEND | LOCK_EX);
});
PHP);
writeTextFile($workDir . '/ini/zz-tmswp-phpstan-memory.ini', 'auto_prepend_file=' . $workDir . '/record.php' . PHP_EOL);
$workers = WORKERS;
// 結果のキャッシュを使わないよう、tmpDirは作り直したこのディレクトリの下にする
writeTextFile($workDir . '/phpstan.neon', <<<NEON
includes:
  - ../../phpstan.neon.dist

parameters:
  tmpDir: {$workDir}/tmp
  parallel:
    maximumNumberOfProcesses: {$workers}

NEON);

$environment = getenv();
// 先頭の区切り文字は、PHPの既定の追加のiniのディレクトリ(拡張の読み込み等)を残したまま、このディレクトリを足す指定
$scanDir = getenv('PHP_INI_SCAN_DIR');
$environment['PHP_INI_SCAN_DIR'] = (is_string($scanDir) && $scanDir !== '' ? $scanDir : '') . PATH_SEPARATOR . $workDir . '/ini';
$environment['TMSWP_PHPSTAN_MEMORY_LOG'] = $logFile;

$process = proc_open(
    [PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '-c', $workDir . '/phpstan.neon', '--memory-limit=' . $memoryLimit['value'], '--no-progress'],
    [1 => STDOUT, 2 => STDERR],
    $pipes,
    $root,
    $environment,
);

if (!is_resource($process)) {
    throw new RuntimeException('PHPStanを起動できません');
}

$exitCode = proc_close($process);
$records = readRecords($logFile);
$workerPeaks = array_column(array_filter($records, static fn(array $record): bool => $record['role'] === 'worker'), 'peak');

foreach ($records as $record) {
    fwrite(STDOUT, sprintf('%-6s pid %-7d %8.1f MiB' . PHP_EOL, $record['role'], $record['pid'], $record['peak'] / 1048576));
}

if ($workerPeaks === []) {
    throw new RuntimeException("ワーカーのメモリの記録がありません(PHPStanの終了コード{$exitCode}): {$logFile}");
}

$maxPeak = max($workerPeaks);
fwrite(STDOUT, sprintf(
    'Max worker peak %.1f MiB of %d workers; memory limit %s (%.1f MiB); margin %.1f MiB (%.1f%% of the limit used)' . PHP_EOL,
    $maxPeak / 1048576,
    count($workerPeaks),
    $memoryLimit['value'],
    $memoryLimit['bytes'] / 1048576,
    ($memoryLimit['bytes'] - $maxPeak) / 1048576,
    $maxPeak / $memoryLimit['bytes'] * 100,
));

exit($exitCode);

/**
 * composer.jsonの`analyse`のスクリプトから`--memory-limit`の値を読む。
 *
 * @return array{value: string, bytes: int}
 */
function analyseMemoryLimit(string $composerFile): array
{
    $composer = json_decode(readTextFile($composerFile), true, 512, JSON_THROW_ON_ERROR);
    $script = is_array($composer) && is_array($composer['scripts'] ?? null) ? ($composer['scripts']['analyse'] ?? null) : null;
    $matches = [];

    if (!is_string($script) || preg_match('/--memory-limit=(\d+)([KMG])\b/', $script, $matches) !== 1) {
        throw new RuntimeException("composer.jsonのanalyseに--memory-limit=<数><K|M|G>がありません: {$composerFile}");
    }

    $units = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824];

    return ['value' => $matches[1] . $matches[2], 'bytes' => (int) $matches[1] * $units[$matches[2]]];
}

/**
 * 各プロセスが記録した「役割pid最大量」の行を読む。
 *
 * @return list<array{role: string, pid: int, peak: int}>
 */
function readRecords(string $logFile): array
{
    if (!is_file($logFile)) {
        return [];
    }

    $records = [];

    foreach (explode("\n", trim(readTextFile($logFile))) as $line) {
        $matches = [];

        if (preg_match('/\A(main|worker) (\d+) (\d+)\z/', $line, $matches) !== 1) {
            throw new RuntimeException("メモリの記録の行を読めません: {$line}");
        }

        $records[] = ['role' => $matches[1], 'pid' => (int) $matches[2], 'peak' => (int) $matches[3]];
    }

    return $records;
}

function readTextFile(string $file): string
{
    $contents = file_get_contents($file);

    if ($contents === false) {
        throw new RuntimeException("ファイルを読み込めません: {$file}");
    }

    return $contents;
}

function writeTextFile(string $file, string $contents): void
{
    if (file_put_contents($file, $contents) === false) {
        throw new RuntimeException("ファイルを書き込めません: {$file}");
    }
}

function clearDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        ensureDirectory($dir);

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo) {
            continue;
        }

        if ($fileInfo->isDir() ? !rmdir($fileInfo->getPathname()) : !unlink($fileInfo->getPathname())) {
            throw new RuntimeException("削除できません: {$fileInfo->getPathname()}");
        }
    }
}

function ensureDirectory(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("ディレクトリを作成できません: {$dir}");
    }
}
