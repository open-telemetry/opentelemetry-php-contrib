<?php

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
use OpenTelemetry\SemConv\TraceAttributes;

/** @psalm-suppress UnusedClass */
final class AwsSdkInstrumentation
{
    /** @psalm-suppress ArgumentTypeCoercion */
    public const NAME = 'aws-sdk';

    public static function register(): void
    {
        $inst = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.aws-sdk',
            null,
            'https://opentelemetry.io/schemas/1.32.0',
        );

        /**
         * Intercept the low‑level `execute` call that actually
         * performs the HTTP request and has the Command object.
         */
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            AwsClient::class,
            'execute',
            pre: static function (
                AwsClient $c,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno
            ) use ($inst) {
                $cmd     = $params[0];
                $builder = $inst->tracer()
                    ->spanBuilder("{$c->getApi()->getServiceName()}.{$cmd->getName()}")
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::RPC_SYSTEM, 'aws-api')
                    ->setAttribute(TraceAttributes::RPC_METHOD, $cmd->getName())
                    ->setAttribute(TraceAttributes::RPC_SERVICE, $c->getApi()->getServiceName())
                    ->setAttribute(TraceAttributes::CLOUD_REGION, $c->getRegion())
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

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
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $result['@metadata']['statusCode']); // @phan-suppress-current-line PhanTypeMismatchDimFetch
                    $span->setAttribute(TraceAttributes::AWS_REQUEST_ID, $result['@metadata']['headers']['x-amz-request-id']); // @phan-suppress-current-line PhanTypeMismatchDimFetch
                }
                if ($ex) {
                    if ($ex instanceof AwsException && $ex->getAwsRequestId() !== null) {
                        $span->setAttribute(TraceAttributes::AWS_REQUEST_ID, $ex->getAwsRequestId());
                    }
                    $span->recordException($ex);
                    $span->setStatus(StatusCode::STATUS_ERROR, $ex->getMessage());
                }
                $span->end();
            }
        );
    }
}
