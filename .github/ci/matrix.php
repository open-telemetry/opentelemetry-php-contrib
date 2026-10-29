<?php

declare(strict_types=1);

/*
 * Builds CI's cells and checks the repository. Run from the repository
 * root. See README.md in this directory.
 *
 *   --project <p>  prints <p>'s cells as JSON, for php-project.yml.
 *                  Fails only on <p>'s own problems, so one broken
 *                  project doesn't stop the others.
 *   --services <p> prints the compose services <p>'s tests need, one per
 *                  line, for make services. Fails as --project does.
 *   --projects     prints each project CI runs, one per line, for
 *                  make projects.
 *   --generate     writes a workflow for each project CI runs, removes
 *                  the workflows of projects that are gone, and rewrites
 *                  the root README's project list.
 *   --check        fails on any problem in the repository, a file that
 *                  differs from what --generate writes, or a project
 *                  README without its badge, for ci.yml.
 *   --all          prints every project's cells as JSON.
 *
 * Problems are printed as GitHub annotations naming the file at fault.
 */

use OpenTelemetry\Contrib\Ci\Badges;
use OpenTelemetry\Contrib\Ci\Matrix;
use OpenTelemetry\Contrib\Ci\Project;
use OpenTelemetry\Contrib\Ci\Repository;
use OpenTelemetry\Contrib\Ci\Workflows;

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

// Fails on the project's own problems only.
function project(Matrix $matrix, Repository $repository, string $name): Project
{
    $notAProject = "::error::src/$name is not a project CI tests. make projects lists them.";
    $project = $repository->project($name) ?? fail($notAProject);
    if ($problems = $matrix->projectProblems($project, $repository->services)) {
        fail(...$problems);
    }
    $matrix->runs($project) || fail($notAProject);

    return $project;
}

$repository = Repository::load('.');

switch ($argv[1] ?? '') {
    case '--project':
        if (!isset($argv[2])) {
            break;
        }
        output($matrix->cells(project($matrix, $repository, $argv[2])));
        exit(0);
    case '--services':
        if (!isset($argv[2])) {
            break;
        }
        foreach (project($matrix, $repository, $argv[2])->ci->services ?? [] as $service) {
            echo $service, "\n";
        }
        exit(0);
    case '--projects':
        if ($problems = $matrix->problems($repository)) {
            fail(...$problems);
        }
        foreach ($matrix->all($repository) as $project) {
            echo $project['project'], "\n";
        }
        exit(0);
    case '--generate':
        if ($problems = $matrix->problems($repository)) {
            fail(...$problems);
        }
        $projects = $matrix->running($repository);
        $workflows = Workflows::generate($projects);
        // Only problems no generated file can fix, such as two projects
        // sharing a workflow.
        if ($problems = Workflows::problems($projects, $workflows)) {
            fail(...$problems);
        }
        $readme = Badges::updateRoot($repository->readme, $projects) ?? fail(Badges::noProjectList());
        foreach (array_diff_key($repository->workflows, $workflows) as $path => $contents) {
            unlink($path);
        }
        is_dir('.github/workflows') || mkdir('.github/workflows', recursive: true);
        foreach ($workflows as $path => $contents) {
            file_put_contents($path, $contents);
        }
        file_put_contents('README.md', $readme);
        exit(0);
    case '--check':
        if ($problems = $matrix->problems($repository)) {
            fail(...$problems);
        }
        $projects = $matrix->running($repository);
        if ($problems = [
            ...Workflows::problems($projects, $repository->workflows),
            ...Badges::problems($projects, $repository->readmes, $repository->readme),
        ]) {
            fail(...$problems);
        }
        exit(0);
    case '--all':
        if ($problems = $matrix->problems($repository)) {
            fail(...$problems);
        }
        output($matrix->all($repository));
        exit(0);
}

fwrite(STDERR, "Usage: php .github/ci/matrix.php --project <path under src/> | --services <path under src/> | --projects | --generate | --check | --all\n");
exit(2);
