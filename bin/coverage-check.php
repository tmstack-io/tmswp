<?php

declare(strict_types=1);

// $argc/$argv はスクリプトのトップレベルにだけ CLI SAPI が注入する暗黙の変数で、静的解析からは未定義に見える。
// 引数は $_SERVER['argv'] から取り、型と個数をここで検証する。
$arguments = $_SERVER['argv'] ?? null;

if (!is_array($arguments) || count($arguments) !== 3 || !is_string($arguments[1]) || !is_numeric($arguments[2])) {
    fwrite(STDERR, "Usage: php bin/coverage-check.php <clover.xml> <minimum-percent>\n");
    exit(2);
}

$cloverPath = $arguments[1];
$minimum = (float) $arguments[2];
$xml = simplexml_load_file($cloverPath);

if ($xml === false) {
    fwrite(STDERR, "Cannot read coverage report: {$cloverPath}\n");
    exit(2);
}

$metrics = $xml->xpath('//project/metrics');

if ($metrics === null || $metrics === false || $metrics === []) {
    fwrite(STDERR, "Coverage report does not contain project metrics: {$cloverPath}\n");
    exit(2);
}

$projectMetrics = null;

foreach ($metrics as $metricNode) {
    $projectMetrics = $metricNode;
    break;
}

if (!$projectMetrics instanceof SimpleXMLElement) {
    fwrite(STDERR, "Coverage report does not contain project metrics: {$cloverPath}\n");
    exit(2);
}

$statements = (int) $projectMetrics['statements'];
$coveredStatements = (int) $projectMetrics['coveredstatements'];

if ($statements === 0) {
    fwrite(STDERR, "Coverage report contains zero statements: {$cloverPath}\n");
    exit(2);
}

$coverage = round($coveredStatements / $statements * 100, 2);

if ($coverage < $minimum) {
    fwrite(STDERR, "Coverage {$coverage}% is below required {$minimum}%\n");
    exit(1);
}

fwrite(STDOUT, "Coverage {$coverage}% (>= {$minimum}%)\n");
