<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Magento2;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\FrontController;
use Magento\Framework\App\Http;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\View;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Event\InvokerInterface;
use Magento\Framework\Event\Manager;
use Magento\Framework\View\Element\Template;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\LocalRootSpan;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\UriSanitizer\DefaultSanitizer;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class Magento2Instrumentation
{
    use LogsMessagesTrait;

    public const NAME = 'magento2';

    public static function register(): void
    {
        /** @var \OpenTelemetry\API\Metrics\HistogramInterface|null $histogram */
        static $histogram = null;

        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.magento2',
            null,
            'https://opentelemetry.io/schemas/1.32.0',
        );

        hook(
            Bootstrap::class,
            'terminate',
            pre: static function (Bootstrap $bootstrap, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $exception = $params[0] instanceof Throwable ? $params[0] : null;
                $span = $instrumentation->tracer()
                    ->spanBuilder('bootstrap.terminate')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );

        hook(
            Http::class,
            'launch',
            pre: static function (Http $http, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                if ($class !== Http::class) {
                    return;
                }
                $factory = new Psr17Factory();
                $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
                $parent = Globals::propagator()->extract($request->getHeaders());

                $requestMeta = [
                    HttpAttributes::HTTP_REQUEST_METHOD => self::canonizeMethod($request->getMethod()) ?? '_OTHER',
                    UrlAttributes::URL_SCHEME => $request->getUri()->getScheme(),
                    NetworkAttributes::NETWORK_PROTOCOL_VERSION => $request->getProtocolVersion(),
                ];

                $spanBuilder = $instrumentation->tracer()
                    ->spanBuilder(sprintf('%s %s', self::canonizeMethod($request->getMethod()) ?? 'HTTP', self::getScriptNameFromRequest($request)))
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttribute(UrlAttributes::URL_SCHEME, $request->getUri()->getScheme())
                    ->setAttribute(UrlAttributes::URL_PATH, $request->getUri()->getPath())
                    ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $requestMeta[HttpAttributes::HTTP_REQUEST_METHOD])
                    ->setAttribute(NetworkAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'));

                if ($requestMeta[HttpAttributes::HTTP_REQUEST_METHOD] === '_OTHER') {
                    $spanBuilder->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL, $request->getMethod());
                }

                [$serverAddress, $serverPort] = self::resolveServerAddressAndPort($request);
                if ($serverAddress !== null) {
                    $spanBuilder->setAttribute(ServerAttributes::SERVER_ADDRESS, $serverAddress);
                }
                if ($serverPort !== null) {
                    $spanBuilder->setAttribute(ServerAttributes::SERVER_PORT, $serverPort);
                }

                $requestStart = Clock::getDefault()->now();
                $span = $spanBuilder->setStartTimestamp($requestStart)->startSpan();

                if (strlen($request->getUri()->getQuery()) > 0) {

                    $span->setAttribute(UrlAttributes::URL_QUERY, (new DefaultSanitizer())->sanitize($request->getUri())->getQuery());
                }

                $scope = Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                $scope->offsetSet('requestMeta', $requestMeta);
                $scope->offsetSet('requestStart', $requestStart);
            },
            post: static function (Http $http, array $params, ResultInterface|HttpResponse|null $response, ?Throwable $exception, string $class) use (&$histogram, $instrumentation) {
                if ($class !== Http::class) {
                    return;
                }
                $requestEnd = Clock::getDefault()->now();
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                $responseMeta = [];

                if ($exception) {
                    $instrumentation->logger()->logRecordBuilder()->setException($exception)->emit();
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    $responseMeta[ErrorAttributes::ERROR_TYPE] = $exception::class;
                }
                if ($response instanceof HttpResponse) {
                    $statusCode = $response->getStatusCode();
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
                    $responseMeta[HttpAttributes::HTTP_RESPONSE_STATUS_CODE] = $statusCode;
                    if ($statusCode >= 500 && !$exception) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                        $responseMeta[ErrorAttributes::ERROR_TYPE] = $statusCode;
                    }
                    $prop = Globals::responsePropagator();
                    $prop->inject($response, ResponsePropagationSetter::getInstance(), $scope->context());
                }
                //https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#http-server
                /** @psalm-suppress MixedAssignment */
                $histogram ??= $instrumentation->meter()->createHistogram(
                    'http.server.request.duration',
                    's',
                    'Duration of HTTP server requests.',
                    ['ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]]
                );

                $requestMeta = $scope->offsetGet('requestMeta');
                /** @var int $requestStart */
                $requestStart = $scope->offsetGet('requestStart');
                /** @psalm-suppress PossiblyInvalidArgument */
                $histogram->record((float) (($requestEnd - $requestStart) / ClockInterface::NANOS_PER_SECOND), array_merge((array) $requestMeta, $responseMeta));
                $span->end($requestEnd);
            }
        );

        hook(
            FrontController::class,
            'dispatch',
            pre: static function (FrontController $frontController, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = $instrumentation->tracer()
                    ->spanBuilder('frontController.dispatch')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (FrontController $frontController, array $params, ResponseInterface|ResultInterface|null $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );

        /** @psalm-suppress DeprecatedClass */
        hook(
            Action::class,
            'dispatch',
            /** @psalm-suppress DeprecatedClass */
            pre: static function (Action $action, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $request = $params[0] instanceof HttpRequest ? $params[0] : null;
                /** @var non-empty-string $actionName */
                $actionName = $request?->getFullActionName() ?? 'unknown';
                if ($request?->getRouteName()) {
                    // Update local root span name as route is resolved
                    /** @psalm-suppress PossiblyNullArgument */
                    LocalRootSpan::current()->updateName(sprintf('%s %s', $request->getMethod(), $request->getRouteName()));
                }
                $span = $instrumentation->tracer()
                    ->spanBuilder('action.dispatch ' . $actionName)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            /** @psalm-suppress DeprecatedClass */
            post: static function (Action $action, array $params, ResponseInterface|ResultInterface|null $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );

        hook(
            ActionInterface::class,
            'execute',
            pre: static function (ActionInterface $actionInterface, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = $instrumentation->tracer()
                    ->spanBuilder('actionInterface.execute')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (ActionInterface $actionInterface, array $params, ResponseInterface|ResultInterface|null $response, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );

        hook(
            Manager::class,
            'dispatch',
            pre: static function (Manager $manager, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $eventName = is_string($params[0]) && $params[0] !== '' ? $params[0] : 'unknown';
                $span = $instrumentation->tracer()
                    ->spanBuilder('event.dispatch ' . $eventName)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (Manager $manager, array $params, mixed $void, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );

        hook(
            InvokerInterface::class,
            'dispatch',
            pre: static function (InvokerInterface $invokerInterface, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $configuration = is_array($params[0]) ? $params[0] : [];
                $observerName = (isset($configuration['name']) && is_string($configuration['name']) && $configuration['name'] !== '')
                    ? $configuration['name']
                    : 'unknown';
                $span = $instrumentation->tracer()
                    ->spanBuilder('observer ' . $observerName)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (InvokerInterface $invokerInterface, array $params, mixed $void, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );

        hook(
            Template::class,
            'fetchView',
            pre: static function (Template $template, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $templateFile = is_string($params[0]) ? $params[0] : null;
                $span = $instrumentation->tracer()
                    ->spanBuilder('template ' . ($templateFile ?? 'unknown'))
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (Template $template, array $params, ?string $html, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );

        hook(
            View::class,
            'renderLayout',
            pre: static function (View $view, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = $instrumentation->tracer()
                    ->spanBuilder('view.render.layout')
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (View $view, array $params, ?View $returnView, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                $span->end();
            }
        );
    }

    private static function getScriptNameFromRequest(ServerRequestInterface $request): string
    {
        $scriptName = $request->getServerParams()['SCRIPT_NAME'] ?? '/';
        if (!is_string($scriptName) || $scriptName === '') {
            return '/';
        }

        return $scriptName;
    }

    /**
     * Best-effort extraction as defined by HTTP semconv:
     * Forwarded host -> X-Forwarded-Host -> :authority -> Host.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private static function resolveServerAddressAndPort(ServerRequestInterface $request): array
    {
        $forwardedHost = self::extractForwardedHost($request->getHeaderLine('Forwarded'));
        if ($forwardedHost !== null) {
            return self::parseHostAndPort($forwardedHost);
        }

        $xForwardedHost = self::getFirstHeaderListValue($request->getHeaderLine('X-Forwarded-Host'));
        if ($xForwardedHost !== null) {
            return self::parseHostAndPort($xForwardedHost);
        }

        $authority = trim($request->getHeaderLine(':authority'));
        if ($authority !== '') {
            return self::parseHostAndPort($authority);
        }

        $host = trim($request->getHeaderLine('Host'));
        if ($host !== '') {
            return self::parseHostAndPort($host);
        }

        $uriHost = $request->getUri()->getHost();
        $uriPort = $request->getUri()->getPort();

        return [$uriHost !== '' ? $uriHost : null, $uriPort];
    }

    /**
     * @param string $forwardedHeader
     * @return string|null
     * @psalm-pure
     */
    private static function extractForwardedHost(string $forwardedHeader): ?string
    {
        if ($forwardedHeader === '') {
            return null;
        }

        $entries = explode(',', $forwardedHeader);
        foreach ($entries as $entry) {
            $parts = explode(';', $entry);
            foreach ($parts as $part) {
                $pair = explode('=', trim($part), 2);
                if (count($pair) !== 2) {
                    continue;
                }

                if (strtolower(trim($pair[0])) !== 'host') {
                    continue;
                }

                $value = trim($pair[1]);
                $value = trim($value, "\"'");

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param string $headerValue
     * @return string|null
     * @psalm-pure
     */
    private static function getFirstHeaderListValue(string $headerValue): ?string
    {
        if ($headerValue === '') {
            return null;
        }

        $first = trim(explode(',', $headerValue)[0]);

        return $first !== '' ? $first : null;
    }

    /**
     * @return array{0: string|null, 1: int|null}
     * @psalm-pure
     */
    private static function parseHostAndPort(string $hostAndPort): array
    {
        $hostAndPort = self::getFirstHeaderListValue($hostAndPort) ?? '';
        if ($hostAndPort === '') {
            return [null, null];
        }

        if (str_starts_with($hostAndPort, '[')) {
            $end = strpos($hostAndPort, ']');
            if ($end === false) {
                return [$hostAndPort, null];
            }

            $address = substr($hostAndPort, 1, $end - 1);
            $remainder = trim(substr($hostAndPort, $end + 1));
            if (str_starts_with($remainder, ':')) {
                $port = self::parsePort(substr($remainder, 1));

                return [$address !== '' ? $address : null, $port];
            }

            return [$address !== '' ? $address : null, null];
        }

        if (substr_count($hostAndPort, ':') > 1) {
            return [$hostAndPort, null];
        }

        if (str_contains($hostAndPort, ':')) {
            $parts = explode(':', $hostAndPort, 2);
            $address = $parts[0];
            $portString = $parts[1] ?? '';

            return [
                $address !== '' ? $address : null,
                self::parsePort($portString),
            ];
        }

        return [$hostAndPort, null];
    }

    /**
     * @param string $port
     * @return int|null
     * @psalm-pure
     */
    private static function parsePort(string $port): ?int
    {
        $port = trim($port);
        if ($port === '' || !preg_match('/^\d+$/', $port)) {
            return null;
        }

        $portNumber = (int) $port;

        return $portNumber > 0 && $portNumber <= 65535 ? $portNumber : null;
    }

    /**
     * @param string $method
     * @return string|null
     * @psalm-pure
     */
    private static function canonizeMethod(string $method): ?string
    {
        // RFC9110, RFC5789
        $knownMethods = [
            HttpAttributes::HTTP_REQUEST_METHOD_VALUE_GET,
            HttpAttributes::HTTP_REQUEST_METHOD_VALUE_HEAD,
            HttpAttributes::HTTP_REQUEST_METHOD_VALUE_POST,
            HttpAttributes::HTTP_REQUEST_METHOD_VALUE_PUT,
            HttpAttributes::HTTP_REQUEST_METHOD_VALUE_DELETE,
            HttpAttributes::HTTP_REQUEST_METHOD_VALUE_CONNECT,
            HttpAttributes::HTTP_REQUEST_METHOD_VALUE_OPTIONS,
            HttpAttributes::HTTP_REQUEST_METHOD_VALUE_TRACE,
            HttpAttributes::HTTP_REQUEST_METHOD_VALUE_PATCH,
            HttpIncubatingAttributes::HTTP_REQUEST_METHOD_VALUE_QUERY,
        ];

        if (in_array($method, $knownMethods)) {
            return $method;
        }

        return null;
    }
}
