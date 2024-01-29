<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Events\RouteMatched;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SemConv\TraceAttributes;

class RequestWatcher extends Watcher
{
    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        $app['events']->listen(RouteMatched::class, static function (RouteMatched $event): void {
            /** @var SpanInterface|null $span */
            $span = $event->request->attributes->get(SpanInterface::class);

            if ($span) {
                $span->updateName("{$event->request->getMethod()} /" . ltrim($event->route->uri, '/'));
                $span->setAttribute(TraceAttributes::HTTP_ROUTE, $event->route->uri);
            }
        });
    }
}
