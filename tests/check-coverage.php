<?php

declare(strict_types=1);

$buildDir = dirname(__DIR__) . '/build';
$coverageFile = $buildDir . '/coverage.xml';

if (!is_dir($buildDir) && !mkdir($buildDir, 0777, true) && !is_dir($buildDir)) {
    fwrite(STDERR, "Unable to create build directory.\n");
    exit(1);
}

$command = escapeshellarg(dirname(__DIR__) . '/vendor/bin/phpunit')
    . ' --coverage-clover ' . escapeshellarg($coverageFile)
    . ' --colors=never';

passthru($command, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "Coverage run failed. Install and enable Xdebug, PCOV, or phpdbg to generate coverage.\n");
    exit($exitCode);
}

$coverage = simplexml_load_file($coverageFile);
if ($coverage === false) {
    fwrite(STDERR, "Unable to read coverage report.\n");
    exit(1);
}

$project = $coverage->project;
$metrics = $project->metrics;
$covered = (int) $metrics['coveredstatements'];
$total = (int) $metrics['statements'];

if ($total === 0 || $covered !== $total) {
    $percentage = $total === 0 ? 0.0 : ($covered / $total) * 100;
    fwrite(STDERR, sprintf("Code coverage is %.2f%%; expected 100.00%%.\n", $percentage));
    exit(1);
}

fwrite(STDOUT, "Code coverage is 100.00%.\n");
