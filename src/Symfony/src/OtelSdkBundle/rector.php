<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/OtelSdkBundle.php',
        __DIR__ . '/DataCollector',
        __DIR__ . '/Debug',
        __DIR__ . '/DependencyInjection',
        __DIR__ . '/Factory',
        __DIR__ . '/Resources',
        __DIR__ . '/Trace',
        __DIR__ . '/Util',
    ])
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withSets([
        SetList::PHP_82,
    ])
    ->withRules([
        AddOverrideAttributeToOverriddenMethodsRector::class,
    ]);
