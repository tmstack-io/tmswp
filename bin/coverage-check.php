<?php

declare(strict_types=1);

/*
 * 複数の clover(ユニット層と統合層)を行単位で合算し、行カバレッジが閾値以上かを判定する。
 *
 * ユニット層(ホストの PHPUnit 11)と統合層(wp-env のコンテナ内の PHPUnit 9.6)は内部のカバレッジ形式に
 * 互換が無いため、clover どうしを合わせる。ファイルのパスはリポジトリからの相対パスに正規化し、
 * 同じファイルの同じ行は、どれかの clover で実行されていれば実行済みとする。
 *
 * clover は、実行可能な行がメソッドの開始行と重なる場合、その行を type="method"(メソッド全体の集計値)として
 * 出力し、行単位の実行有無を持たない。合算は行単位の実行有無を持つ type="stmt" の行だけで行う。
 */

/** ホスト側のリポジトリのルート(このスクリプトは bin/ に置く) */
$hostRoot = realpath(dirname(__DIR__));

/** wp-env のコンテナ内のリポジトリのルート(.wp-env.json の mappings の wp-content/plugins/tmswp) */
const CONTAINER_ROOT = '/var/www/html/wp-content/plugins/tmswp';

const USAGE = "Usage: php bin/coverage-check.php <minimum-percent> <clover.xml> [<clover.xml> ...]\n";

/**
 * 入力の不備で判定できない場合に、理由を出して exit 2 で止める。
 */
function abortCheck(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(2);
}

// $argc/$argv はスクリプトのトップレベルにだけ CLI SAPI が注入する暗黙の変数で、静的解析からは未定義に見える。
// 引数は $_SERVER['argv'] から取り、型と個数をここで検証する。
$arguments = $_SERVER['argv'] ?? null;

if (!is_array($arguments) || count($arguments) < 3 || !is_string($arguments[1]) || !is_numeric($arguments[1])) {
    fwrite(STDERR, USAGE);
    exit(2);
}

$minimum = (float) $arguments[1];

if ($minimum < 0.0 || $minimum > 100.0) {
    fwrite(STDERR, USAGE);
    exit(2);
}

/** @var list<string> $cloverPaths */
$cloverPaths = [];

foreach (array_slice($arguments, 2) as $argument) {
    if (!is_string($argument) || $argument === '') {
        fwrite(STDERR, USAGE);
        exit(2);
    }

    $cloverPaths[] = $argument;
}

if ($hostRoot === false) {
    abortCheck('Cannot resolve repository root: ' . dirname(__DIR__));
}

$roots = [$hostRoot, CONTAINER_ROOT];

/**
 * clover のファイルの絶対パスを、リポジトリからの相対パスにする。
 *
 * 同じファイルの別表記(`./`、重複したスラッシュ、`../`)を同じ計測対象にするため、パスの要素を正規化してから
 * ルートの中かを確かめる。絶対パスでない、ファイルシステムのルートより上を指す、どのルートの中にも無い場合は止める。
 *
 * @param list<string> $roots
 */
function normalizePath(string $path, array $roots, string $cloverPath): string
{
    if (!str_starts_with($path, '/')) {
        abortCheck("Cannot normalize path {$path} in {$cloverPath} (not an absolute path)");
    }

    $segments = [];

    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            if ($segments === []) {
                abortCheck("Cannot normalize path {$path} in {$cloverPath} (points above the filesystem root)");
            }

            array_pop($segments);

            continue;
        }

        $segments[] = $segment;
    }

    $canonicalPath = '/' . implode('/', $segments);

    foreach ($roots as $root) {
        $prefix = $root . '/';

        if (str_starts_with($canonicalPath, $prefix)) {
            return substr($canonicalPath, strlen($prefix));
        }
    }

    abortCheck("Cannot normalize path {$path} in {$cloverPath} (expected under " . implode(' or ', $roots) . ')');
}

/**
 * clover の行の属性を、int で表せる範囲の整数にする。
 *
 * 受け付けるのは、先頭に 0 が付かない 10 進表記("0" は可。"01"・"00" は不可)で、$minimum 以上 PHP_INT_MAX 以下の値だけ。
 * PHPUnit の clover はこれ以外の表記を出力しないため、それ以外は壊れた入力として止める。
 */
function parseLineAttribute(string $value, int $minimum, string $fileName, string $cloverPath): int
{
    $parsed = ctype_digit($value)
        ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]])
        : false;

    if ($parsed === false) {
        abortCheck("Invalid line entry in {$fileName} of {$cloverPath} (num must be 1..PHP_INT_MAX, count must be 0..PHP_INT_MAX)");
    }

    return $parsed;
}

/**
 * リポジトリからの相対パス => [行番号 => 実行済みか]
 *
 * @var array<string, array<int, bool>> $merged
 */
$merged = [];

foreach ($cloverPaths as $cloverPath) {
    if (!is_file($cloverPath)) {
        abortCheck("Cannot read coverage report: {$cloverPath}");
    }

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($cloverPath);
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        abortCheck("Cannot read coverage report: {$cloverPath}");
    }

    if ($xml->getName() !== 'coverage' || $xml->xpath('/coverage/project') === []) {
        abortCheck("Not a clover coverage report: {$cloverPath}");
    }

    $files = $xml->xpath('/coverage/project//file');

    if ($files === null || $files === false || $files === []) {
        abortCheck("Coverage report contains no files: {$cloverPath}");
    }

    $statementCount = 0;
    $coveredCount = 0;

    foreach ($files as $file) {
        $name = (string) $file['name'];

        if ($name === '') {
            abortCheck("Coverage report contains a file without name: {$cloverPath}");
        }

        $relativePath = normalizePath($name, $roots, $cloverPath);
        $merged[$relativePath] ??= [];

        foreach ($file->line as $line) {
            if ((string) $line['type'] !== 'stmt') {
                continue;
            }

            $number = parseLineAttribute((string) $line['num'], 1, $name, $cloverPath);
            $executed = parseLineAttribute((string) $line['count'], 0, $name, $cloverPath) > 0;
            $merged[$relativePath][$number] = ($merged[$relativePath][$number] ?? false) || $executed;
            $statementCount++;
            $coveredCount += $executed ? 1 : 0;
        }
    }

    if ($statementCount === 0) {
        abortCheck("Coverage report contains zero statements: {$cloverPath}");
    }

    fwrite(STDOUT, sprintf(
        "%s: %d/%d lines (%.2f%%)\n",
        $cloverPath,
        $coveredCount,
        $statementCount,
        $coveredCount / $statementCount * 100,
    ));
}

$statements = 0;
$coveredStatements = 0;

foreach ($merged as $lines) {
    $statements += count($lines);
    $coveredStatements += count(array_filter($lines));
}

$coverage = round($coveredStatements / $statements * 100, 2);
$summary = "Combined coverage {$coverage}% ({$coveredStatements}/{$statements} lines in " . count($merged) . ' files)';

if ($coverage < $minimum) {
    fwrite(STDERR, "{$summary} is below required {$minimum}%\n");
    exit(1);
}

fwrite(STDOUT, "{$summary} (>= {$minimum}%)\n");
