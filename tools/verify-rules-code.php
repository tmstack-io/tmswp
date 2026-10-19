<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$codeDir = $root . '/var/rules-code';
$analysisDir = $root . '/var/rules-analysis';
$analysisAppDir = $analysisDir . '/app';
$phpstanConfig = $analysisDir . '/phpstan.neon';
// PHPStan の tmpDir(writePhpstanConfig() の tmpDir と同じ場所)。拡張のソースは解析対象の外にあり、
// PHPStan はその変更で DI コンテナのキャッシュを作り直さないため、検証のたびに空にする。
$phpstanTmpDir = $root . '/var/phpstan-rules';

if (!is_dir($codeDir)) {
    throw new RuntimeException("rules コード例が抽出されていません: {$codeDir}");
}

$phpFiles = collectPhpFiles($codeDir);

if ($phpFiles === []) {
    throw new RuntimeException("検証対象の PHP コード例がありません: {$codeDir}");
}

foreach ($phpFiles as $phpFile) {
    runProcess([PHP_BINARY, '-l', $phpFile], $root);
}

clearDirectory($analysisDir);
clearDirectory($phpstanTmpDir);
copyDirectory($codeDir . '/app', $analysisAppDir);
writePhpstanConfig($phpstanConfig);

$phpstan = $root . '/vendor/bin/phpstan';

if (!is_file($phpstan)) {
    throw new RuntimeException("PHPStan 実行ファイルがありません: {$phpstan}");
}

runProcess([$phpstan, 'analyse', '-c', $phpstanConfig, '--memory-limit=1G'], $root);

fwrite(STDOUT, 'Rules code examples verified.' . PHP_EOL);

/**
 * @return list<string>
 */
function collectPhpFiles(string $dir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo) {
            continue;
        }

        if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
            continue;
        }

        $files[] = $fileInfo->getPathname();
    }

    sort($files);

    return $files;
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

        if ($fileInfo->isDir()) {
            if (!rmdir($fileInfo->getPathname())) {
                throw new RuntimeException("ディレクトリを削除できません: {$fileInfo->getPathname()}");
            }

            continue;
        }

        if (!unlink($fileInfo->getPathname())) {
            throw new RuntimeException("ファイルを削除できません: {$fileInfo->getPathname()}");
        }
    }
}

function copyDirectory(string $sourceDir, string $targetDir): void
{
    if (!is_dir($sourceDir)) {
        throw new RuntimeException("静的解析対象の app コード例がありません: {$sourceDir}");
    }

    ensureDirectory($targetDir);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo) {
            continue;
        }

        $relativePath = substr($fileInfo->getPathname(), strlen($sourceDir) + 1);

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

        if ($fileInfo->isDir()) {
            ensureDirectory($targetPath);

            continue;
        }

        ensureDirectory(dirname($targetPath));

        if (!copy($fileInfo->getPathname(), $targetPath)) {
            throw new RuntimeException("コード例をコピーできません: {$targetPath}");
        }
    }
}

function writePhpstanConfig(string $target): void
{
    $config = <<<'NEON'
includes:
  - ../../vendor/szepeviktor/phpstan-wordpress/extension.neon
  - ../../packages/core/phpstan/extension.neon

parameters:
  level: max
  paths:
    - app
  scanFiles:
    - ../../vendor/php-stubs/acf-pro-stubs/acf-pro-stubs.php
  treatPhpDocTypesAsCertain: false
  tmpDir: ../phpstan-rules
  dynamicConstantNames:
    - WP_DEBUG
    - WP_DEBUG_LOG
    - WP_DEBUG_DISPLAY
NEON;

    ensureDirectory(dirname($target));

    if (file_put_contents($target, $config . PHP_EOL) === false) {
        throw new RuntimeException("PHPStan 設定を書き込めません: {$target}");
    }
}

function ensureDirectory(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }

    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("ディレクトリを作成できません: {$dir}");
    }
}

/**
 * @param list<string> $command
 */
function runProcess(array $command, string $cwd): void
{
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd);

    if (!is_resource($process)) {
        throw new RuntimeException('プロセスを開始できません: ' . implode(' ', $command));
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($stdout !== false && $stdout !== '') {
        fwrite(STDOUT, $stdout);
    }

    if ($stderr !== false && $stderr !== '') {
        fwrite(STDERR, $stderr);
    }

    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('プロセス出力を読み取れません: ' . implode(' ', $command));
    }

    if ($exitCode !== 0) {
        throw new RuntimeException("プロセスが失敗しました({$exitCode}): " . implode(' ', $command));
    }
}
