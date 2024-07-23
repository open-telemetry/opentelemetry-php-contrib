<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\ExtRdKafka;

use Composer\InstalledVersions;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;

use OpenTelemetry\SemConv\TraceAttributeValues;

use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\ProducerTopic;

use Throwable;

class ExtRdKafkaInstrumentation
{
    public const NAME = 'ext_rdkafka';
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.ext_rdkafka',
            InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-ext-rdkafka'),
            'https://opentelemetry.io/schemas/1.25.0',
        );

        // Start root span and propagate parent if it exists in headers, for each message consumed
        self::addConsumeHooks($instrumentation);
        // End root span on offset commit
        self::addCommitHooks('commit');
        self::addCommitHooks('commitAsync');
        // Context propagation for outbound messages
        self::addProductionHooks($instrumentation);
    }

    private static function addCommitHooks($functionName)
    {
        hook(
            KafkaConsumer::class,
            $functionName,
            post: static function () {
                $scope = Context::storage()->scope();
                if ($scope === null) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                $span->end();
            }
        );
    }

    private static function addProductionHooks($instrumentation)
    {
        hook(
            ProducerTopic::class,
            'producev',
            pre: static function (
                ProducerTopic $exchange,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno
            ) use ($instrumentation) : array {
                /** @var CachedInstrumentation $instrumentation */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s %s', $exchange->getName(), TraceAttributeValues::MESSAGING_OPERATION_PUBLISH))
                    ->setSpanKind(SpanKind::KIND_PRODUCER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, TraceAttributeValues::MESSAGING_SYSTEM_KAFKA)
                    ->setAttribute(TraceAttributes::MESSAGING_OPERATION, TraceAttributeValues::MESSAGING_OPERATION_PUBLISH)
                ;

                $parent = Context::getCurrent();
                $propagator = Globals::propagator();

                $span = $builder
                    ->setParent($parent)
                    ->startSpan();

                $context = $span->storeInContext($parent);
                Context::storage()->attach($context);

                // Kafka message key
                if (array_key_exists(3, $params)) {
                    $span->setAttribute(TraceAttributes::MESSAGING_KAFKA_MESSAGE_KEY, $params[3]);
                }

                // Headers are the 5th argument for the producev function
                $carrier = [];
                $propagator->inject($carrier);
                $params[4] = isset($params[4]) ? array_merge($params[4], $carrier) : $carrier;

                return $params;
            },
            post: static function (
                ProducerTopic $exchange,
                array $params,
                $returnValue,
                ?Throwable $exception
            ) {
                $scope = Context::storage()->scope();
                if ($scope === null) {
                    return $returnValue;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());
                $span->end();

                return $returnValue;
            }
        );
    }

    private static function addConsumeHooks($instrumentation)
    {
        hook(
            KafkaConsumer::class,
            'consume',
            post: static function (
                ?KafkaConsumer $exchange,
                array $params,
                ?Message $message,
                ?Throwable $exception
            ) use ($instrumentation) : void {
                // This is to ensure that there is data. Packages periodically poll this method in order to
                // determine if there is a message there. If there is not, we don't want to create a span.
                if (!$message instanceof Message || $message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    return;
                }

                /** @var CachedInstrumentation $instrumentation */
                $builder = $instrumentation
                    ->tracer()
                    // @phan-suppress-next-line PhanTypeMismatchArgumentInternal - Doesn't seem to know this has to be a string
                    ->spanBuilder(sprintf('%s %s', $message->topic_name, TraceAttributeValues::MESSAGING_OPERATION_DELIVER))
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setAttribute(TraceAttributes::MESSAGING_SYSTEM, TraceAttributeValues::MESSAGING_SYSTEM_KAFKA)
                    ->setAttribute(TraceAttributes::MESSAGING_OPERATION, TraceAttributeValues::MESSAGING_OPERATION_DELIVER)
                    ->setAttribute(TraceAttributes::MESSAGING_KAFKA_MESSAGE_KEY, $message->key)
                    ->setAttribute(TraceAttributes::MESSAGING_KAFKA_MESSAGE_OFFSET, $message->offset)
                ;

                if (is_array($message->headers)) {
                    $parent = Globals::propagator()->extract($message->headers);
                    /**
                     * @phpstan-ignore-next-line
                     * - The stub for rdkakfa says headers always a string. It can infact be null. Need this
                     * to allow phpstan to pass.
                     */
                } else {
                    $parent = Context::getCurrent();
                }

                $builder->setParent($parent);
                $span = $builder->startSpan();

                $context = $span->storeInContext($parent);

                Context::storage()->attach($context);
            }
        );
    }
}
