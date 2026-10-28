<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Ci\Tests;

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

    public function test_checks_a_consistent_repository_silently(): void
    {
        $this->addProject('Symfony');

        $this->assertSame([0, '', ''], $this->runMatrix('--check'));
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
    }

    public function test_fails_with_usage_for_unknown_arguments(): void
    {
        $this->addProject('Symfony');

        foreach ([[], ['--bogus'], ['--project']] as $args) {
            [$status, $stdout, $stderr] = $this->runMatrix(...$args);
            $this->assertSame([2, ''], [$status, $stdout], implode(' ', $args));
            $this->assertStringStartsWith('Usage:', $stderr);
        }
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
