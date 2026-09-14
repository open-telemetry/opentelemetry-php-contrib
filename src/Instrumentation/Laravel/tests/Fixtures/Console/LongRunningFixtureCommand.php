<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Console;

use Illuminate\Console\Command;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Console\LongRunningCommand;

/** @psalm-suppress UnusedClass */
#[LongRunningCommand]
class LongRunningFixtureCommand extends Command
{
    /** @psalm-suppress PossiblyUnusedMethod */
    protected $signature = 'test:long-running-fixture';

    /** @psalm-suppress PossiblyUnusedMethod */
    public function handle(): int
    {
        return self::SUCCESS;
    }
}
