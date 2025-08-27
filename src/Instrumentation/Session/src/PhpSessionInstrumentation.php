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
            Version::VERSION_1_32_0->url(),
        );

        self::_hook($instrumentation, null, 'session_start', 'session.start');
        self::_hook($instrumentation, null, 'session_destroy', 'session.destroy');
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
            pre: static function ($object, ?array $params, ?string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $name) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder($instrumentation, $name, $function, $filename, $lineno);
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
        string $function,
        ?string $filename,
        ?int $lineno
    ): SpanBuilderInterface {
        /** @psalm-suppress ArgumentTypeCoercion */
        return $instrumentation->tracer()
            ->spanBuilder($name)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
            ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);
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
            $span->setAttribute('session.id', session_id());
            $span->setAttribute('session.name', session_name());
            $isSessionActive = !empty(session_id());
            $span->setAttribute('session.status', $isSessionActive ? 'active' : 'inactive');

            // Add session cookie parameters
            $cookieParams = session_get_cookie_params();
            foreach ($cookieParams as $key => $value) {
                if (is_scalar($value)) {
                    $span->setAttribute("session.cookie.$key", $value);
                }
            }
        } elseif ($function === 'session_destroy') {
            $span->setAttribute('session.destroy.success', $exception === null);
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
                            $builder->setAttribute("session.options.$key", $value);
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
