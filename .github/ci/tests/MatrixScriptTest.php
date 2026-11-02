<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Ci\Tests;

use OpenTelemetry\Contrib\Ci\Badges;
use PHPUnit\Framework\TestCase;

/**
 * matrix.php end to end, run in a fake repository the way CI runs it
 * in the real one.
 */
final class MatrixScriptTest extends TestCase
{
    use Fixture;

    public function test_prints_one_projects_cells(): void
    {
        $this->addProject('Symfony', '{"require": {"php": "^8.4"}}');
        $this->addProject('Aws');

        [$status, $stdout, $stderr] = $this->runMatrix('--project', 'Symfony');

        $this->assertSame([0, ''], [$status, $stderr]);
        $this->assertSame(['PHP 8.4', 'PHP 8.5'], array_column(json_decode($stdout, true), 'name'));
        $this->assertStringContainsString('"composer-requires":""', $stdout);
        $this->assertStringContainsString('"services":[]', $stdout);
    }

    public function test_prints_one_projects_cells_despite_problems_elsewhere(): void
    {
        $this->addProject('Symfony');
        $this->addSplit('src/Gone');

        [$status, $stdout] = $this->runMatrix('--project', 'Symfony');

        $this->assertSame(0, $status);
        $this->assertCount(5, json_decode($stdout, true));
    }

    public function test_fails_for_one_projects_own_problems(): void
    {
        $this->addProject('Symfony', '{"extra": {"ci": {"service": ["mysql"]}}}');

        $this->assertSame([1, '', <<<'TEXT'
            ::error file=src/Symfony/composer.json::extra.ci.service is not a known key (additional-extensions, composer-requires, services, skip)

            TEXT], $this->runMatrix('--project', 'Symfony'));
    }

    public function test_fails_for_a_project_ci_does_not_test(): void
    {
        $this->addProject('Image', '{"extra": {"ci": {"skip": true}}}', tested: false);

        foreach (['Image', 'Gone'] as $name) {
            $this->assertSame(
                [1, '', "::error::src/$name is not a project CI tests. make projects lists them.\n"],
                $this->runMatrix('--project', $name),
                $name,
            );
        }
    }

    public function test_prints_every_project_with_all(): void
    {
        $this->addProject('Symfony');
        $this->addProject('Aws');

        [$status, $stdout] = $this->runMatrix('--all');

        $this->assertSame(0, $status);
        $this->assertSame(['Aws', 'Symfony'], array_column(json_decode($stdout, true), 'project'));
    }

    public function test_lists_the_projects_ci_runs(): void
    {
        $this->addProject('Symfony');
        $this->addProject('Instrumentation/PDO');
        $this->addProject('Image', '{"extra": {"ci": {"skip": true}}}', tested: false);

        $this->assertSame([0, "Instrumentation/PDO\nSymfony\n", ''], $this->runMatrix('--projects'));
    }

    public function test_lists_one_projects_services(): void
    {
        $this->write('docker-compose.yaml', "services:\n  mysql: {}\n  kafka: {}\n");
        $this->addProject('PDO', '{"extra": {"ci": {"services": ["mysql", "kafka"]}}}');
        $this->addProject('Aws');
        $this->addSplit('src/Gone');

        $this->assertSame([0, "mysql\nkafka\n", ''], $this->runMatrix('--services', 'PDO'));
        $this->assertSame([0, '', ''], $this->runMatrix('--services', 'Aws'));
    }

    public function test_fails_to_list_services_for_one_projects_own_problems(): void
    {
        $this->addProject('PDO', '{"extra": {"ci": {"services": ["mysql"]}}}');

        $this->assertSame([1, '', <<<'TEXT'
            ::error file=src/PDO/composer.json::extra.ci.services lists mysql, which is not a service in docker-compose.yaml

            TEXT], $this->runMatrix('--services', 'PDO'));
        $this->assertSame(
            [1, '', "::error::src/Gone is not a project CI tests. make projects lists them.\n"],
            $this->runMatrix('--services', 'Gone'),
        );
    }

    public function test_checks_a_consistent_repository_silently(): void
    {
        $this->addProject('Symfony');
        $this->runMatrix('--generate');

        $this->assertSame([0, '', ''], $this->runMatrix('--check'));
    }

    public function test_generates_a_workflow_for_each_project_ci_runs(): void
    {
        $this->addProject('Symfony');
        $this->addProject('Image', '{"extra": {"ci": {"skip": true}}}', tested: false);

        $this->assertSame([0, '', ''], $this->runMatrix('--generate'));
        $this->assertSame(['project-symfony.yml'], $this->workflowFiles());
        $this->assertStringContainsString("project: Symfony\n", (string) file_get_contents("$this->root/.github/workflows/project-symfony.yml"));
    }

    public function test_generate_writes_the_root_readmes_project_list(): void
    {
        $this->addProject('Symfony');
        $this->write('README.md', "# Contrib\n\n" . Badges::START . "\n" . Badges::END . "\n");

        $this->assertSame([0, '', ''], $this->runMatrix('--generate'));
        $this->assertStringContainsString(
            "|---|---|\n| [Symfony](src/Symfony) | [![CI]",
            (string) file_get_contents("$this->root/README.md"),
        );
    }

    public function test_generate_writes_nothing_when_the_root_readme_has_no_project_list(): void
    {
        $this->addProject('Symfony');
        $this->write('README.md', "# Contrib\n");

        [$status, , $stderr] = $this->runMatrix('--generate');

        $this->assertSame(1, $status);
        $this->assertStringStartsWith('::error file=README.md::README.md has no generated project list.', $stderr);
        $this->assertSame([], $this->workflowFiles());
        $this->assertSame("# Contrib\n", file_get_contents("$this->root/README.md"));
    }

    public function test_check_fails_on_a_project_readme_without_its_badge(): void
    {
        $this->addProject('Symfony');
        $this->runMatrix('--generate');
        $this->write('src/Symfony/README.md', "# Symfony\n");

        [$status, , $stderr] = $this->runMatrix('--check');

        $this->assertSame(1, $status);
        $this->assertStringStartsWith('::error file=src/Symfony/README.md::src/Symfony/README.md has no CI badge.', $stderr);
    }

    public function test_check_fails_on_a_stale_root_project_list(): void
    {
        $this->addProject('Symfony');
        $this->runMatrix('--generate');
        $this->write('README.md', Badges::START . "\n" . Badges::END . "\n");

        $this->assertSame(
            [1, '', "::error file=README.md::README.md's project list differs from what matrix.php generates. Run make ci-generate.\n"],
            $this->runMatrix('--check'),
        );
    }

    public function test_generate_removes_workflows_for_projects_that_are_gone_and_keeps_other_workflows(): void
    {
        $this->addProject('Symfony');
        $this->write('.github/workflows/project-gone.yml', '');
        $this->write('.github/workflows/ci.yml', '');

        $this->runMatrix('--generate');

        $this->assertSame(['ci.yml', 'project-symfony.yml'], $this->workflowFiles());
    }

    public function test_generate_writes_nothing_when_the_repository_has_problems(): void
    {
        $this->addProject('Symfony', '{"extra": {"ci": {"service": ["mysql"]}}}');

        [$status] = $this->runMatrix('--generate');

        $this->assertSame(1, $status);
        $this->assertSame([], $this->workflowFiles());
    }

    public function test_check_fails_on_a_stale_workflow(): void
    {
        $this->addProject('Symfony');
        $this->runMatrix('--generate');
        $this->addProject('Aws');

        $this->assertSame(
            [1, '', <<<'TEXT'
                ::error file=.github/workflows/project-aws.yml::src/Aws has no workflow. Run make ci-generate.
                ::error file=README.md::README.md's project list differs from what matrix.php generates. Run make ci-generate.

                TEXT],
            $this->runMatrix('--check'),
        );
    }

    public function test_fails_printing_each_problem_as_an_annotation(): void
    {
        $this->addProject('Symfony');
        $this->addSplit('src/Gone');
        $this->write('src/Orphan/composer.json', '{}');

        $problems = <<<'TEXT'
            ::error file=src/Orphan/composer.json::src/Orphan has a composer.json but is not a prefix in .gitsplit.yml, so CI does not test it
            ::error file=.gitsplit.yml::src/Gone is a prefix in .gitsplit.yml but has no composer.json

            TEXT;
        $this->assertSame([1, '', $problems], $this->runMatrix('--check'));
        $this->assertSame([1, '', $problems], $this->runMatrix('--all'));
        $this->assertSame([1, '', $problems], $this->runMatrix('--projects'));
    }

    public function test_fails_with_usage_for_unknown_arguments(): void
    {
        $this->addProject('Symfony');

        foreach ([[], ['--bogus'], ['--project'], ['--services']] as $args) {
            [$status, $stdout, $stderr] = $this->runMatrix(...$args);
            $this->assertSame([2, ''], [$status, $stdout], implode(' ', $args));
            $this->assertStringStartsWith('Usage:', $stderr);
        }
    }

    /** @return list<string> */
    private function workflowFiles(): array
    {
        $files = glob("$this->root/.github/workflows/*") ?: [];

        return array_map('basename', $files);
    }

    /** @return array{int, string, string} exit status, stdout and stderr */
    private function runMatrix(string ...$args): array
    {
        $command = [PHP_BINARY, dirname(__DIR__) . '/matrix.php', ...array_values($args)];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->root);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
