<?php

declare(strict_types=1);

/*
 * Builds CI's cells and checks the repository. Run from the repository
 * root. See README.md in this directory.
 *
 *   --project <p>  prints <p>'s cells as JSON, for php-project.yml.
 *                  Fails only on <p>'s own problems, so one broken
 *                  project doesn't stop the others.
 *   --check        fails on any problem in the repository, for ci.yml.
 *   --all          prints every project's cells as JSON.
 *
 * Problems are printed as GitHub annotations naming the file at fault.
 */

use OpenTelemetry\Contrib\Ci\Matrix;
use OpenTelemetry\Contrib\Ci\Repository;

require __DIR__ . '/vendor/autoload.php';

$matrix = new Matrix(
    supported: ['8.1', '8.2', '8.3', '8.4'],
    experimental: ['8.5'],
    coverage: '8.4',
);

function fail(string ...$problems): never
{
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(1);
}

function output(mixed $value): void
{
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}

$repository = Repository::load('.');

switch ($argv[1] ?? '') {
    case '--project':
        if (!isset($argv[2])) {
            break;
        }
        $notAProject = "::error::src/$argv[2] is not a project CI tests. make projects lists them.";
        $project = $repository->project($argv[2]) ?? fail($notAProject);
        if ($problems = $matrix->projectProblems($project, $repository->services)) {
            fail(...$problems);
        }
        $matrix->runs($project) || fail($notAProject);
        output($matrix->cells($project));
        exit(0);
    case '--check':
    case '--all':
        if ($problems = $matrix->problems($repository)) {
            fail(...$problems);
        }
        if ($argv[1] === '--all') {
            output($matrix->all($repository));
        }
        exit(0);
}

fwrite(STDERR, "Usage: php .github/ci/matrix.php --project <path under src/> | --check | --all\n");
exit(2);
