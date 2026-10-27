<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$outputDir = $root . '/var/rules-code';
// 各パッケージのrules/(レシピはパッケージごとに置く)。mdの無いディレクトリからは何も抽出しない
$rulesDirs = glob($root . '/packages/*/rules', GLOB_ONLYDIR);

if ($rulesDirs === false || $rulesDirs === []) {
    throw new RuntimeException("rules ディレクトリがありません: {$root}/packages/*/rules");
}

sort($rulesDirs);
clearDirectory($outputDir);

foreach ($rulesDirs as $rulesDir) {
    $iterator = new DirectoryIterator($rulesDir);

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'md') {
            continue;
        }

        extractCodeBlocks($fileInfo->getPathname(), $outputDir);
    }
}

function extractCodeBlocks(string $markdownFile, string $outputDir): void
{
    $markdown = file_get_contents($markdownFile);

    if (!is_string($markdown)) {
        throw new RuntimeException("rules ファイルを読み込めません: {$markdownFile}");
    }

    $matches = [];
    preg_match_all('/```php\s+(.*?)```/s', $markdown, $matches);
    $index = 0;

    foreach ($matches[1] as $code) {
        if (!str_starts_with(ltrim($code), '<?php')) {
            continue;
        }

        ++$index;
        $target = targetPath($code, $markdownFile, $outputDir, $index);
        ensureDirectory(dirname($target));

        if (is_file($target)) {
            throw new RuntimeException("コード例の出力先が重複しています: {$target}");
        }

        if (file_put_contents($target, trim($code) . PHP_EOL) === false) {
            throw new RuntimeException("コード例を書き込めません: {$target}");
        }
    }
}

function targetPath(string $code, string $markdownFile, string $outputDir, int $index): string
{
    $matches = [];
    if (preg_match('/\nnamespace\s+App\\\\([^;]+);/m', $code, $matches) === 1) {
        $relativeNamespace = str_replace('\\', DIRECTORY_SEPARATOR, $matches[1]);
        $classMatches = [];

        // クラスの修飾子(final・readonly・abstract)は、PHPが許すどの順・組み合わせでも読む(`final readonly class`等)
        if (preg_match('/\n(?:(?:final|readonly|abstract)\s+)*(?:class|enum)\s+([A-Za-z][A-Za-z0-9_]*)\b/m', $code, $classMatches) !== 1) {
            throw new RuntimeException("namespace付きコード例にクラス名がありません: {$markdownFile}");
        }

        return $outputDir . '/app/' . $relativeNamespace . DIRECTORY_SEPARATOR . $classMatches[1] . '.php';
    }

    $baseName = basename($markdownFile, '.md');

    return $outputDir . '/templates/' . $baseName . '-' . $index . '.php';
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

function ensureDirectory(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }

    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("ディレクトリを作成できません: {$dir}");
    }
}
