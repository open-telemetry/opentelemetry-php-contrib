<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Symfony\Component\Console\Command\Command;

/**
 * Creates a default root span for console command execution, mirroring the
 * root span created by the HttpKernel instrumentation for HTTP requests.
 */
final class ConsoleInstrumentation
{
    const ATTRIBUTE_CONSOLE_EXIT_CODE = 'symfony.console.exit_code';

    /** @psalm-suppress PossiblyUnusedMethod */
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.symfony_console',
            null,
            Version::VERSION_1_32_0->url(),
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            Command::class,
            'run',
            pre: static function (
                Command $command,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                $name = $command->getName() ?? 'command';

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder($name)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

                $parent = Context::getCurrent();
                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: static function (
                Command $command,
                array $params,
                ?int $returnValue,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());

                if (null !== $exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                } elseif (null !== $returnValue && $returnValue !== Command::SUCCESS) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }

                if (null !== $returnValue) {
                    $span->setAttribute(self::ATTRIBUTE_CONSOLE_EXIT_CODE, $returnValue);
                }

                $span->end();
            }
        );
    }
}
