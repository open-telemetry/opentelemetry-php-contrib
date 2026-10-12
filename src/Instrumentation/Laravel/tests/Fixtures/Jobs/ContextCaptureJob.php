<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs;

use Illuminate\Contracts\Events\Dispatcher;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Events\ContextObserved;

class ContextCaptureJob
{
    public function handle(Dispatcher $dispatcher): void
    {
        // Emit the current context.
        $dispatcher->dispatch(new ContextObserved(Context::getCurrent()));
    }
}
