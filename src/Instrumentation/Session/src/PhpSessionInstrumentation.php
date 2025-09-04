<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\PhpSession;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Throwable;

/** @psalm-suppress UnusedClass */
class PhpSessionInstrumentation
{
    public const NAME = 'php-session';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.session',
            null,
            Version::VERSION_1_36_0->url(),
        );

        self::_hook($instrumentation, null, 'session_start', 'session.start');
        self::_hook($instrumentation, null, 'session_destroy', 'session.destroy');
        self::_hook($instrumentation, null, 'session_write_close', 'session.write_close');
        self::_hook($instrumentation, null, 'session_unset', 'session.unset');
        self::_hook($instrumentation, null, 'session_abort', 'session.abort');
    }

    /**
     * Simple generic hook function which starts and ends a minimal span
     * @psalm-suppress UnusedFunctionCall
     */
    private static function _hook(CachedInstrumentation $instrumentation, ?string $class, string $function, string $name): void
    {
        hook(
            class: $class,
            function: $function,
            /** @psalm-suppress UnusedClosureParam */
            pre: static function ($object, ?array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $name) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, $name, $function);
                self::addParams($builder, $function, $params);
                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) use ($function) {
                self::end($exception, $function, $return);
            }
        );
    }

    private static function makeBuilder(
        CachedInstrumentation $instrumentation,
        string $name,
        string $function
    ): SpanBuilderInterface {
        /** @psalm-suppress ArgumentTypeCoercion */
        return $instrumentation->tracer()
            ->spanBuilder($name)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function);
    }

    private static function end(?Throwable $exception, string $function, mixed $return = null): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());

        switch ($function) {
            case 'session_start':
                // Use the return value to determine if session was successfully started
                $sessionStartSuccess = $return === true;
                $span->setAttribute('php.session.status', $sessionStartSuccess ? 'active' : 'inactive');

                // Add session cookie parameters
                $cookieKeys = array_keys(session_get_cookie_params());
                sort($cookieKeys);
                $span->setAttribute('php.session.cookie.keys', $cookieKeys);
                if (!$sessionStartSuccess) {
                    $span->setStatus(StatusCode::STATUS_ERROR, "$function failed with return code $return");
                }
                // no break
            case 'session_write_close':
            case 'session_unset':
            case 'session_abort':
                $sessionId =  session_id();
                // Add session information
                if (!empty($sessionId)) {
                    $span->setAttribute('php.session.id', $sessionId);
                    $span->setAttribute('php.session.name', session_name());
                }
                // no break
            case 'session_destroy':
                break;
        }
        
        // Set span status based on return value
        $Success = $return === true;
        if (!$Success) {
            $span->setStatus(StatusCode::STATUS_ERROR, "$function failed with return code $return");
        }

        $span->end();
    }

    private static function addParams(SpanBuilderInterface $builder, string $function, ?array $params): void
    {
        if ($params === null) {
            return;
        }
        
        switch ($function) {
            case 'session_start':
                if (isset($params[0]) && is_array($params[0])) {
                    foreach ($params[0] as $key => $value) {
                        if (is_scalar($value)) {
                            $builder->setAttribute("php.session.options.$key", $value);
                        }
                    }
                }

                break;
                
            case 'session_destroy':
                $sessionId =  session_id();
                // Add session information
                if (!empty($sessionId)) {
                    $builder->setAttribute('php.session.id', $sessionId);
                    $builder->setAttribute('php.session.name', session_name());
                }

                // No parameters to add for session_destroy
                break;
        }
    }
}
