<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelSdkBundle\DependencyInjection;

use OpenTelemetry\SDK\Trace\SpanProcessor;
use OpenTelemetry\Symfony\OtelSdkBundle\Debug\TraceableSpanProcessor;

interface SpanProcessors
{
    public const SIMPLE = SpanProcessor\SimpleSpanProcessor::class;
    public const BATCH = SpanProcessor\BatchSpanProcessor::class;
    public const NOOP = SpanProcessor\NoopSpanProcessor::class;
    public const MULTI = SpanProcessor\MultiSpanProcessor::class;
    public const TRACEABLE = TraceableSpanProcessor::class;
    public const SPAN_PROCESSORS = [
        self::SIMPLE,
        self::BATCH,
        self::NOOP,
        self::MULTI,
    ];
    public const DEFAULT = self::BATCH;
    public const NAMESPACE = 'OpenTelemetry\SDK\Trace\SpanProcessor';
}
