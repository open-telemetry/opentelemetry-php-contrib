<?php

declare(strict_types=1);

namespace OpenTelemetry\Symfony\OtelInstrumentationBundle\EventSubscriber;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\Tracer;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

class TracingKernelSubscriber implements EventSubscriberInterface
{
    private Tracer $tracer;
    private ?SpanInterface $mainSpan = null;

    public function __construct(Tracer $tracer, TracerProvider $tracerProvider)
    {
        $this->tracer = $tracer;
    }

    public function onTerminateEvent(TerminateEvent $event): void
    {
        if ($this->mainSpan === null) {
            return;
        }

        $this->mainSpan->end();
    }

    /**
     * Start a new span, whenever
     *
     * @param RequestEvent $requestEvent
     * @return void
     */
    public function onKernelRequestEvent(RequestEvent $requestEvent): void
    {
        if ($requestEvent->isMainRequest() === false) {
            return;
        }

        $request = $requestEvent->getRequest();

        $context = TraceContextPropagator::getInstance()->extract($request->headers->all());

        // Create our main span and activate it
        $this->mainSpan = $this->tracer->spanBuilder(sprintf('%s %s', $request->getMethod(), $request->getPathInfo()))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($context)
            ->setAttribute(TraceAttributes::HTTP_METHOD, $request->getMethod())
            ->setAttribute(TraceAttributes::HTTP_URL, $request->getUri())
            ->setAttribute(TraceAttributes::HTTP_TARGET, $request->getPathInfo())
            ->setAttribute(TraceAttributes::HTTP_HOST, $request->getHost())
            ->setAttribute(TraceAttributes::HTTP_SCHEME, $request->getScheme())
            ->setAttribute(TraceAttributes::NET_PEER_IP, $request->getClientIp())
            ->startSpan();

        $this->mainSpan->activate();
    }

    public function onExceptionEvent(ExceptionEvent $event): void
    {
        $this->mainSpan->recordException($event->getThrowable());
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => [
                ['onExceptionEvent', 10000]
            ],
            KernelEvents::REQUEST => 'onKernelRequestEvent',
            KernelEvents::CONTROLLER => [
                ['onControllerEvent', 10000]
            ],
            KernelEvents::TERMINATE => [
                ['onTerminateEvent', -10000]
            ],
        ];
    }
}
