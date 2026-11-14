<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Ci;

/**
 * A .gitsplit.yml prefix under src/ that has a composer.json.
 */
final class Project
{
    /**
     * @param string $name the path under src/
     * @param bool $tested whether it has a phpunit.xml.dist
     * @param string $minimum the lowest PHP version require.php allows,
     *     or '' when it has no constraint
     * @param mixed $ci extra.ci as decoded, objects as stdClass, so
     *     Matrix can tell {} from []
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $tested,
        public readonly string $minimum,
        public readonly mixed $ci,
    ) {
    }

    public static function fromComposerJson(string $name, string $composerJson, bool $tested): self
    {
        $composer = json_decode($composerJson, flags: JSON_THROW_ON_ERROR);
        preg_match('/\d+\.\d+/', $composer->require->php ?? '', $minimum);

        return new self($name, $tested, $minimum[0] ?? '', $composer->extra->ci ?? new \stdClass());
    }

    public function file(): string
    {
        return "src/$this->name/composer.json";
    }
}
