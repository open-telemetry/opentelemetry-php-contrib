[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-ext-amqp/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/ExtAmqp)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-ext-amqp)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-ext-amqp/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-ext-amqp/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-ext-amqp/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-ext-amqp/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry ext-amqp auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, and spans will automatically be created for the
following methods:
- `AMQPExchange::publish`
- `AMQPQueue::ack`
- `AMQPQueue::nack`
- `AMQPQueue::reject`

The instrumentation automatically creates a span for each of the above methods and injects the span context into the
message headers. A consumer *SHOULD* create a span for each message received, extract the span context and can decide
to assume the context for processing the message or start a new trace and use trace-links to link the producer with
the consumer.

### Example

```php
//Create and declare channel
$channel = new AMQPChannel($connection);

$routing_key = 'task_queue';

$callback_func = function(AMQPEnvelope $message, AMQPQueue $q) {
    $context = $propagator->extract($message->getHeaders(), ArrayAccessGetterSetter::getInstance());
    $tracer = Globals::tracerProvider()->getTracer('my.org.consumer');

    // Start a new span that assumes the context that was injected by the producer
    $span = $tracer
        ->spanBuilder('my_queue consume')
        ->setSpanKind(SpanKind::KIND_CONSUMER)
        ->setParent($context)
        ->startSpan();

    sleep(sleep(substr_count($message->getBody(), '.')));

    $q->ack($message->getDeliveryTag());

    $span->end();
};

try{
    $queue = new AMQPQueue($channel);
    $queue->setName($routing_key);
    $queue->setFlags(AMQP_DURABLE);
    $queue->declareQueue();

    $queue->consume($callback_func);
} catch(AMQPQueueException $ex){
    print_r($ex);
} catch(Exception $ex){
    print_r($ex);
}

$connection->disconnect();
```

Full Example: https://github.com/rabbitmq/rabbitmq-tutorials/blob/main/php-amqp/worker.php

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=ext_amqp
```
