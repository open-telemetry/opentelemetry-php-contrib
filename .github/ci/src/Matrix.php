<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Ci;

/**
 * The cells CI runs: one per project, PHP version and composer-requires
 * constraint. See README.md in .github/ci.
 */
final class Matrix
{
    private const DEFAULT_EXTENSIONS = ['ast', 'opentelemetry'];
    private const KNOWN_KEYS = ['additional-extensions', 'composer-requires', 'services', 'skip'];

    /**
     * @param list<string> $supported versions that must pass
     * @param list<string> $experimental versions that run but may fail
     * @param string $coverage the version whose cells upload coverage
     * @param int $jobLimit how many jobs GitHub allows in one matrix
     */
    public function __construct(
        private readonly array $supported,
        private readonly array $experimental,
        private readonly string $coverage,
        private readonly int $jobLimit = 256,
    ) {
    }

    /**
     * Everything wrong with the repository, as GitHub annotations. The
     * matrix is only built when this is empty.
     *
     * @return list<string>
     */
    public function problems(Repository $repository): array
    {
        $problems = [];
        foreach ($repository->unlisted as $dir) {
            $problems[] = self::error("$dir/composer.json", "$dir has a composer.json but is not a prefix in .gitsplit.yml, so CI does not test it");
        }
        foreach ($repository->missing as $name) {
            $problems[] = self::error('.gitsplit.yml', "src/$name is a prefix in .gitsplit.yml but has no composer.json");
        }
        $problems = [...$problems, ...$this->versionProblems()];
        foreach ($repository->projects as $project) {
            array_push($problems, ...$this->ownProblems($project, $repository->services));
        }

        return $problems;
    }

    /**
     * What's wrong with one project, ignoring the rest of the
     * repository. Its cells are only built when this is empty.
     *
     * @param list<string> $services the services in docker-compose.yaml
     * @return list<string>
     */
    public function projectProblems(Project $project, array $services): array
    {
        return [...$this->versionProblems(), ...$this->ownProblems($project, $services)];
    }

    /**
     * Every project CI tests, each with its cells, sorted by name. Call
     * only when problems() is empty.
     *
     * @return list<array{project: string, cells: list<array<string, mixed>>}>
     */
    public function all(Repository $repository): array
    {
        $projects = [];
        foreach ($this->running($repository) as $project) {
            $projects[] = ['project' => $project->name, 'cells' => $this->cells($project)];
        }
        usort($projects, fn (array $a, array $b) => strcmp($a['project'], $b['project']));

        return $projects;
    }

    /**
     * The projects CI runs. Call only when problems() is empty.
     *
     * @return list<Project>
     */
    public function running(Repository $repository): array
    {
        return array_values(array_filter($repository->projects, $this->runs(...)));
    }

    /**
     * Whether CI runs the project. Call only when projectProblems() is
     * empty.
     */
    public function runs(Project $project): bool
    {
        return !self::skipped($project);
    }

    /**
     * One project's cells. Call only when projectProblems() is empty.
     *
     * @return list<array<string, mixed>>
     */
    public function cells(Project $project): array
    {
        $requires = (array) ($project->ci->{'composer-requires'} ?? ['' => '']);
        $extensions = implode(', ', [...self::DEFAULT_EXTENSIONS, ...$project->ci->{'additional-extensions'} ?? []]);
        $cells = [];
        foreach ($this->versions() as $php) {
            if (!self::atLeast($php, $project->minimum)) {
                continue;
            }
            foreach ($requires as $require => $minimum) {
                if (!self::atLeast($php, $minimum)) {
                    continue;
                }
                $cells[] = [
                    'name' => rtrim("PHP $php $require"),
                    'php-version' => $php,
                    'composer-requires' => (string) $require,
                    'extensions' => $extensions,
                    'services' => $project->ci->services ?? [],
                    'experimental' => in_array($php, $this->experimental, true),
                    'coverage' => $project->tested && $php === $this->coverage,
                ];
            }
        }

        return $cells;
    }

    /** @return list<string> */
    private function versionProblems(): array
    {
        if (in_array($this->coverage, $this->supported, true)) {
            return [];
        }

        return [self::error('.github/ci/matrix.php', sprintf('the coverage version %s is not a supported version (%s)', $this->coverage, implode(' ', $this->supported)))];
    }

    /**
     * @param list<string> $services
     * @return list<string>
     */
    private function ownProblems(Project $project, array $services): array
    {
        $problems = $this->ciProblems($project, $services);
        // The checks below need cells, which can't be built from a
        // broken extra.ci.
        if ($problems) {
            return $problems;
        }

        $cells = $this->cells($project);
        if ($project->tested && !array_filter($cells, fn (array $cell) => $cell['coverage'])) {
            $problems[] = self::error($project->file(), "src/$project->name has a phpunit.xml.dist but no cell on PHP $this->coverage, the coverage version");
        }
        if (count($cells) > $this->jobLimit) {
            $problems[] = self::error($project->file(), sprintf('src/%s has %d cells, more than the %d jobs GitHub allows in one matrix', $project->name, count($cells), $this->jobLimit));
        }

        return $problems;
    }

    /**
     * @param list<string> $services
     * @return list<string>
     */
    private function ciProblems(Project $project, array $services): array
    {
        $file = $project->file();
        $ci = $project->ci;
        if (!$ci instanceof \stdClass) {
            return [self::error($file, 'extra.ci must be an object')];
        }

        $problems = [];
        $keys = array_keys((array) $ci);
        sort($keys);
        foreach (array_diff($keys, self::KNOWN_KEYS) as $key) {
            $problems[] = self::error($file, sprintf('extra.ci.%s is not a known key (%s)', $key, implode(', ', self::KNOWN_KEYS)));
        }

        if (property_exists($ci, 'additional-extensions')) {
            $extensions = $ci->{'additional-extensions'};
            if (!self::isListOfStrings($extensions)) {
                $problems[] = self::error($file, 'extra.ci.additional-extensions must be a list of strings');
            } else {
                foreach (array_intersect($extensions, self::DEFAULT_EXTENSIONS) as $extension) {
                    $problems[] = self::error($file, "extra.ci.additional-extensions lists $extension, which every cell already has");
                }
            }
        }

        if (property_exists($ci, 'composer-requires')) {
            $requires = $ci->{'composer-requires'};
            if (!$requires instanceof \stdClass || !self::isListOfStrings(array_values((array) $requires))) {
                $problems[] = self::error($file, 'extra.ci.composer-requires must map each constraint to a minimum PHP version');
            } else {
                foreach ((array) $requires as $require => $minimum) {
                    if (!in_array($minimum, $this->versions(), true)) {
                        $problems[] = self::error($file, sprintf('extra.ci.composer-requires gives %s the minimum %s, which is not a PHP version CI runs (%s)', $require, $minimum, implode(' ', $this->versions())));
                    }
                }
            }
        }

        if (property_exists($ci, 'services')) {
            if (!self::isListOfStrings($ci->services)) {
                $problems[] = self::error($file, 'extra.ci.services must be a list of strings');
            } else {
                foreach (array_diff($ci->services, $services) as $service) {
                    $problems[] = self::error($file, "extra.ci.services lists $service, which is not a service in docker-compose.yaml");
                }
            }
        }

        if (property_exists($ci, 'skip')) {
            if (!is_bool($ci->skip)) {
                $problems[] = self::error($file, 'extra.ci.skip must be true or false');
            } elseif ($ci->skip && $project->tested) {
                $problems[] = self::error($file, "src/$project->name has a phpunit.xml.dist but extra.ci.skip is true, so CI never runs its tests");
            }
        }

        return $problems;
    }

    // Call only once ciProblems() has accepted the project's extra.ci.
    private static function skipped(Project $project): bool
    {
        return $project->ci->skip ?? false;
    }

    /** @return list<string> */
    private function versions(): array
    {
        return [...$this->supported, ...$this->experimental];
    }

    private static function atLeast(string $version, string $minimum): bool
    {
        return $minimum === '' || version_compare($version, $minimum, '>=');
    }

    private static function isListOfStrings(mixed $value): bool
    {
        return is_array($value) && $value === array_filter($value, 'is_string');
    }

    private static function error(string $file, string $message): string
    {
        return "::error file=$file::$message";
    }
}
