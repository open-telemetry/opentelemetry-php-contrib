<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Queue\TracingIsolated;

class IsolatedJob implements ShouldQueue, TracingIsolated
{
    use Queueable;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function handle(): void
    {
        Log::info('Isolated job handled');
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function fire()
    {
        $this->handle();
    }
}
