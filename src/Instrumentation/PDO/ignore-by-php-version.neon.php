<?php

declare(strict_types=1);

$ignoreErrors = [];

// PHPStan ships PHP 8.4 stubs regardless of the PHP version it runs on, so
// Pdo\Sqlite and its methods always resolve. Only PDO::connect() is reported,
// and only when the analysing PHP version predates it.
if (version_compare(PHP_VERSION, '8.4', '<')) {
    $ignoreErrors = [
        '#Call to an undefined static method PDO::connect\(\)#',
    ];
}

return [
    'parameters' => [
        'ignoreErrors' => $ignoreErrors,
    ],
];
