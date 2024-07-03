This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry ext-rdkafka auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview

Auto-instrumentation hooks are registered via composer. There will be a new span created for each message that is
consumed. The goal of this instrumentation is to allow distributed traces to happen across Kafka in PHP. This means that
the following should happen:

- A span should be created for each message that is consumed
- The span should be created with the correct parent context- should the inbound message contain a traceparent header
- The span should be ended when the message offset is `committed`
- Any messages produced should have the traceparent header injected into the message headers

This is done by hooking into three methods:

- `\RdKafka\KafkaConsumer::consume`- For span creation and context propagation on inbound messages.
- `\RdKafka\ProducerTopic::producev`- For injecting the traceparent header into the message headers of produced
  messages.
    - Note there is an old method called `produce`. This does not support headers, and thus there was no point hooking
      into this
- `\RdKafka\KafkaConsumer::commit` / `\RdKafka\KafkaConsumer::commitAsync` - For ending the span when the message offset
  is committed.

## Versions

* Tested on PHP 8.2 and 8.3 with success

## Configuration

The extension can be disabled
via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=ext-rdkafka
```
