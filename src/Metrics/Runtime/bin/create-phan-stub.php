<?php

declare(strict_types=1);

// Creates a no-op vendor/bin/phan stub when phan is not installable (e.g. PHP 8.5+ dependency conflict).
$stub = implode("\n", [
    '#!/usr/bin/env php',
    '<?php fwrite(STDERR, "phan not available on this PHP version (dependency conflict)" . PHP_EOL); exit(0);',
    '',
]);

if (!is_file('vendor/bin/phan')) {
    file_put_contents('vendor/bin/phan', $stub);
    chmod('vendor/bin/phan', 0755);
}
