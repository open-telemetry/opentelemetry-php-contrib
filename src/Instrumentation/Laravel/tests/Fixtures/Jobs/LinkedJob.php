<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Queue\TracingLinked;
use Psr\Log\LoggerInterface;

class LinkedJob implements ShouldQueue, TracingLinked
{
    use Queueable;

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function handle(LoggerInterface $logger): void
    {
        $logger->info('Linked job handled');
    }
}
