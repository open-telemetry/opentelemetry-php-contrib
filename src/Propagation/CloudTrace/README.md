[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-propagator-cloudtrace/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Propagation/CloudTrace)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-propagator-cloudtrace)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-propagator-cloudtrace/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-propagation-cloudtrace/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-propagator-cloudtrace/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-propagation-cloudtrace/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry CloudTrace Propagator

CloudTrace is a propagator that supports the specification for the header "x-cloud-trace-context" used for trace context propagation across
service boundaries. (https://cloud.google.com/trace/docs/setup#force-trace). OpenTelemetry PHP CloudTrace Propagator Extension provides
option to use it bi-directionally or one-way. One-way does not inject the header for downstream consumption, it only processes the incoming headers
and returns the correct span context. It only attaches to existing X-Cloud-Trace-Context traces and does not create downstream ones.

## Installation

```sh
composer require open-telemetry/opentelemetry-propagation-cloudtrace
```

## Usage

For one-way CloudTrace:

```
$propagator = CloudTracePropagator::getOneWayInstance();
```

For bi-directional CloudTrace:

```
$propagator = CloudTracePropagator::getInstance();
```
