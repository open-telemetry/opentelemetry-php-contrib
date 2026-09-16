<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Phalcon\Integration\Fixtures;

use Phalcon\Cli\Task;

final class MainTask extends Task
{
    public function mainAction(): string
    {
        return 'ok';
    }
}
