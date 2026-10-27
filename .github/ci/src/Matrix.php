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
    private const KNOWN_KEYS = ['additional-extensions', 'composer-requires', 'services'];

    // A changed path matching this runs every project. Changes to
    // docker-compose.yaml and docker/<service>/ run the projects using a
    // service instead.
    private const RUN_ALL = '{^(\.github/workflows/php(-project)?\.yml|\.github/ci/(matrix\.php|composer\.json|src/.*))$}';

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
        foreach ($repository->projects as $project) {
            array_push($problems, ...$this->ciProblems($project, $repository->services));
        }
        if (count($repository->projects) > $this->jobLimit) {
            $problems[] = self::error('.gitsplit.yml', sprintf('.gitsplit.yml has %d projects, more than the %d jobs GitHub allows in one matrix', count($repository->projects), $this->jobLimit));
        }
        if (!in_array($this->coverage, $this->supported, true)) {
            $problems[] = self::error('.github/ci/matrix.php', sprintf('the coverage version %s is not a supported version (%s)', $this->coverage, implode(' ', $this->supported)));
        }
        // The checks below need cells, which can't be built from a
        // broken extra.ci.
        if ($problems) {
            return $problems;
        }

        foreach ($repository->projects as $project) {
            $cells = $this->cells($project);
            if ($project->tested && !array_filter($cells, fn (array $cell) => $cell['coverage'])) {
                $problems[] = self::error($project->file(), "src/$project->name has a phpunit.xml.dist but no cell on PHP $this->coverage, the coverage version");
            }
            if (count($cells) > $this->jobLimit) {
                $problems[] = self::error($project->file(), sprintf('src/%s has %d cells, more than the %d jobs GitHub allows in one matrix', $project->name, count($cells), $this->jobLimit));
            }
        }

        return $problems;
    }

    /**
     * The projects to test, each with its cells, sorted by name. Call
     * only when problems() is empty.
     *
     * @param list<string>|null $changed the changed paths, or null for
     *     every project
     * @return list<array{project: string, cells: list<array<string, mixed>>}>
     */
    public function projects(Repository $repository, ?array $changed): array
    {
        $runAll = $changed === null || preg_grep(self::RUN_ALL, $changed);
        $projects = [];
        foreach ($repository->projects as $project) {
            if ($runAll || $this->touches($project, $changed)) {
                $projects[] = ['project' => $project->name, 'cells' => $this->cells($project)];
            }
        }
        usort($projects, fn (array $a, array $b) => strcmp($a['project'], $b['project']));

        return $projects;
    }

    /** @param list<string> $changed */
    private function touches(Project $project, array $changed): bool
    {
        $services = $project->ci->services ?? [];
        foreach ($changed as $path) {
            if (str_starts_with($path, "src/$project->name/")) {
                return true;
            }
            if ($path === 'docker-compose.yaml' && $services) {
                return true;
            }
            foreach ($services as $service) {
                if (str_starts_with($path, "docker/$service/")) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function cells(Project $project): array
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

        return $problems;
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
