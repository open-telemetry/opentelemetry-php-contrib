<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\AwsSdk;

use OpenTelemetry\API\Common\Instrumentation\InstrumentationInterface;
use OpenTelemetry\API\Common\Instrumentation\InstrumentationTrait;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * @experimental
 */
class AwsSdkInstrumentation implements InstrumentationInterface
{
    use InstrumentationTrait;

    public const NAME = 'AWS SDK Instrumentation';
    public const VERSION = '0.0.1';
    public const SPAN_KIND = SpanKind::KIND_CLIENT;
    private TextMapPropagatorInterface $propagator;
    private TracerProviderInterface $tracerProvider;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getVersion(): ?string
    {
        return self::VERSION;
    }

    public function getSchemaUrl(): ?string
    {
        return null;
    }

    public function init(): bool
    {
        return true;
    }

    public function setPropagator(TextMapPropagatorInterface $propagator): void
    {
        $this->propagator = $propagator;
    }

    public function getPropagator(): TextMapPropagatorInterface
    {
        return $this->propagator;
    }

    public function setTracerProvider(TracerProviderInterface $tracerProvider): void
    {
        $this->tracerProvider = $tracerProvider;
    }

    public function getTracerProvider(): TracerProviderInterface
    {
        return $this->tracerProvider;
    }

    public function getTracer(): TracerInterface
    {
        return $this->tracerProvider->getTracer('io.opentelemetry.contrib.php');
    }

    public function activate(): bool
    {
        AwsGlobal::setInstrumentation($this);

        try {
            runkit7_method_copy('Aws\AwsClient', '__call_copy', 'Aws\AwsClient', '__call');
            runkit7_method_copy('Aws\AwsClient', 'executeAsync_copy', 'Aws\AwsClient', 'executeAsync');

            runkit7_method_redefine(
                'Aws\AwsClient',
                '__call',
                '$name, $args',
                '   
                
                $tracer = \OpenTelemetry\Instrumentation\AwsSdk\AwsGlobal::getInstrumentation()->getTracer();
                $carrier = [];
                
                $this->span = $tracer->spanBuilder($this->getApi()->getServiceName() . "." . $name)->setSpanKind(\OpenTelemetry\Instrumentation\AwsSdk\AwsSdkInstrumentation::SPAN_KIND)->startSpan();
                $this->scope = $this->span->activate();
                
                $propagator = \OpenTelemetry\Instrumentation\AwsSdk\AwsGlobal::getInstrumentation()->getPropagator();
                $propagator->inject($carrier);
                
                $this->span->setAttributes([
                    "rpc.method" => $name,
                    "rpc.service" => $this->getApi()->getServiceName(),
                    "rpc.system" => "aws-api",
                    "aws.region" => $this->getRegion()
                ]);
                
                return $this->__call_copy($name, $args);
                    
                
                ',
            );

            runkit7_method_redefine(
                'Aws\AwsClient',
                'executeAsync',
                '$command',
                ' 
                    $this->scope->detach();
                    $this->span->end();
                    return $this->executeAsync_copy($command);
                ',
            );
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }
}
