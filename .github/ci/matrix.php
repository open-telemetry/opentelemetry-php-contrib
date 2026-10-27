<?php

declare(strict_types=1);

/*
 * Prints the CI matrix for the projects touched by the paths on stdin,
 * or for every project with --all, as JSON: a list of projects, each
 * with its own cells. Run from the repository root.
 *
 * Fails, printing each problem as a GitHub annotation, when the
 * repository is inconsistent: a project CI would silently skip, or an
 * extra.ci this can't follow.
 *
 * php.yml's detect job runs this on the real repository, after the
 * tests in tests/ have passed. Its output is the matrix for the php
 * job. See "Pipeline" in README.md in this directory.
 */

use OpenTelemetry\Contrib\Ci\Matrix;
use OpenTelemetry\Contrib\Ci\Repository;

require __DIR__ . '/vendor/autoload.php';

$matrix = new Matrix(
    supported: ['8.1', '8.2', '8.3', '8.4'],
    experimental: ['8.5'],
    coverage: '8.4',
);

$repository = Repository::load('.');

$problems = $matrix->problems($repository);
if ($problems) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(1);
}

$changed = null;
if (($argv[1] ?? '') !== '--all') {
    $changed = array_values(array_filter(explode("\n", stream_get_contents(STDIN))));
}

echo json_encode($matrix->projects($repository, $changed), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
