<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Symfony\OtelBundle\Console;

use function get_class;
use OpenTelemetry\API\Trace\AbstractSpan as Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Symfony\OtelBundle\OtelBundle;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ConsoleListener implements EventSubscriberInterface
{
    private TracerInterface $tracer;

    public function __construct(
        TracerProviderInterface $tracerProvider
    ) {
        $this->tracer = $tracerProvider->getTracer(
            OtelBundle::instrumentationName(),
            OtelBundle::instrumentationVersion(),
            TraceAttributes::SCHEMA_URL,
        );
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => [
                ['startCommand', 10000],
            ],
            ConsoleEvents::ERROR => [
                ['recordException', -10000],
            ],
            ConsoleEvents::TERMINATE => [
                ['terminateCommand', -10000],
            ],
        ];
    }

    public function startCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        /** @var non-empty-string|null $name */
        $name = $command
            ? $command->getName()
            : null;
        $class = $command
            ? get_class($command)
            : null;

        $span = $this->tracer
            ->spanBuilder($name ?? 'command')
            ->setAttribute(TraceAttributes::CODE_FUNCTION, 'run')
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->startSpan();

        Context::storage()->attach($span->storeInContext(Context::getCurrent()));
    }

    public function recordException(ConsoleErrorEvent $event): void
    {
        $span = Span::getCurrent();
        $span->recordException($event->getError());
    }

    public function terminateCommand(ConsoleTerminateEvent $event): void
    {
        if (!$scope = Context::storage()->scope()) {
            return;
        }

        $scope->detach();
        $span = Span::fromContext($scope->context());
        $span->setAttribute('symfony.console.exit_code', $event->getExitCode());
        if ($event->getExitCode()) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }

        $span->end();
    }
}
