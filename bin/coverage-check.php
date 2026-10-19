<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php bin/coverage-check.php <clover.xml> <minimum-percent>\n");
    exit(2);
}

$cloverPath = $argv[1];
$minimum = (float) $argv[2];
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
