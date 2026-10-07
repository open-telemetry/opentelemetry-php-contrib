<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Queue;

/**
 * Marks a job as fully independent from the dispatching (parent) trace.
 *
 * When a job implements this interface, the instrumentation will start a new
 * root span for the job execution with no parent and no link to the context
 * extracted from the job payload.
 */
interface TracingIsolated
{
}
