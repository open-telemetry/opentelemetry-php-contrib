<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        '*/_register.php',
    ])
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withSets([
        SetList::PHP_82,
        PHPUnitSetList::PHPUNIT_100,
    ])
    ->withRules([
        AddOverrideAttributeToOverriddenMethodsRector::class,
    ]);
