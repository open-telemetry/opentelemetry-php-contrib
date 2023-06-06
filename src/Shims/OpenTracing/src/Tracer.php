<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Shim\OpenTracing;

use Composer\InstalledVersions;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTracing as API;
use OpenTracing\StartSpanOptions;

/**
 * @psalm-suppress UndefinedInterfaceMethod
 */
class Tracer implements API\Tracer
{
    private TracerProviderInterface $tracerProvider;
    private TracerInterface $tracer;
    private API\ScopeManager $scopeManager;

    public function __construct(TracerProviderInterface $tracerProvider)
    {
        $version = InstalledVersions::getPrettyVersion('open-telemetry/opentracing-shim');
        $this->tracer = $tracerProvider->getTracer('opentracing-shim', $version);
        $this->tracerProvider = $tracerProvider;
        $this->scopeManager = new ScopeManager();
    }

    public function getScopeManager(): API\ScopeManager
    {
        return $this->scopeManager;
    }

    public function getActiveSpan(): ?API\Span
    {
        $scope = $this->scopeManager->getActive();
        if ($scope === null) {
            return null;
        }

        return $scope->getSpan();
    }

    private function parseOptions($options): API\StartSpanOptions
    {
        if (!($options instanceof API\StartSpanOptions)) {
            if (array_key_exists('child_of', $options) && $options['child_of'] instanceof Span) {
                $options['child_of'] = $options['child_of']->getContext();
            }
            $options = API\StartSpanOptions::create($options);
        }

        return $options;
    }

    /**
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress LessSpecificReturnStatement
     */
    public function startActiveSpan(string $operationName, $options = []): Scope
    {
        $options = $this->parseOptions($options);

        $span = $this->startSpan($operationName, $options);

        /** @phpstan-ignore-next-line */
        return $this->scopeManager->activate(
            $span,
            $options->shouldFinishSpanOnClose()
        );
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function startSpan(string $operationName, $options = []): Span
    {
        $options = $this->parseOptions($options);

        $parent = $this->getParent($options);
        $spanBuilder = $this->tracer
            ->spanBuilder($operationName)
            ->setParent($parent)
            ->setAttributes($options->getTags());
        $span = $spanBuilder->startSpan();
        $context = $parent->withContextValue(\OpenTelemetry\API\Trace\Span::wrap($span->getContext()));

        return new Span($span, $context, $operationName);
    }

    /**
     * @phan-suppress PhanUndeclaredMethod
     */
    public function inject(API\SpanContext $spanContext, string $format, &$carrier): void
    {
        /** @phpstan-ignore-next-line */
        $context = $spanContext->getContext();

        $propagator = $this->propagator($format);
        if ($propagator !== null) {
            $propagator->inject($carrier, null, $context);
        }
    }

    public function extract(string $format, $carrier): ?API\SpanContext
    {
        $propagator = $this->propagator($format);
        if ($propagator !== null) {
            $context = $propagator->extract($carrier);

            return new SpanContext($context);
        }

        return null;
    }

    public function flush(): void
    {
        if ($this->tracerProvider instanceof \OpenTelemetry\SDK\Trace\TracerProviderInterface) {
            $this->tracerProvider->forceFlush();
        }
    }

    private function propagator(string $openTracingFormat): ?TextMapPropagatorInterface
    {
        if ($openTracingFormat === API\Formats\BINARY) {
            throw API\UnsupportedFormatException::forFormat(API\Formats\BINARY);
        }
        switch ($openTracingFormat) {
            case API\Formats\TEXT_MAP:
            case API\Formats\HTTP_HEADERS:
                return TraceContextPropagator::getInstance();
            default:
                return null;
        }
    }

    /**
     * Get parent context from options, falling back to active/current context
     * @phan-suppress PhanUndeclaredMethod
     */
    private function getParent(StartSpanOptions $options): ContextInterface
    {
        $references = $options->getReferences();
        foreach ($references as $ref) {
            if ($ref->isType(API\Reference::CHILD_OF)) {
                /** @phpstan-ignore-next-line */
                return $ref->getSpanContext()->getContext();
            }
        }
        $active = $this->getActiveSpan();
        if ($active) {
            /** @phpstan-ignore-next-line */
            return $active->getContext()->getContext();
        }

        return Context::getCurrent();
    }
}
