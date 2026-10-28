<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Twig;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use Twig\Environment;
use Twig\Error\Error as TwigError;

class TwigInstrumentation
{
    public const NAME = 'twig';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.twig',
            null,
            'https://opentelemetry.io/schemas/1.24.0'
        );

        // Hook Environment::render
        hook(
            Environment::class,
            'render',
            pre: static function (
                Environment $twig,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                $templateName = self::extractTemplateName($params[0] ?? null);

                $parent = Context::getCurrent();
                $span = $instrumentation->tracer()
                    ->spanBuilder(sprintf('twig.render %s', $templateName))
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute('twig.template.name', $templateName)
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->startSpan();
                self::addTemplateAttributes($span, $twig, $templateName);

                if (isset($params[1]) && is_array($params[1])) {
                    $span->setAttribute('twig.context.keys', count($params[1]));
                }

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);

                return $params;
            },
            post: static function (
                Environment $twig,
                array $params,
                mixed $returnValue,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                $span = Span::fromContext($scope->context());

                if ($exception instanceof TwigError) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                } elseif ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
                $scope->detach();
            }
        );

        // Hook Environment::load
        hook(
            Environment::class,
            'load',
            pre: static function (
                Environment $twig,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                $templateName = self::extractTemplateName($params[0] ?? null);

                $parent = Context::getCurrent();
                $span = $instrumentation->tracer()
                    ->spanBuilder(sprintf('twig.load %s', $templateName))
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute('twig.template.name', $templateName)
                    ->setAttribute('code.function', $function)
                    ->setAttribute('code.namespace', $class)
                    ->startSpan();
                self::addTemplateAttributes($span, $twig, $templateName);

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);

                return $params;
            },
            post: static function (
                Environment $twig,
                array $params,
                mixed $returnValue,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                $span = Span::fromContext($scope->context());

                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
                $scope->detach();
            }
        );
    }

    private static function extractTemplateName(mixed $name): string
    {
        if (is_string($name)) {
            return $name;
        }

        if ($name === null) {
            return '(null)';
        }

        if (is_array($name)) {
            if (isset($name[0]) && is_string($name[0])) {
                return $name[0];
            }

            foreach ($name as $item) {
                if (is_string($item)) {
                    return $item;
                }
            }

            return '(array[' . count($name) . '])';
        }

        if (is_object($name) && method_exists($name, '__toString')) {
            return (string) $name;
        }

        return '(' . get_debug_type($name) . ')';
    }

    private static function addTemplateAttributes(Span $span, Environment $twig, string $templateName): void
    {
        $span->setAttribute('twig.template.name', $templateName);

        try {
            $loader = $twig->getLoader();
            $source = $loader->getSourceContext($templateName);
            $path = $source->getPath();
            if (!empty($path)) {
                $span->setAttribute('code.filepath', $path);
            }
        } catch (\Throwable) {
            // Ignore - loader might not support getSourceContext
        }
    }
}
