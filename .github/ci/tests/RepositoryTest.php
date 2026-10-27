<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Ci\Tests;

use OpenTelemetry\Contrib\Ci\Project;
use OpenTelemetry\Contrib\Ci\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Reading a repository from disk, in a fake one built in a temp
 * directory with only the files Repository reads.
 */
final class RepositoryTest extends TestCase
{
    use Fixture;

    public function test_reads_projects_from_gitsplit_prefixes(): void
    {
        $this->addProject('Instrumentation/Psr1', '{"require": {"php": "^8.1"}}');
        $this->addProject('Symfony', tested: false);

        $projects = Repository::load($this->root)->projects;

        $this->assertSame(['Instrumentation/Psr1', 'Symfony'], array_map(fn (Project $p) => $p->name, $projects));
        $this->assertSame([true, false], array_map(fn (Project $p) => $p->tested, $projects));
        $this->assertSame(['8.1', ''], array_map(fn (Project $p) => $p->minimum, $projects));
    }

    public function test_ignores_prefixes_outside_src(): void
    {
        $this->addProject('Symfony');
        $this->addSplit('docs');

        $repository = Repository::load($this->root);

        $this->assertCount(1, $repository->projects);
        $this->assertSame([], $repository->missing);
    }

    public function test_lists_prefixes_without_a_composer_json(): void
    {
        $this->addProject('Symfony');
        $this->addSplit('src/Gone');

        $this->assertSame(['Gone'], Repository::load($this->root)->missing);
    }

    public function test_lists_composer_json_directories_outside_every_project(): void
    {
        // Psr1 is a prefix of Psr14's name, but Psr14 is not inside it.
        $this->addProject('Instrumentation/Psr1');
        $this->addProject('Symfony');
        $this->write('src/Symfony/src/OtelBundle/composer.json', '{}');
        $this->write('src/Instrumentation/Psr14/composer.json', '{}');
        $this->write('src/Orphan/composer.json', '{}');

        $this->assertSame(['src/Instrumentation/Psr14', 'src/Orphan'], Repository::load($this->root)->unlisted);
    }

    public function test_reads_services_from_docker_compose_yaml(): void
    {
        $this->write('docker-compose.yaml', <<<'YAML'
            # Not a service.
            services:
                php:
                    image: php
                mysql:
                    image: mysql
                    environment:
                        MYSQL_DATABASE: otel_db
            volumes:
                data:
            YAML);

        $this->assertSame(['php', 'mysql'], Repository::load($this->root)->services);
    }

    public function test_has_no_services_without_docker_compose_yaml(): void
    {
        $this->assertSame([], Repository::load($this->root)->services);
    }
}
