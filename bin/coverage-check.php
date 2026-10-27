<?php

declare(strict_types=1);

/*
 * 複数のclover(ユニット層と統合層)を行単位で合算し、行カバレッジが閾値以上かを、全体の合算とパッケージ別に判定する。
 *
 * ユニット層(ホストの PHPUnit 11)と統合層(wp-env のコンテナ内の PHPUnit 9.6)は内部のカバレッジ形式に
 * 互換が無いため、clover どうしを合わせる。ファイルのパスはリポジトリからの相対パスに正規化し、
 * 同じファイルの同じ行は、どれかの clover で実行されていれば実行済みとする。
 *
 * clover は、実行可能な行がメソッドの開始行と重なる場合、その行を type="method"(メソッド全体の集計値)として
 * 出力し、行単位の実行有無を持たない。合算は行単位の実行有無を持つ type="stmt" の行だけで行う。
 *
 * 閾値との比較は丸める前の値で行う(表示は小数2桁で切り捨てる)。
 */

/** ホスト側のリポジトリのルート(このスクリプトは bin/ に置く) */
$hostRoot = realpath(dirname(__DIR__));

/** wp-env のコンテナ内のリポジトリのルート(.wp-env.json の mappings の wp-content/plugins/tmswp) */
const CONTAINER_ROOT = '/var/www/html/wp-content/plugins/tmswp';

const USAGE = "Usage: php bin/coverage-check.php <minimum-percent> [--package=<name>:<minimum-percent> ...] <clover.xml> [<clover.xml> ...]\n";

/**
 * 入力の不備で判定できない場合に、理由を出して exit 2 で止める。
 */
function abortCheck(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(2);
}

/**
 * 引数の不備で判定できない場合に、usageを出してexit 2で止める。
 */
function abortUsage(): never
{
    fwrite(STDERR, USAGE);
    exit(2);
}

/**
 * 閾値の引数(0〜100の数値、%)を読む。数値でない・範囲外ならusageで止める。
 */
function parseMinimum(string $value): float
{
    if (!is_numeric($value)) {
        abortUsage();
    }

    $minimum = (float) $value;

    if ($minimum < 0.0 || $minimum > 100.0) {
        abortUsage();
    }

    return $minimum;
}

// $argc/$argv はスクリプトのトップレベルにだけ CLI SAPI が注入する暗黙の変数で、静的解析からは未定義に見える。
// 引数は $_SERVER['argv'] から取り、型と個数をここで検証する。
$arguments = $_SERVER['argv'] ?? null;

if (!is_array($arguments) || count($arguments) < 3 || !is_string($arguments[1])) {
    abortUsage();
}

$minimum = parseMinimum($arguments[1]);

/**
 * パッケージ名(packages/の直下のディレクトリ名) => 閾値
 *
 * @var array<string, float> $packageMinimums
 */
$packageMinimums = [];

/** @var list<string> $cloverPaths */
$cloverPaths = [];

foreach (array_slice($arguments, 2) as $argument) {
    if (!is_string($argument) || $argument === '') {
        abortUsage();
    }

    if (str_starts_with($argument, '--package=')) {
        $matches = [];

        if (preg_match('/\A--package=([a-z0-9][a-z0-9-]*):(.+)\z/', $argument, $matches) !== 1 || isset($packageMinimums[$matches[1]])) {
            abortUsage();
        }

        $packageMinimums[$matches[1]] = parseMinimum($matches[2]);

        continue;
    }

    $cloverPaths[] = $argument;
}

if ($cloverPaths === []) {
    abortUsage();
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
        "%s: %d/%d lines (%s%%)\n",
        $cloverPath,
        $coveredCount,
        $statementCount,
        formatCoverage($coveredCount, $statementCount),
    ));
}

/**
 * リポジトリからの相対パスが属するパッケージ名(packages/<名前>/の<名前>)を返す。packages/の外ならnull。
 */
function packageOf(string $relativePath): ?string
{
    $matches = [];

    return preg_match('#\Apackages/([^/]+)/#', $relativePath, $matches) === 1 ? $matches[1] : null;
}

/**
 * 実行済みの行数が、行数に対して閾値(%)以上か。丸める前の値で比べる(77.995%は閾値78を満たさない)。
 */
function meetsMinimum(int $covered, int $statements, float $minimum): bool
{
    return $covered * 100 >= $minimum * $statements;
}

/**
 * 行カバレッジを小数2桁で切り捨てた表記にする(閾値を満たさない値を、満たすように見せない)。
 */
function formatCoverage(int $covered, int $statements): string
{
    $hundredths = intdiv($covered * 10000, $statements);

    return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
}

/**
 * 判定の1行を出し、閾値を満たすかを返す。満たせばSTDOUT、満たさなければSTDERRに出す。
 *
 * @param array<string, array<int, bool>> $files リポジトリからの相対パス => [行番号 => 実行済みか]
 */
function reportCoverage(string $label, array $files, float $minimum): bool
{
    $statements = 0;
    $covered = 0;

    foreach ($files as $lines) {
        $statements += count($lines);
        $covered += count(array_filter($lines));
    }

    if ($statements === 0) {
        abortCheck("{$label} has zero statements");
    }

    $summary = sprintf('%s %s%% (%d/%d lines in %d files)', $label, formatCoverage($covered, $statements), $covered, $statements, count($files));

    if (!meetsMinimum($covered, $statements, $minimum)) {
        fwrite(STDERR, "{$summary} is below required {$minimum}%\n");

        return false;
    }

    fwrite(STDOUT, "{$summary} (>= {$minimum}%)\n");

    return true;
}

/**
 * パッケージ名 => [リポジトリからの相対パス => [行番号 => 実行済みか]]
 *
 * @var array<string, array<string, array<int, bool>>> $packages
 */
$packages = [];

if ($packageMinimums !== []) {
    // パッケージ別の判定では、閾値の無いパッケージのファイルを判定から漏らさない。閾値を渡したパッケージに
    // ファイルが1つも無い場合も、何も比べずに通さない
    foreach ($merged as $relativePath => $lines) {
        $package = packageOf($relativePath);

        if ($package === null || !isset($packageMinimums[$package])) {
            abortCheck("No package minimum for {$relativePath} (pass --package=<name>:<minimum-percent> for every measured package)");
        }

        $packages[$package][$relativePath] = $lines;
    }

    ksort($packageMinimums);

    foreach (array_keys($packageMinimums) as $package) {
        if (!isset($packages[$package])) {
            abortCheck("Coverage reports contain no files of package {$package}");
        }
    }
}

$passed = true;

foreach ($packageMinimums as $package => $packageMinimum) {
    $passed = reportCoverage("Package {$package} coverage", $packages[$package], $packageMinimum) && $passed;
}

$passed = reportCoverage('Combined coverage', $merged, $minimum) && $passed;

exit($passed ? 0 : 1);
