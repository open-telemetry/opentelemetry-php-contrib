<?php

declare(strict_types=1);

$ignoreErrors = [];

if (version_compare(PHP_VERSION, '8.4', '<')) {
    $ignoreErrors = [
        '#Call to an undefined static method PDO::connect\(\)#',
        '#PHPDoc tag @var for variable \$db contains unknown class PDO\\\\Sqlite#',
        '#Call to method createFunction\(\) on an unknown class PDO\\\\Sqlite#',
        '#Call to method exec\(\) on an unknown class PDO\\\\Sqlite#',
        '#Call to method query\(\) on an unknown class PDO\\\\Sqlite#',
    ];
} elseif (version_compare(PHP_VERSION, '8.4', '>=')) {
    $ignoreErrors = [
        '#Call to an undefined method Pdo\\\\Sqlite::createFunction\(\)#',
    ];
}

return [
    'parameters' => [
        'ignoreErrors' => $ignoreErrors,
    ],
];
