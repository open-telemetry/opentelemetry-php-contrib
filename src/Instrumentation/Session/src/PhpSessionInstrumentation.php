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
                self::end($exception, $function);
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

    private static function end(?Throwable $exception, string $function): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());
        
        // Add session-specific attributes in the post hook
        if ($function === 'session_start') {
            $isSessionActive = !empty(session_id());
            $span->setAttribute('php.session.id', session_id());
            $span->setAttribute('php.session.name', session_name());
            $span->setAttribute('php.session.status', $isSessionActive ? 'active' : 'inactive');

            // Add session cookie parameters
            $cookieParams = session_get_cookie_params();
            foreach ($cookieParams as $key => $value) {
                if (is_scalar($value)) {
                    $span->setAttribute("php.session.cookie.$key", '<redacted>');
                }
            }
        } elseif ($function === 'session_write_close') {
            $sessionId = session_id();
            $sessionName = session_name();
            
            // Add session information
            if (!empty($sessionId)) {
                $span->setAttribute('php.session.id', $sessionId);
                $span->setAttribute('php.session.name', $sessionName);
            }
            
        } elseif ($function === 'session_unset') {
            $sessionId = session_id();
            $sessionName = session_name();
            
            // Add session information
            if (!empty($sessionId)) {
                $span->setAttribute('php.session.id', $sessionId);
                $span->setAttribute('php.session.name', $sessionName);
            }
            
        } elseif ($function === 'session_abort') {
            $sessionId = session_id();
            $sessionName = session_name();
            
            // Add session information
            if (!empty($sessionId)) {
                $span->setAttribute('php.session.id', $sessionId);
                $span->setAttribute('php.session.name', $sessionName);
            }

        }
        if ($exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        } else {
            $span->setStatus(StatusCode::STATUS_OK);
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
                // No parameters to add for session_destroy
                break;
        }
    }
}
