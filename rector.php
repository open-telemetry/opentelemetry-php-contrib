<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;

/**
 * Rector configuration for adding #[\Override] attributes.
 *
 * Due to the monorepo structure, run rector from each package directory:
 *   cd src/Aws && composer install && ../../vendor/bin/rector
 *
 * Or copy this config to the package and run locally.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSkip([
        '*/_register.php',
        '*/vendor/*',
    ])
    ->withRules([
        AddOverrideAttributeToOverriddenMethodsRector::class,
    ]);
