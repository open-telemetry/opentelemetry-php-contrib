#!/usr/bin/env php
<?php

/**
 * Script to automatically generate dependabot.yml configuration based on directory structure.
 *
 * This script scans the project for composer.json files and generates a dependabot.yml
 * configuration file with appropriate settings for each package.
 *
 * Usage: php scripts/generate-dependabot-config.php
 */

// Configuration
$rootDir = dirname(__DIR__);
$outputFile = $rootDir . '/.github/dependabot.yml';
$scanDirs = [
    $rootDir,
    $rootDir . '/src',
    $rootDir . '/examples',
];

// Initialize YAML content
$yaml = [
    '# Dependabot configuration file',
    '# See: https://docs.github.com/github/administering-a-repository/',
    '# configuration-options-for-dependency-updates',
    '',
    'version: 2',
    'updates:',
];

// Add root project configuration
$yaml[] = '  # Maintain dependencies for the root project';
$yaml[] = '  - package-ecosystem: "composer"';
$yaml[] = '    directory: "/"';
$yaml[] = '    schedule:';
$yaml[] = '      interval: "weekly"';
$yaml[] = '    open-pull-requests-limit: 10';
$yaml[] = '    labels:';
$yaml[] = '      - "dependencies"';
$yaml[] = '    versioning-strategy: "auto"';
$yaml[] = '    allow:';
$yaml[] = '      - dependency-type: "direct"';
$yaml[] = '      - dependency-type: "indirect"';
$yaml[] = '    groups:';
$yaml[] = '      dev-dependencies:';
$yaml[] = '        patterns:';
$yaml[] = '          - "friendsofphp/php-cs-fixer"';
$yaml[] = '          - "phan/phan"';
$yaml[] = '          - "phpstan/phpstan*"';
$yaml[] = '          - "phpunit/phpunit"';
$yaml[] = '          - "vimeo/psalm"';
$yaml[] = '          - "psalm/plugin-phpunit"';
$yaml[] = '        exclude-patterns:';
$yaml[] = '          - "open-telemetry/*"';
$yaml[] = '      open-telemetry:';
$yaml[] = '        patterns:';
$yaml[] = '          - "open-telemetry/*"';
$yaml[] = '      symfony:';
$yaml[] = '        patterns:';
$yaml[] = '          - "symfony/*"';
$yaml[] = '    ignore:';
$yaml[] = '      - dependency-name: "*"';
$yaml[] = '        update-types: ["version-update:semver-major"]';
$yaml[] = '    commit-message:';
$yaml[] = '      prefix: "chore"';
$yaml[] = '      prefix-development: "chore"';
$yaml[] = '      include: "scope"';
$yaml[] = '';

// Add GitHub Actions configuration
$yaml[] = '  # Maintain dependencies for GitHub Actions';
$yaml[] = '  - package-ecosystem: "github-actions"';
$yaml[] = '    directory: "/"';
$yaml[] = '    schedule:';
$yaml[] = '      interval: "weekly"';
$yaml[] = '    open-pull-requests-limit: 10';
$yaml[] = '    labels:';
$yaml[] = '      - "dependencies"';
$yaml[] = '    commit-message:';
$yaml[] = '      prefix: "chore"';
$yaml[] = '      prefix-development: "chore"';
$yaml[] = '      include: "scope"';
$yaml[] = '';

// Find all composer.json files
$composerFiles = [];
foreach ($scanDirs as $scanDir) {
    findComposerFiles($scanDir, $composerFiles, $rootDir);
}

// Filter out the root composer.json as it's already configured
$packageDirectories = [];
foreach ($composerFiles as $composerFile) {
    if ($composerFile !== '/') {
        $packageDirectories[] = $composerFile;
    }
}

// Add a single configuration for all package directories
if (!empty($packageDirectories)) {
    $yaml[] = '  # Maintain dependencies for all packages';
    $yaml[] = '  - package-ecosystem: "composer"';
    $yaml[] = '    directories:';

    // Sort directories for consistent output
    sort($packageDirectories);
    foreach ($packageDirectories as $directory) {
        $yaml[] = '      - "' . $directory . '"';
    }

    $yaml[] = '    schedule:';
    $yaml[] = '      interval: "weekly"';
    $yaml[] = '    labels:';
    $yaml[] = '      - "dependencies"';
    $yaml[] = '    groups:';
    $yaml[] = '      dev-dependencies:';
    $yaml[] = '        patterns:';
    $yaml[] = '          - "friendsofphp/php-cs-fixer"';
    $yaml[] = '          - "phan/phan"';
    $yaml[] = '          - "phpstan/phpstan*"';
    $yaml[] = '          - "phpunit/phpunit"';
    $yaml[] = '          - "vimeo/psalm"';
    $yaml[] = '          - "psalm/plugin-phpunit"';
    $yaml[] = '      laravel:';
    $yaml[] = '        patterns:';
    $yaml[] = '          - "laravel/*"';
    $yaml[] = '          - "illuminate/*"';
    $yaml[] = '      open-telemetry:';
    $yaml[] = '        patterns:';
    $yaml[] = '          - "open-telemetry/*"';
    $yaml[] = '      symfony:';
    $yaml[] = '        patterns:';
    $yaml[] = '          - "symfony/*"';
    $yaml[] = '    ignore:';
    $yaml[] = '      - dependency-name: "*"';
    $yaml[] = '        update-types: ["version-update:semver-major"]';
    $yaml[] = '    commit-message:';
    $yaml[] = '      prefix: "chore"';
    $yaml[] = '      prefix-development: "chore"';
    $yaml[] = '      include: "scope"';
    $yaml[] = '';
}

// Write the YAML file
file_put_contents($outputFile, implode("\n", $yaml));

echo "Dependabot configuration generated at: $outputFile\n";
echo "Found " . count($composerFiles) . " composer.json files.\n";

/**
 * Recursively find composer.json files in the given directory.
 *
 * @param string $dir The directory to scan
 * @param array $results Array to store results
 * @param string $rootDir The root directory of the project
 */
function findComposerFiles($dir, &$results, $rootDir) {
    $files = scandir($dir);

    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || $file === 'vendor') {
            continue;
        }

        $path = $dir . '/' . $file;

        if (is_dir($path)) {
            // Skip vendor directories
            if (strpos($path, '/vendor/') !== false) {
                continue;
            }

            findComposerFiles($path, $results, $rootDir);
        } elseif ($file === 'composer.json') {
            // Get relative path from root
            $relativePath = str_replace($rootDir, '', dirname($path));
            $relativePath = $relativePath ?: '/';

            // Add to results if not already present
            if (!in_array($relativePath, $results)) {
                $results[] = $relativePath;
            }
        }
    }
}
