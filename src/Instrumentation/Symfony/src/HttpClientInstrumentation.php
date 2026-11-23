<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\HttpClient\UriTemplateHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @phan-file-suppress PhanTypeInvalidCallableArraySize
 * @psalm-suppress UnusedClass
 */
final class HttpClientInstrumentation
{
    /**
     * These clients are not supported by this instrumentation, because
     * they are synchronous and do not support the on_progress option.
     */
    const SYNCHRONOUS_CLIENTS = [
        /** @psalm-suppress UndefinedClass */
        'ApiPlatform\Symfony\Bundle\Test\Client',
        'ApiPlatform\Test\Client',
    ];

    /**
     * These decorators send each request they receive to the client they wrap, once, with a URL they may have
     * rewritten (resolved against a base URI, expanded from a template).
     */
    const URL_REWRITING_CLIENTS = [
        ScopingHttpClient::class,
        UriTemplateHttpClient::class,
    ];

    public static function supportsProgress(string $class): bool
    {
        return false === in_array($class, self::SYNCHRONOUS_CLIENTS);
    }

    private static function requestKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-http-client.request');
    }

    private static function rewritingKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-http-client.rewriting');
    }

    private static function spanKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-http-client.span');
    }

    private static function forwardedKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-http-client.forwarded');
    }

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.symfony_http',
            null,
            Version::VERSION_1_38_0->url(),
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            HttpClientInterface::class,
            'request',
            pre: static function (
                HttpClientInterface $client,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                $parent = Context::getCurrent();
                $rawUrl = (string) $params[1];
                $requestOptions = $params[2] ?? [];
                $request = sprintf('%s %s', $params[0], $rawUrl);

                $ownerRequest = $parent->get(self::requestKey());

                if ($ownerRequest === $request || true === $parent->get(self::rewritingKey())) {
                    $ownerSpan = $parent->get(self::spanKey());
                    if ($ownerRequest !== $request && $ownerSpan instanceof SpanInterface) {
                        $ownerSpan->setAttributes(self::urlAttributes($rawUrl));
                    }
                    Context::storage()->attach($parent
                        ->with(self::requestKey(), $request)
                        ->with(self::forwardedKey(), true)
                        ->with(self::rewritingKey(), \in_array($class, self::URL_REWRITING_CLIENTS, true)));

                    return $params;
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('%s', $params[0]))
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttributes(self::urlAttributes($rawUrl))
                    ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $params[0])
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

                $propagator = Globals::propagator();

                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                if (!isset($requestOptions['headers'])) {
                    $requestOptions['headers'] = [];
                }

                /** @psalm-suppress UndefinedClass */
                if (false === self::supportsProgress($class)) {
                    $context = self::ownerContext($span->storeInContext($parent), $span, $request, $class);
                    $propagator->inject($requestOptions['headers'], ArrayAccessGetterSetter::getInstance(), $context);

                    Context::storage()->attach($context);

                    return $params;
                }

                $previousOnProgress = $requestOptions['on_progress'] ?? null;
                $awaitingResolvedUrl = !self::isAbsoluteUrl($rawUrl);

                //As Response are lazy we end span when status code was received
                $requestOptions['on_progress'] = static function (int $dlNow, int $dlSize, array $info) use (
                    $previousOnProgress,
                    $span,
                    &$awaitingResolvedUrl
                ): void {
                    if (null !== $previousOnProgress) {
                        $previousOnProgress($dlNow, $dlSize, $info);
                    }

                    $resolvedUrl = $info['url'] ?? null;
                    if ($awaitingResolvedUrl && \is_string($resolvedUrl) && '' !== $resolvedUrl) {
                        $awaitingResolvedUrl = false;
                        $span->setAttributes(self::urlAttributes($resolvedUrl));
                    }

                    $statusCode = $info['http_code'] ?? null;
                    if (0 !== $statusCode && null !== $statusCode && $span->isRecording() && !self::isFollowingRedirect($info)) {
                        $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);

                        if ($statusCode >= 400 && $statusCode < 600) {
                            $span->setStatus(StatusCode::STATUS_ERROR);
                        }

                        $span->end();
                    }
                };

                $context = self::ownerContext($span->storeInContext($parent), $span, $request, $class);
                $propagator->inject($requestOptions['headers'], ArrayAccessGetterSetter::getInstance(), $context);

                Context::storage()->attach($context);
                $params[2] = $requestOptions;

                return $params;
            },
            post: static function (
                HttpClientInterface $client,
                array $params,
                ?ResponseInterface $response,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $scope->detach();

                if (true === $scope->context()->get(self::forwardedKey())) {
                    return;
                }

                $span = Span::fromContext($scope->context());

                if (null !== $exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    $span->end();

                    return;
                }

                if ($response !== null && false === self::supportsProgress(get_class($client))) {
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());

                    if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 600) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                }

                // As most Response are lazy we end span after response is received,
                // it's added in on_progress callback, see line 69
            },
        );
    }

    /**
     * @return array<non-empty-string, ?string>
     */
    private static function urlAttributes(string $url): array
    {
        $url = self::redactCredentials($url);

        return [
            TraceAttributes::PEER_SERVICE => parse_url($url)['host'] ?? null,
            UrlAttributes::URL_FULL => $url,
        ];
    }

    private static function isFollowingRedirect(array $info): bool
    {
        $statusCode = $info['http_code'];
        $headers = $info['response_headers'] ?? [];
        if ($statusCode < 300 || $statusCode >= 400 || [] === $headers) {
            return false;
        }

        $responses = \count(preg_grep('#^HTTP/#', $headers) ?: []);
        if (0 === $responses) {
            return false;
        }

        if ($responses <= ($info['redirect_count'] ?? 0)) {
            return true;
        }

        if ('' !== ($info['redirect_url'] ?? null)) {
            return false;
        }

        for ($i = \count($headers) - 1; $i >= 0 && !str_starts_with($headers[$i], 'HTTP/'); --$i) {
            if (0 === stripos($headers[$i], 'location:')) {
                return true;
            }
        }

        return false;
    }

    private static function isAbsoluteUrl(string $url): bool
    {
        $parts = parse_url($url) ?: [];

        return isset($parts['scheme'], $parts['host']);
    }

    private static function redactCredentials(string $url): string
    {
        if (!str_contains($url, '@')) {
            return $url;
        }

        return preg_replace('~^((?:[^:/?#]+:)?//)[^/?#]*@~', '$1REDACTED:REDACTED@', $url) ?? $url;
    }

    private static function ownerContext(ContextInterface $context, SpanInterface $span, string $request, string $class): ContextInterface
    {
        return $context
            ->with(self::spanKey(), $span)
            ->with(self::requestKey(), $request)
            ->with(self::forwardedKey(), false)
            ->with(self::rewritingKey(), \in_array($class, self::URL_REWRITING_CLIENTS, true));
    }
}
