<?php

// src/Instrumentation/AwsSdk/AwsSdkInstrumentation.php
declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\AwsSdk;

use Aws\AwsClient;
use Aws\Exception\AwsException;
use Aws\ResultInterface;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;

final class AwsSdkInstrumentation
{
    public const NAME = 'aws-sdk';

    public static function register(): void
    {
        $inst = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.aws-sdk',
            null,
            'https://opentelemetry.io/schemas/1.30.0',
        );

        /**
         * ② Intercept the low‑level `execute` call that actually
         *    performs the HTTP request and has the Command object.
         */
        hook(
            AwsClient::class,
            'execute',
            pre: static function (
                AwsClient $c,
                array $params,
                string $class,
                string $func,
                ?string $file,
                ?int $line
            ) use ($inst) {
                $cmd     = $params[0];
                $builder = $inst->tracer()
                    ->spanBuilder("{$c->getApi()->getServiceName()}.{$cmd->getName()}")
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute('rpc.system', 'aws-api')
                    ->setAttribute('rpc.method', $cmd->getName())
                    ->setAttribute('rpc.service', $c->getApi()->getServiceName())
                    ->setAttribute('aws.region', $c->getRegion())
                    ->setAttribute('code.function', $func)
                    ->setAttribute('code.namespace', $class)
                    ->setAttribute('code.filepath', $file)
                    ->setAttribute('code.line_number', $line);

                $span   = $builder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (
                AwsClient $c,
                array $params,
                mixed $result,
                ?\Throwable $ex
            ) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());
                $scope->detach();

                if ($result instanceof ResultInterface && isset($result['@metadata'])) {
                    $span->setAttribute('http.status_code', $result['@metadata']['statusCode']);
                    $span->setAttribute('aws.requestId', $result['@metadata']['headers']['x-amz-request-id']);
                }
                if ($ex) {
                    if ($ex instanceof AwsException && $ex->getAwsRequestId() !== null) {
                        $span->setAttribute('aws.requestId', $ex->getAwsRequestId());
                    }
                    $span->recordException($ex);
                    $span->setStatus(StatusCode::STATUS_ERROR, $ex->getMessage());
                }
                $span->end();
            }
        );
    }
}
