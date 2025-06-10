[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-exporter-instana/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://www.ibm.com/support/pages/instana-support)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Exporter/Instana)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-exporter-instana)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-instana-exporter/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-exporter-instana/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-instana-exporter/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-exporter-instana/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# Instana OpenTelemetry PHP Exporter

Instana exporter for OpenTelemetry.

## Documentation

https://www.ibm.com/docs/en/instana-observability/current?topic=php-opentelemetry-exporter

## Installing via Composer

Install Composer in a common location or in your project

```bash
curl -s https://getcomposer.org/installer | php
```

Install via Composer

```bash
composer require open-telemetry/opentelemetry-exporter-instana
```

## Usage


Utilizing the OpenTelemetry PHP SDK, we can send spans natively to Instana, by providing an OpenTelemetry span processor our `SpanExporterInterface`.

This can be manually constructed, or created from the `SpanExporterFactory`. See the factory implementation for how to manually construct the `SpanExporter`. The factory reads from two environment variables which can be set according, else will fallback onto the following defaults

```bash
INSTANA_AGENT_HOST=127.0.0.1
INSTANA_AGENT_PORT=42699
```

The service name that is visible in the Instana UI can be configured with the following environment variables. OpenTelemetry provides `OTEL_SERVICE_NAME` (see documentation [here](https://opentelemetry.io/docs/languages/sdk-configuration/general/#otel_service_name)) as a way to customize this within the SDK. We also provide `INSTANA_SERVICE_NAME` which will be taken as the highest precedence.

```bash
export INSTANA_SERVICE_NAME=custom-service-name
```

## Example

```php
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

$tracerProvider = new TracerProvider(
    new SimpleSpanProcessor(
        Registry::spanExporterFactory("instana")->create()
    )
);
$tracer = $tracerProvider->getTracer('io.instana.opentelemetry.php');

$span = $tracer->spanBuilder('root')->startSpan();
$span->setAttribute('remote_ip', '1.2.3.4')
    ->setAttribute('country', 'CAN');
$span->addEvent('generated_session', [
    'id' => md5((string) microtime(true)),
]);
$span->end();

$tracerProvider->shutdown();
```

## Issues

This exporter is primarily maintained by contributors from IBM. Issues should be reported as part of standard [Instana product support](https://www.ibm.com/support/pages/instana-support).
