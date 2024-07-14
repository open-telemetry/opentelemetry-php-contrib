<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\CakePHP\Hooks\Cake\Http;

use Cake\Routing\Exception\MissingRouteException;
use Cake\Routing\Router;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\CakePHP\Hooks\CakeHook;
use OpenTelemetry\Contrib\Instrumentation\CakePHP\Hooks\CakeHookTrait;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SDK\Common\Time\ClockInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class Server implements CakeHook
{
    use CakeHookTrait;

    public function instrument(): void
    {
        hook(
            \Cake\Http\Server::class,
            'run',
            pre: function (\Cake\Http\Server $server, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $request = $params[0];
                assert($request === null || $request instanceof ServerRequestInterface);

                $request = $this->buildSpan($request, $class, $function, $filename, $lineno);

                return [$request];
            },
            post: function (\Cake\Http\Server $server, array $params, ?ResponseInterface $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();

                /** @var ServerRequestInterface $request */
                $request = $params[0];
                $route = $this->getRouteTemplate($request);
                $span = \OpenTelemetry\API\Trace\Span::fromContext($scope->context());

                if($route && $this->isRoot()) {
                    $span->setAttribute(TraceAttributes::HTTP_ROUTE, $route);
                }
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
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $response->getHeaderLine('Content-Length'));
                }

                $span->end();
            },
        );
    }

    /**
     * @param $request
     * @return string|null
     */
    private function getRouteTemplate($request): string|null
    {
        try {
            $route = Router::parseRequest($request);

            return $route['_matchedRoute'] ?? null;
        } catch (MissingRouteException) {
            return null;
        }
    }
}