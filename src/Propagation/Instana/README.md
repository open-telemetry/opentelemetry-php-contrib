[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-propagator-instana/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Propagation/Instana)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-propagator-instana)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-propagation-instana/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-propagation-instana/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-propagation-instana/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-propagation-instana/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Instana Propagator

The OpenTelemetry Propagator for Instana provides HTTP header propagation and Baggage propagation for systems that are using IBM Observability by Instana.
This propagator translates the Instana trace correlation headers (`X-INSTANA-T/X-INSTANA-S/X-INSTANA-L`) into the OpenTelemetry `SpanContext`, and vice versa.
It does not handle `TraceState`.


## Installation

```sh
composer require open-telemetry/opentelemetry-propagation-instana
```

## Usage

```
$propagator = InstanaPropagator::getInstance();
```

Both of the above have extract and inject methods available to extract and inject respectively into the header.

For Baggage propagation, use opentelemetry's MultiTextMapPropagator, and pass the array list of propagators i.e Instana and Baggage propagator as below.

```
$propagator = new MultiTextMapPropagator([InstanaPropagator::getInstance(), BaggagePropagator::getInstance()]);
```

## Propagator Details

There are three headers that the propagator handles: `X-INSTANA-T` (the trace ID), `X-INSTANA-S` (the parent span ID), and `X-INSTANA-L` (the sampling level).

Example header triplet:

* `X-INSTANA-T: 80f198ee56343ba864fe8b2a57d3eff7`,
* `X-INSTANA-S: e457b5a2e4d86bd1`,
* `X-INSTANA-L: 1`.

A short summary for each of the headers is provided below. More details are available at <https://www.ibm.com/docs/en/obi/current?topic=monitoring-traces#tracing-headers>.

### X-INSTANA-T -- trace ID

* A string of either 16 or 32 characters from the alphabet `0-9a-f`, representing either a 64 bit or 128 bit ID.
* This header corresponds to the [OpenTelemetry TraceId](https://github.com/open-telemetry/opentelemetry-specification/blob/master/specification/overview.md#spancontext).
* If the propagator receives an X-INSTANA-T header value that is shorter than 32 characters when _extracting_ headers into the OpenTelemetry span context, it will left-pad the string with the character "0" to length 32.
* No length transformation is applied when _injecting_ the span context into headers.

### X-INSTANA-S -- parent span ID

* Format: A string of 16 characters from the alphabet `0-9a-f`, representing a 64 bit ID.
* This header corresponds to the [OpenTelemetry SpanId](https://github.com/open-telemetry/opentelemetry-specification/blob/master/specification/overview.md#spancontext).

### X-INSTANA-L - sampling level

* The only two valid values are `1` and `0`.
* A level of `1` means that this request is to be sampled, a level of `0` means that the request should not be sampled.
* This header corresponds to the sampling bit of the [OpenTelemetry TraceFlags](https://github.com/open-telemetry/opentelemetry-specification/blob/master/specification/overview.md#spancontext).

## Useful links

* For more information on Instana, visit <https://www.instana.com/> and [Instana' documentation](https://www.ibm.com/docs/en/obi/current).
* For more information on OpenTelemetry, visit: <https://opentelemetry.io/>

## Installing dependencies and executing tests

From Instana subdirectory:

``` sh
$ composer install
$ ./vendor/bin/phpunit tests
```
