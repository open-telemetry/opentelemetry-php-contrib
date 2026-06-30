<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Console;

use Illuminate\Console\Command;

/** @psalm-suppress UnusedClass */
class FailingCommand extends Command
{
    /** @psalm-suppress PossiblyUnusedMethod */
    protected $signature = 'test:failing-command';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function handle(): int
    {
        return self::FAILURE;
    }
}
