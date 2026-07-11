<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Http\Client;

use Illuminate\Http\Client\PendingRequest as IlluminatePendingRequest;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHook;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\LaravelHookTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Propagators\RequestPropagationSetter;
use function OpenTelemetry\Instrumentation\hook;
use Psr\Http\Message\RequestInterface;

class PendingRequest implements LaravelHook
{
    use LaravelHookTrait;

    public function instrument(): void
    {
        $this->hookRunBeforeSendingCallbacks();
    }

    /**
     * `runBeforeSendingCallbacks()` is where Laravel finalizes the PSR-7 request just before
     * handing it to Guzzle, so injecting into its first argument here reaches the request that
     * is actually sent (the `RequestSending` event ClientRequestWatcher listens on can't do this
     * itself, since it only receives an immutable copy that never reaches the real request).
     *
     * Gated behind an opt-in flag: the target of an outbound HTTP client request may be a
     * third-party service outside the application's control, which should not receive internal
     * trace (and, in future, baggage) data unless the developer explicitly asks for it.
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    protected function hookRunBeforeSendingCallbacks(): bool
    {
        return hook(
            IlluminatePendingRequest::class,
            'runBeforeSendingCallbacks',
            pre: function (IlluminatePendingRequest $_pendingRequest, array $params) {
                if (!LaravelInstrumentation::shouldPropagateHttpClientTraceContext()) {
                    return $params;
                }

                $request = $params[0];
                if (!$request instanceof RequestInterface) {
                    return $params;
                }

                TraceContextPropagator::getInstance()->inject($request, RequestPropagationSetter::instance(), Context::getCurrent());
                $params[0] = $request;

                return $params;
            },
        );
    }
}
