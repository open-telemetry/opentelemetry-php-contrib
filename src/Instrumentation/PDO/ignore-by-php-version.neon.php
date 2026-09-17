<?php

declare(strict_types=1);

$ignoreErrors = [];

if (version_compare(PHP_VERSION, '8.4', '<')) {
    $ignoreErrors = [
        '#Call to an undefined static method PDO::connect\(\)#',
        '#Class Pdo\\\\Sqlite referenced with incorrect case: PDO\\\\Sqlite#',
    ];
} elseif (version_compare(PHP_VERSION, '8.4', '>=')) {
    $ignoreErrors = [
        '#Call to function method_exists\(\) with .PDO. and .connect. will always evaluate to true#',
        '#PDOInstrumentationTest::createDBWithNewSubclass\(\) should return PDO but returns#',
    ];
}

return [
    'parameters' => [
        'ignoreErrors' => $ignoreErrors,
    ],
];
