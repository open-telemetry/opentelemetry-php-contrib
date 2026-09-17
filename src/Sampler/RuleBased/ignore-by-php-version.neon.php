<?php

declare(strict_types=1);

$ignoreErrors = [];

if (version_compare(PHP_VERSION, '8.2', '<')) {
    $ignoreErrors = [
        '#Call to an undefined method Symfony\\\\Component\\\\Config\\\\Definition\\\\Builder\\\\NodeParentInterface::#',
    ];
}

return [
    'parameters' => [
        'ignoreErrors' => $ignoreErrors,
    ],
];
