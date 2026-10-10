<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Queue\TracingLinked;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Events\ContextObserved;
use Psr\Log\LoggerInterface;

class LinkedJob implements ShouldQueue, TracingLinked
{
    use Queueable;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function handle(LoggerInterface $logger, Dispatcher $dispatcher): void
    {
        $logger->info('Linked job handled');

        $dispatcher->dispatch(new ContextObserved(Context::getCurrent()));
    }
}
