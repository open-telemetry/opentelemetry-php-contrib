<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Ci\Tests;

/**
 * A fake repository in a temp directory, removed after each test.
 * A project is a .gitsplit.yml prefix with a composer.json, and
 * phpunit.xml.dist is what makes it tested.
 */
trait Fixture
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ci-fixture-' . bin2hex(random_bytes(4));
        $this->write('.gitsplit.yml', "splits:\n");
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function addProject(string $name, string $composerJson = '{}', bool $tested = true): void
    {
        $this->write("src/$name/composer.json", $composerJson);
        if ($tested) {
            $this->write("src/$name/phpunit.xml.dist", '');
        }
        $this->addSplit("src/$name");
    }

    private function addSplit(string $prefix): void
    {
        file_put_contents(
            "$this->root/.gitsplit.yml",
            "  - prefix: \"$prefix\"\n    target: \"https://example.com/$prefix.git\"\n",
            FILE_APPEND,
        );
    }

    private function write(string $path, string $contents): void
    {
        $file = "$this->root/$path";
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), recursive: true);
        }
        file_put_contents($file, $contents);
    }
}
