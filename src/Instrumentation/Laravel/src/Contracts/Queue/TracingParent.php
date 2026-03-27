<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Queue;

/**
 * Marks a job as a child of the dispatching (parent) trace.
 *
 * When a job implements this interface, the instrumentation will start a new
 * span for the job execution and make it a child of the context extracted
 * from the job payload.
 *
 * This behavior is the default. While optional, this interface allows for
 * explicit declaration of the tracing relationship.
 */
interface TracingParent
{
}
