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

    private array $metricAttributes = [];

    public function instrument(): void
    {
        hook(
            \Cake\Http\Server::class,
            'run',
            pre: function (\Cake\Http\Server $server, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                $request = $params[0];
                assert($request === null || $request instanceof ServerRequestInterface);

                if($request !== null) {
                    [$serverAddress, $serverPort] = $this->extractHostAddressAndPort($request);

                    $this->metricAttributes = [
                        TraceAttributes::HTTP_REQUEST_METHOD => $request->getMethod(),
                        TraceAttributes::URL_SCHEME => $request->getUri()->getScheme(),
                        TraceAttributes::NETWORK_PROTOCOL_VERSION => $request->getProtocolVersion(),
                        TraceAttributes::SERVER_ADDRESS => $serverAddress,
                        TraceAttributes::SERVER_PORT => $serverPort,
                    ];
                }
                
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
                    $this->metricAttributes += [TraceAttributes::HTTP_ROUTE => $route];
                }
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    $this->metricAttributes += [TraceAttributes::ERROR_TYPE => get_class($exception)];
                }
                if ($response) {
                    if ($response->getStatusCode() >= 400) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                        $this->metricAttributes += [TraceAttributes::ERROR_TYPE => $response->getStatusCode()];
                    }

                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $response->getHeaderLine('Content-Length'));
                    $this->metricAttributes += [TraceAttributes::HTTP_RESPONSE_STATUS_CODE => $response->getStatusCode()];
                }

                $span->end();

                if($this->isRoot()) {
                    $this->recordDuration($span, $this->metricAttributes);
                    $this->recordRequestBodySize($request, $this->metricAttributes);
                    $this->recordResponseBodySize($response, $this->metricAttributes);
                }
            },
        );
    }

    private function extractHostAddressAndPort(ServerRequestInterface $request): array
    {
        $scheme = $request->getUri()->getScheme();
        $defaultPort = $scheme === 'https' ? 443 : 80;

        $xForwardedHost = $request->getHeader('X-Forwarded-Host');
        $xForwardedHost = empty($xForwardedHost) ? null : $xForwardedHost[0];
        if($xForwardedHost !== null) {
            return $this->parseHostString($xForwardedHost, $defaultPort);
        }

        $forwarded = $request->getHeader('Forwarded');
        $forwarded  = empty($forwarded) ? null : $forwarded[0];
        if($forwarded !== null) {
            $parts = explode(';', $forwarded);
            $host = preg_grep('/^host=.*$/', $parts);
            if ($host) {
                return $this->parseHostString($host[0], $defaultPort);
            }
        }

        $hostHeader = $request->getHeader('Host');
        $hostHeader = empty($hostHeader) ? null : $hostHeader[0];
        if ($hostHeader) {
            return $this->parseHostString($hostHeader, $defaultPort);
        }

        return [$request->getUri()->getHost(), $defaultPort];
    }

    private function parseHostString(string $host, int $defaultPort): array
    {
        $parts = explode(':', $host);
        if(count($parts) < 2) {
            return [$parts[0], $defaultPort];
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @param SpanInterface $span
     * @param array $metricAttributes
     * @return void
     */
    private function recordDuration(SpanInterface $span, array $metricAttributes): void
    {
        if (method_exists($span, 'getDuration')) {
            $this->instrumentation->meter()->createHistogram(
                'http.server.request.duration',
                's',
                'Duration of HTTP server requests.',
                [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]
            )->record($span->getDuration() / ClockInterface::NANOS_PER_SECOND, $metricAttributes);
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @param array $metricAttributes
     * @return void
     */
    private function recordRequestBodySize(ServerRequestInterface $request, array $metricAttributes): void
    {
        if ($request->getBody()->getSize() !== null) {
            $this->instrumentation->meter()->createHistogram(
                'http.server.request.body.size',
                'By',
                'Size of HTTP server request bodies.'
            )->record($request->getBody()->getSize(), $metricAttributes);
        }
    }

    /**
     * @param ResponseInterface|null $response
     * @param array $metricAttributes
     * @return void
     */
    private function recordResponseBodySize(?ResponseInterface $response, array $metricAttributes): void
    {
        if ($response && $response->getBody()->getSize() !== null) {
            $this->instrumentation->meter()->createHistogram(
                'http.server.response.body.size',
                'By',
                'Size of HTTP server response bodies.'
            )->record($response->getBody()->getSize(), $metricAttributes);
        }
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