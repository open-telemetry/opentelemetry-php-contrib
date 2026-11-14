<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Ci\Tests;

use OpenTelemetry\Contrib\Ci\Matrix;
use OpenTelemetry\Contrib\Ci\Project;
use OpenTelemetry\Contrib\Ci\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Matrix's rules, on a small in-memory repository. Its projects are
 * chosen for the rules they exercise, not to mirror the real one, so
 * adding a real project never changes these tests.
 */
final class MatrixTest extends TestCase
{
    private Matrix $matrix;

    protected function setUp(): void
    {
        // Pinned so these tests don't change when matrix.php's versions do.
        $this->matrix = new Matrix(
            supported: ['8.1', '8.2', '8.3', '8.4'],
            experimental: ['8.5'],
            coverage: '8.4',
        );
    }

    // Listing every project

    public function test_lists_every_project_sorted_by_name(): void
    {
        $this->assertSame(
            [
                'Extras' => ['8.3', '8.4', '8.4', '8.5', '8.5'],
                'Instrumentation/Psr1' => ['8.1', '8.2', '8.3', '8.4', '8.5'],
                'Instrumentation/Psr14' => ['8.2', '8.3', '8.4', '8.5'],
                'Instrumentation/Symfony' => ['8.3', '8.4', '8.5'],
                'MetaPackage' => ['8.1', '8.2', '8.3', '8.4', '8.5'],
                'Symfony' => ['8.1', '8.2', '8.3', '8.4', '8.5'],
            ],
            $this->summarise($this->repository()),
        );
    }

    public function test_leaves_out_a_skipped_project(): void
    {
        $repository = $this->repository(extra: [self::project('Image', '{"extra": {"ci": {"skip": true}}}', tested: false)]);

        $this->assertNotContains('Image', array_keys($this->summarise($repository)));
    }

    public function test_lists_a_project_whose_skip_is_false(): void
    {
        $this->assertContains('Instrumentation/Psr1', array_keys($this->summarise($this->withPsr1Ci('{"skip": false}'))));
    }

    public function test_groups_cells_by_project_without_repeating_the_project_in_each_cell(): void
    {
        $projects = $this->matrix->all($this->repository());

        $this->assertSame(['project', 'cells'], array_keys($projects[0]));
        $this->assertSame(
            ['name', 'php-version', 'composer-requires', 'extensions', 'services', 'experimental', 'coverage'],
            array_keys($projects[0]['cells'][0]),
        );
    }

    // Building cells

    public function test_names_each_cell_by_php_version_and_require(): void
    {
        $this->assertSame(
            ['PHP 8.3 a/b:^1', 'PHP 8.4 a/b:^1', 'PHP 8.4 a/b:^2', 'PHP 8.5 a/b:^1', 'PHP 8.5 a/b:^2'],
            $this->field('Extras', 'name'),
        );
    }

    public function test_names_a_cell_without_a_require_by_php_version_alone(): void
    {
        $this->assertSame('PHP 8.1', $this->field('Symfony', 'name')[0]);
    }

    public function test_runs_a_project_without_a_php_constraint_on_every_version(): void
    {
        $this->assertSame(['8.1', '8.2', '8.3', '8.4', '8.5'], $this->field('Symfony', 'php-version'));
    }

    public function test_runs_a_project_from_the_minimum_its_php_constraint_allows(): void
    {
        $this->assertSame(['8.2', '8.3', '8.4', '8.5'], $this->field('Instrumentation/Psr14', 'php-version'));
    }

    public function test_adds_cells_per_composer_requires_from_the_higher_minimum(): void
    {
        $this->assertSame(['8.3', '8.4', '8.4', '8.5', '8.5'], $this->field('Extras', 'php-version'));
        $this->assertSame(['a/b:^1', 'a/b:^1', 'a/b:^2', 'a/b:^1', 'a/b:^2'], $this->field('Extras', 'composer-requires'));
    }

    public function test_gives_a_cell_without_a_require_an_empty_one(): void
    {
        $this->assertSame([''], array_unique($this->field('Symfony', 'composer-requires')));
    }

    public function test_installs_ast_and_opentelemetry_by_default(): void
    {
        $this->assertSame(['ast, opentelemetry'], array_unique($this->field('Instrumentation/Psr1', 'extensions')));
    }

    public function test_adds_additional_extensions_to_the_defaults(): void
    {
        $this->assertSame(['ast, opentelemetry, amqp, grpc'], array_unique($this->field('Extras', 'extensions')));
    }

    public function test_lists_the_services_a_project_needs(): void
    {
        $this->assertSame(['mysql', 'kafka'], $this->field('Extras', 'services')[0]);
    }

    public function test_gives_a_project_without_services_an_empty_list(): void
    {
        $this->assertSame([], $this->field('Symfony', 'services')[0]);
    }

    public function test_marks_experimental_cells_and_coverage_on_the_coverage_version(): void
    {
        $this->assertSame([false, false, false, true], $this->field('Instrumentation/Psr14', 'experimental'));
        $this->assertSame([false, false, true, false], $this->field('Instrumentation/Psr14', 'coverage'));
    }

    public function test_takes_coverage_from_every_composer_requires_on_the_coverage_version(): void
    {
        $this->assertSame([false, true, true, false, false], $this->field('Extras', 'coverage'));
    }

    public function test_gives_a_package_without_phpunit_xml_dist_no_coverage_cell(): void
    {
        $this->assertNotContains(true, $this->field('MetaPackage', 'coverage'));
    }

    // Problems. Each test breaks the base repository in one way.

    public function test_accepts_the_base_repository(): void
    {
        $this->assertSame([], $this->problems($this->repository()));
    }

    public function test_fails_for_a_composer_json_outside_every_project(): void
    {
        $this->assertSame(
            ['::error file=src/Orphan/composer.json::src/Orphan has a composer.json but is not a prefix in .gitsplit.yml, so CI does not test it'],
            $this->problems($this->repository(unlisted: ['src/Orphan'])),
        );
    }

    public function test_fails_for_a_prefix_without_a_composer_json(): void
    {
        $this->assertSame(
            ['::error file=.gitsplit.yml::src/Gone is a prefix in .gitsplit.yml but has no composer.json'],
            $this->problems($this->repository(missing: ['Gone'])),
        );
    }

    public function test_fails_when_extra_ci_is_not_an_object(): void
    {
        $this->assertSame(
            ['::error file=src/Instrumentation/Psr1/composer.json::extra.ci must be an object'],
            $this->problems($this->withPsr1Ci('["amqp"]')),
        );
    }

    public function test_fails_for_an_unknown_extra_ci_key(): void
    {
        $this->assertSame(
            ['::error file=src/Instrumentation/Psr1/composer.json::extra.ci.extensions is not a known key (additional-extensions, composer-requires, services, skip)'],
            $this->problems($this->withPsr1Ci('{"extensions": ["amqp"]}')),
        );
    }

    public function test_fails_when_additional_extensions_is_not_a_list(): void
    {
        $this->assertSame(
            ['::error file=src/Instrumentation/Psr1/composer.json::extra.ci.additional-extensions must be a list of strings'],
            $this->problems($this->withPsr1Ci('{"additional-extensions": "amqp"}')),
        );
    }

    public function test_fails_when_additional_extensions_repeats_a_default(): void
    {
        $this->assertSame(
            ['::error file=src/Instrumentation/Psr1/composer.json::extra.ci.additional-extensions lists opentelemetry, which every cell already has'],
            $this->problems($this->withPsr1Ci('{"additional-extensions": ["amqp", "opentelemetry"]}')),
        );
    }

    public function test_fails_when_composer_requires_is_not_a_map(): void
    {
        $this->assertSame(
            ['::error file=src/Instrumentation/Psr1/composer.json::extra.ci.composer-requires must map each constraint to a minimum PHP version'],
            $this->problems($this->withPsr1Ci('{"composer-requires": ["a/b:^1"]}')),
        );
    }

    public function test_fails_for_a_composer_requires_minimum_that_ci_does_not_run(): void
    {
        $this->assertSame(
            ['::error file=src/Instrumentation/Psr1/composer.json::extra.ci.composer-requires gives a/b:^0 the minimum 7.4, which is not a PHP version CI runs (8.1 8.2 8.3 8.4 8.5)'],
            $this->problems($this->withPsr1Ci('{"composer-requires": {"a/b:^1": "8.1", "a/b:^0": "7.4"}}')),
        );
    }

    public function test_fails_when_services_is_not_a_list(): void
    {
        $this->assertSame(
            ['::error file=src/Instrumentation/Psr1/composer.json::extra.ci.services must be a list of strings'],
            $this->problems($this->withPsr1Ci('{"services": "mysql"}')),
        );
    }

    public function test_fails_for_a_service_not_in_docker_compose_yaml(): void
    {
        $this->assertSame(
            [
                '::error file=src/Instrumentation/Psr1/composer.json::extra.ci.services lists data, which is not a service in docker-compose.yaml',
                '::error file=src/Instrumentation/Psr1/composer.json::extra.ci.services lists redis, which is not a service in docker-compose.yaml',
            ],
            $this->problems($this->withPsr1Ci('{"services": ["mysql", "data", "redis"]}')),
        );
    }

    public function test_fails_when_skip_is_not_a_boolean(): void
    {
        $this->assertSame(
            ['::error file=src/Instrumentation/Psr1/composer.json::extra.ci.skip must be true or false'],
            $this->problems($this->withPsr1Ci('{"skip": "yes"}')),
        );
    }

    public function test_fails_for_a_tested_project_that_skips_ci(): void
    {
        $this->assertSame(
            ['::error file=src/Instrumentation/Psr1/composer.json::src/Instrumentation/Psr1 has a phpunit.xml.dist but extra.ci.skip is true, so CI never runs its tests'],
            $this->problems($this->withPsr1Ci('{"skip": true}')),
        );
    }

    public function test_fails_when_the_coverage_version_is_not_a_supported_version(): void
    {
        $matrix = new Matrix(supported: ['8.1', '8.2', '8.3', '8.4'], experimental: ['8.5'], coverage: '8.5');

        $this->assertSame(
            ['::error file=.github/ci/matrix.php::the coverage version 8.5 is not a supported version (8.1 8.2 8.3 8.4)'],
            $matrix->problems($this->repository()),
        );
    }

    public function test_fails_for_a_tested_project_with_no_coverage_cell(): void
    {
        $this->assertSame(
            ['::error file=src/Future/composer.json::src/Future has a phpunit.xml.dist but no cell on PHP 8.4, the coverage version'],
            $this->problems($this->repository(extra: [self::project('Future', '{"require": {"php": "^8.5"}}')])),
        );
    }

    public function test_fails_for_a_project_with_more_cells_than_a_matrix_allows(): void
    {
        $matrix = new Matrix(supported: ['8.1', '8.2', '8.3', '8.4'], experimental: ['8.5'], coverage: '8.4', jobLimit: 6);
        $repository = $this->repository(extras: '{"composer-requires": {"a/b:^1": "8.1", "a/b:^2": "8.4", "a/b:^3": "8.1"}}');

        $this->assertSame(
            ['::error file=src/Extras/composer.json::src/Extras has 8 cells, more than the 6 jobs GitHub allows in one matrix'],
            $matrix->problems($repository),
        );
    }

    public function test_has_no_limit_on_the_number_of_projects(): void
    {
        $matrix = new Matrix(supported: ['8.1', '8.2', '8.3', '8.4'], experimental: ['8.5'], coverage: '8.4', jobLimit: 6);

        $this->assertSame([], $matrix->problems($this->repository(extra: [self::project('Seventh')])));
    }

    // One project's problems, for building its cells alone

    public function test_accepts_a_project_without_problems(): void
    {
        $this->assertSame([], $this->matrix->projectProblems($this->find($this->repository(), 'Extras'), ['mysql', 'kafka']));
    }

    public function test_reports_only_the_given_projects_problems(): void
    {
        $repository = $this->repository(unlisted: ['src/Orphan'], missing: ['Gone'], psr1: '{"skip": "yes"}');

        $this->assertSame([], $this->matrix->projectProblems($this->find($repository, 'Symfony'), $repository->services));
        $this->assertSame(
            ['::error file=src/Instrumentation/Psr1/composer.json::extra.ci.skip must be true or false'],
            $this->matrix->projectProblems($this->find($repository, 'Instrumentation/Psr1'), $repository->services),
        );
    }

    public function test_reports_a_projects_cell_problems(): void
    {
        $this->assertSame(
            ['::error file=src/Future/composer.json::src/Future has a phpunit.xml.dist but no cell on PHP 8.4, the coverage version'],
            $this->matrix->projectProblems(self::project('Future', '{"require": {"php": "^8.5"}}'), []),
        );
    }

    public function test_reports_the_coverage_version_with_a_projects_problems(): void
    {
        $matrix = new Matrix(supported: ['8.1', '8.2', '8.3', '8.4'], experimental: ['8.5'], coverage: '8.5');

        $this->assertSame(
            ['::error file=.github/ci/matrix.php::the coverage version 8.5 is not a supported version (8.1 8.2 8.3 8.4)'],
            $matrix->projectProblems(self::project('Symfony'), []),
        );
    }

    /**
     * The base repository, or a copy broken in one way.
     *
     * @param list<string> $missing prefixes without a composer.json
     * @param list<string> $unlisted composer.json directories outside every prefix
     * @param list<Project> $extra more projects
     */
    private function repository(
        array $missing = [],
        array $unlisted = [],
        array $extra = [],
        string $psr1 = '{}',
        string $extras = '{"composer-requires": {"a/b:^1": "8.1", "a/b:^2": "8.4"}, "additional-extensions": ["amqp", "grpc"], "services": ["mysql", "kafka"]}',
    ): Repository {
        return new Repository(
            projects: [
                // Their minimums differ.
                self::project('Instrumentation/Psr1', '{"require": {"php": "^8.1"}, "extra": {"ci": ' . $psr1 . '}}'),
                self::project('Instrumentation/Psr14', '{"require": {"php": "^8.2"}, "extra": {"ci": {"services": ["kafka"]}}}'),
                // Same name in two categories. The root one has no PHP constraint.
                self::project('Instrumentation/Symfony', '{"require": {"php": ">=8.3"}}'),
                self::project('Symfony'),
                // Requires with minimums below and above require.php,
                // extensions and services.
                self::project('Extras', '{"require": {"php": "^8.3"}, "extra": {"ci": ' . $extras . '}}'),
                // No phpunit.xml.dist, so no coverage cell and no
                // problem for lacking one.
                self::project('MetaPackage', '{"require": {"php": "^8.1"}}', tested: false),
                ...$extra,
            ],
            missing: $missing,
            unlisted: $unlisted,
            services: ['php', 'mysql', 'kafka'],
        );
    }

    private function withPsr1Ci(string $ci): Repository
    {
        return $this->repository(psr1: $ci);
    }

    private static function project(string $name, string $composerJson = '{}', bool $tested = true): Project
    {
        return Project::fromComposerJson($name, $composerJson, $tested);
    }

    private function find(Repository $repository, string $name): Project
    {
        $project = $repository->project($name);
        $this->assertNotNull($project);

        return $project;
    }

    /** @return list<string> */
    private function problems(Repository $repository): array
    {
        return $this->matrix->problems($repository);
    }

    /**
     * Each listed project's PHP versions, in order.
     *
     * @return array<string, list<string>>
     */
    private function summarise(Repository $repository): array
    {
        $summary = [];
        foreach ($this->matrix->all($repository) as $project) {
            $summary[$project['project']] = array_column($project['cells'], 'php-version');
        }

        return $summary;
    }

    /** @return list<mixed> one field from each of a project's cells */
    private function field(string $project, string $field): array
    {
        return array_column($this->matrix->cells($this->find($this->repository(), $project)), $field);
    }
}
