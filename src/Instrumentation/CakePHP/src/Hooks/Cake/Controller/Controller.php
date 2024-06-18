<?php
declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\CakePHP\Hooks\Cake\Controller;

use Cake\Controller\Controller as CakeController;
use Cake\Routing\Router;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\CakePHP\Hooks\CakeHook;
use OpenTelemetry\Contrib\Instrumentation\CakePHP\Hooks\CakeHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use function OpenTelemetry\Instrumentation\hook;

class Controller implements CakeHook
{
    use CakeHookTrait;

    public function instrument(): void
    {
        hook(
            CakeController::class,
            'invokeAction',
            pre: function (CakeController $app, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $request = $app->getRequest();
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $this->instrumentation->tracer()
                    ->spanBuilder($request->getMethod())
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

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
                    ->setAttribute(TraceAttributes::HTTP_ROUTE, $request->getParam('_matchedRoute'))
                    ->startSpan();

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (CakeController $app, array $params, ?ResponseInterface $response, ?Throwable $exception) {
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
                /** @var ResponseInterface|null $response */
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