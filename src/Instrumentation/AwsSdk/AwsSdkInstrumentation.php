<?php

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation\AwsSdk;

use Aws\Middleware;
use Aws\ResultInterface;
use OpenTelemetry\API\Common\Instrumentation\InstrumentationInterface;
use OpenTelemetry\API\Common\Instrumentation\InstrumentationTrait;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;

use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Context\ScopeInterface;

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
    private $clients = [] ;
    private String $clientName;
    private String $region;
    private SpanInterface $span;
    private ScopeInterface $scope;

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

    public function getContext():Context
    {
        return Context::getRoot();
    }

    public function instrumentClients($clientsArray) : void
    {
        $this->clients = $clientsArray;
    }

    public function activate(): bool
    {
        try {
            $middleware = Middleware::tap(function ($cmd, $req) {
                $tracer = $this->getTracer();
                $propagator = $this->getPropagator();

                $carrier = [];

                $this->span = $tracer->spanBuilder($this->clientName . '.' . $cmd->getName())->setSpanKind(AwsSdkInstrumentation::SPAN_KIND)->startSpan();
                $this->scope = $this->span->activate();

                $propagator->inject($carrier, null, $this->getContext());

                $this->span->setAttributes([
                    'rpc.method' => $cmd->getName(),
                    'rpc.service' => $this->clientName,
                    'rpc.system' => 'aws-api',
                    'aws.region' => $this->region,
                    ]);
            });

            $end_middleware = Middleware::mapResult(function (ResultInterface $result) {
                $this->span->setAttributes([
                    'http.status_code' => $result['@metadata']['statusCode'],
                ]);

                $this->span->end();
                $this->scope->detach();

                return $result;
            });

            foreach ($this->clients as $client) {
                $this->clientName = $client->getApi()->getServiceName();
                $this->region = $client->getRegion();

                $client->getHandlerList()->prependInit($middleware, 'instrumentation');
                $client->getHandlerList()->appendSign($end_middleware, 'end_instrumentation');
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }
}
