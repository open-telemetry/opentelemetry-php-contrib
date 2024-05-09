<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Psr\Log\LoggerInterface;

class DummyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $name,
    ) {
    }

    public function handle(LoggerInterface $logger): void
    {
        $logger->info("Task: {$this->name}");
    }
}
