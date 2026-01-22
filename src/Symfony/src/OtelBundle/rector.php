<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Console',
        __DIR__ . '/DependencyInjection',
        __DIR__ . '/HttpKernel',
        __DIR__ . '/Resources',
    ])
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withRules([
        AddOverrideAttributeToOverriddenMethodsRector::class,
    ]);
