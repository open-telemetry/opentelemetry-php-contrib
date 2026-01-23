<?php

declare(strict_types=1);

$ignoreErrors = [];

if (version_compare(PHP_VERSION, '8.4', '<')) {
    $ignoreErrors = [
        '#Call to an undefined static method PDO::connect\(\)#',
    ];
} elseif (version_compare(PHP_VERSION, '8.4', '>=')) {
    $ignoreErrors = [
        '#Call to an undefined method PDO::createFunction\(\)#',
    ];
}

return [
    'parameters' => [
        'ignoreErrors' => $ignoreErrors,
    ],
];
