<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Aws\Lambda;

use Bref\Context\Context;
use Bref\Event\Http\HttpRequestEvent;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Aws\Lambda\Detector as LambdaDetector;
use OpenTelemetry\Contrib\Aws\Xray\IdGenerator;
use OpenTelemetry\Contrib\Aws\Xray\Propagator as XrayPropagator;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;

class AwsLambdaWrapper
{
    private const LAMBDA_NAME_ENV = 'AWS_LAMBDA_FUNCTION_NAME';
    private const LAMBDA_INVOCATION_CONTEXT_ENV = 'LAMBDA_INVOCATION_CONTEXT';
    private const OTEL_SERVICE_NAME_ENV = 'OTEL_SERVICE_NAME';
    private const OTEL_EXPORTER_ENDPOINT_ENV = 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT';

    private const DEFAULT_OTLP_EXPORTER_ENDPOINT = 'http://localhost:4318/v1/traces';

    private TracerInterface $tracer;
    private bool $isColdStart = true;

    private static ?AwsLambdaWrapper $instance = null;

    /** @psalm-suppress PossiblyUnusedMethod */
    public static function getInstance(): AwsLambdaWrapper
    {
        if (self::$instance === null) {
            self::$instance = new AwsLambdaWrapper();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $lambdaDetector = new LambdaDetector();
        $defaultResource = ResourceInfoFactory::defaultResource();
        $resource = $defaultResource->merge($lambdaDetector->getResource());

        if (getenv(self::OTEL_SERVICE_NAME_ENV) == null) {
            $service_name_resource = ResourceInfo::create(Attributes::create([
                TraceAttributes::SERVICE_NAME => getenv(self::LAMBDA_NAME_ENV),
            ]));
            $resource = $resource->merge($service_name_resource);
        }

        // 1) Configure OTLP/HTTP exporter
        $transportFactory = new OtlpHttpTransportFactory();
        $transport = $transportFactory->create(
            getenv(self::OTEL_EXPORTER_ENDPOINT_ENV)
                ?: self::DEFAULT_OTLP_EXPORTER_ENDPOINT,
            'application/x-protobuf'
        );
        $exporter = new OtlpExporter($transport);

        $spanProcessor = new SimpleSpanProcessor($exporter);
        $xrayIdGenerator = new IdGenerator();
        $tracerProvider = new TracerProvider($spanProcessor, null, $resource, null, $xrayIdGenerator);

        $this->tracer = $tracerProvider->getTracer('php-lambda');

        register_shutdown_function(function () use ($tracerProvider): void {
            $tracerProvider->shutdown();
        });

    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getTracer(): TracerInterface
    {
        return $this->tracer;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function setTracer(TracerInterface $newTracer): void
    {
        $this->tracer = $newTracer;
    }

    /** @psalm-suppress PossiblyUnusedMethod
     * @psalm-suppress ArgumentTypeCoercion
     * @psalm-suppress PossiblyFalseArgument
    */
    public function WrapHandler(callable $handler): callable
    {
        return function (array $event, Context $context) use ($handler): array {

            $jsonContext = getenv(self::LAMBDA_INVOCATION_CONTEXT_ENV) ?: '';

            $lambdaContext = json_decode($jsonContext, true, 512, JSON_THROW_ON_ERROR);

            $awsRequestId       = $lambdaContext['awsRequestId']       ?? null;
            $invokedFunctionArn = $lambdaContext['invokedFunctionArn'] ?? null;
            $traceId            = $lambdaContext['traceId']            ?? null;

            $propagator = new XrayPropagator();
            $carrier = [
                XrayPropagator::AWSXRAY_TRACE_ID_HEADER => $traceId,
            ];
            $parentCtx  = $propagator->extract($carrier);

            $lambdaName = getenv(self::LAMBDA_NAME_ENV);

            $lambdaSpanAttributes = Attributes::create([
                TraceAttributes::FAAS_TRIGGER => self::isHttpRequest($event) ? 'http' : 'other',
                TraceAttributes::FAAS_COLDSTART => $this->isColdStart,
                TraceAttributes::FAAS_NAME => $lambdaName,
                TraceAttributes::FAAS_INVOCATION_ID => $awsRequestId,
                TraceAttributes::CLOUD_RESOURCE_ID => self::getCloudResourceId($invokedFunctionArn),
                TraceAttributes::CLOUD_ACCOUNT_ID => self::getAccountId($invokedFunctionArn),
            ]);

            $this->isColdStart = false;

            // Start a root span for the Lambda invocation
            $rootSpan = $this->tracer
                ->spanBuilder($lambdaName)
                ->setParent($parentCtx)
                ->setAttributes($lambdaSpanAttributes)
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->startSpan();
            $rootScope = $rootSpan->activate();

            try {
                return $handler($event, $context);
            } finally {
                $rootSpan->end();
                $rootScope->detach();
            }
        };
    }

    private static function getAccountId(?string $functionArn): ?string
    {
        if (empty($functionArn)) {
            return null;
        }

        $segments = explode(':', $functionArn);

        return isset($segments[4]) ? $segments[4] : null;
    }

    private static function getCloudResourceId(?string $functionArn): ?string
    {
        if (empty($functionArn)) {
            return null;
        }

        // According to cloud.resource_id description https://github.com/open-telemetry/semantic-conventions/blob/v1.32.0/docs/resource/faas.md?plain=1#L59-L63
        // the 8th part of arn (function version or alias, see https://docs.aws.amazon.com/lambda/latest/dg/lambda-api-permissions-ref.html)
        // should not be included into cloud.resource_id
        $segments = explode(':', $functionArn);
        if (count($segments) >= 8) {
            return implode(':', array_slice($segments, 0, 7));
        }

        return $functionArn;
    }

    private static function isHttpRequest(array $event): bool
    {
        try {
            /** @phan-suppress-next-line PhanNoopNew  */
            new HttpRequestEvent($event);
        } catch (\Throwable $th) {
            return false;
        }

        return true;
    }

}
