<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use OpenTelemetry\API\Trace\Span;

class DummyJob implements ShouldQueue
{
    use Queueable;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private string $name,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function handle(): void
    {
        $currentSpan = Span::getCurrent();
        $currentSpan->setAttribute('task.name', $this->name);
    }
}
