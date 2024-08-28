<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\CakePHP\Integration\App\src\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;

class DummyCommand extends Command
{
    public function execute(Arguments $args, ConsoleIo $io)
    {
        return self::CODE_SUCCESS;
    }
}
