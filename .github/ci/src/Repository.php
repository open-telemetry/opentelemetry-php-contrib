<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Ci;

use Symfony\Component\Yaml\Yaml;

/**
 * The files in a checkout that decide the matrix.
 */
final class Repository
{
    /**
     * @param list<Project> $projects
     * @param list<string> $missing prefixes under src/ without a composer.json
     * @param list<string> $unlisted directories with a composer.json that
     *     are not inside a prefix, so CI would never test them
     * @param list<string> $services the services in docker-compose.yaml
     * @param array<string, string> $workflows each project-*.yml in
     *     .github/workflows, by path, sorted
     */
    public function __construct(
        public readonly array $projects,
        public readonly array $missing,
        public readonly array $unlisted,
        public readonly array $services,
        public readonly array $workflows = [],
    ) {
    }

    public static function load(string $root): self
    {
        $prefixes = [];
        foreach (Yaml::parseFile("$root/.gitsplit.yml")['splits'] ?? [] as $split) {
            if (str_starts_with($split['prefix'], 'src/')) {
                $prefixes[] = substr($split['prefix'], strlen('src/'));
            }
        }

        $projects = [];
        $missing = [];
        foreach ($prefixes as $name) {
            $dir = "$root/src/$name";
            if (!is_file("$dir/composer.json")) {
                $missing[] = $name;

                continue;
            }
            $projects[] = Project::fromComposerJson($name, (string) file_get_contents("$dir/composer.json"), is_file("$dir/phpunit.xml.dist"));
        }

        $unlisted = array_values(array_filter(
            self::composerDirs($root),
            fn (string $dir) => !array_filter($prefixes, fn (string $name) => $dir === "src/$name" || str_starts_with($dir, "src/$name/")),
        ));

        $services = [];
        if (is_file("$root/docker-compose.yaml")) {
            $services = array_map('strval', array_keys(Yaml::parseFile("$root/docker-compose.yaml")['services'] ?? []));
        }

        $workflows = [];
        foreach (glob("$root/" . Workflows::PATTERN) ?: [] as $file) {
            $workflows[substr($file, strlen("$root/"))] = (string) file_get_contents($file);
        }
        ksort($workflows);

        return new self($projects, $missing, $unlisted, $services, $workflows);
    }

    public function project(string $name): ?Project
    {
        foreach ($this->projects as $project) {
            if ($project->name === $name) {
                return $project;
            }
        }

        return null;
    }

    /**
     * Every directory under src/ with a composer.json. vendor/ is skipped
     * only for speed, since it is always inside a project.
     *
     * @return list<string>
     */
    private static function composerDirs(string $root): array
    {
        if (!is_dir("$root/src")) {
            return [];
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator("$root/src", \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $file) => $file->isDir() ? $file->getFilename() !== 'vendor' : $file->getFilename() === 'composer.json',
        ));
        $dirs = [];
        foreach ($files as $file) {
            $dirs[] = substr($file->getPath(), strlen("$root/"));
        }
        sort($dirs);

        return $dirs;
    }
}
