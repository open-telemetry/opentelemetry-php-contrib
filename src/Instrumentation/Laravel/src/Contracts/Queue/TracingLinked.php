<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Queue;

/**
 * Marks a job as related to — but not a child of — the dispatching (parent) trace.
 *
 * When a job implements this interface, the instrumentation will start a new
 * root span for the job execution and add a span link to the context extracted
 * from the job payload, instead of making it a child of that context.
 */
interface TracingLinked
{
}
