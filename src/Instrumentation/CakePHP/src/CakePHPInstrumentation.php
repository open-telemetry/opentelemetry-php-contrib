<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\CakePHP;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Cake\Controller\Controller;
use Cake\Http\Response;
use Throwable;

class CakePHPInstrumentation
{
    public const NAME = 'cakephp';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.cakephp');

        hook(
            Controller::class,
            'invokeAction',
            pre: static function (Controller $app, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = ($app->getRequest() instanceof ServerRequestInterface) ? $app->getRequest() : null;
                $builder = $instrumentation->tracer()
                    ->spanBuilder(sprintf('%s', $request?->getMethod() ?? 'unknown'))
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);
                if ($request) {
                    $parent = Globals::propagator()->extract($request->getHeaders());
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(TraceAttributes::URL_FULL, $request->getUri()->__toString())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                        ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                        ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                        ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort())
                        ->setAttribute(TraceAttributes::URL_SCHEME, $request->getUri()->getScheme())
                        ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                        ->startSpan();
                } else {
                    $span = $builder->startSpan();
                }
                
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (Controller $app, array $params, ?ResponseInterface $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                $response = $app->getResponse();
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                if ($response) {
                    if ($response->getStatusCode() >= 400) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $response->getHeaderLine('Content-Length') ?: null);
                }

                $span->end();
            },
        );
    }
}
