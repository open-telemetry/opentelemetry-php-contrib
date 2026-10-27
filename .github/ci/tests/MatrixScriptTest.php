<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Ci\Tests;

use PHPUnit\Framework\TestCase;

/**
 * matrix.php end to end, run in a fake repository the way php.yml
 * runs it in the real one.
 */
final class MatrixScriptTest extends TestCase
{
    use Fixture;

    public function test_prints_the_projects_for_the_changed_files_on_stdin(): void
    {
        $this->addProject('Symfony', '{"require": {"php": "^8.4"}}');
        $this->addProject('Aws');

        [$status, $stdout] = $this->runMatrix("README.md\nsrc/Symfony/src/A.php\n");

        $this->assertSame(0, $status);
        $this->assertSame(['Symfony'], array_column(json_decode($stdout, true), 'project'));
        $this->assertStringContainsString('"composer-requires":""', $stdout);
        $this->assertStringContainsString('"services":[]', $stdout);
    }

    public function test_prints_every_project_with_all(): void
    {
        $this->addProject('Symfony');
        $this->addProject('Aws');

        [$status, $stdout] = $this->runMatrix('', '--all');

        $this->assertSame(0, $status);
        $this->assertSame(['Aws', 'Symfony'], array_column(json_decode($stdout, true), 'project'));
    }

    public function test_prints_an_empty_list_when_nothing_runs(): void
    {
        $this->addProject('Symfony');

        $this->assertSame([0, "[]\n", ''], $this->runMatrix("README.md\n"));
    }

    public function test_fails_printing_each_problem_as_an_annotation(): void
    {
        $this->addProject('Symfony');
        $this->addSplit('src/Gone');
        $this->write('src/Orphan/composer.json', '{}');

        $this->assertSame([1, '', <<<'TEXT'
            ::error file=src/Orphan/composer.json::src/Orphan has a composer.json but is not a prefix in .gitsplit.yml, so CI does not test it
            ::error file=.gitsplit.yml::src/Gone is a prefix in .gitsplit.yml but has no composer.json

            TEXT], $this->runMatrix("README.md\n"));
    }

    /** @return array{int, string, string} exit status, stdout and stderr */
    private function runMatrix(string $stdin, string ...$args): array
    {
        $command = [PHP_BINARY, dirname(__DIR__) . '/matrix.php', ...array_values($args)];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->root);
        $this->assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
