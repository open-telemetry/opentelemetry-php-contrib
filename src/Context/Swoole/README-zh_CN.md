[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/context-swoole/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Context/Swoole)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/context-swoole)
[![Latest Version](http://poser.pugx.org/open-telemetry/context-swoole/v/unstable)](https://packagist.org/packages/open-telemetry/context-swoole/)
[![Stable](http://poser.pugx.org/open-telemetry/context-swoole/v/stable)](https://packagist.org/packages/open-telemetry/context-swoole/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Swoole context

[简体中文](README-zh_CN.md) | [ENGLISH](README.md)

不同于传统的 php-fpm 运行时单个进程同一时刻处理一个请求，在 Swoole 中每个进程同时会处理多个 http 请求， 这个库解决在 Swoole 中使用 Opentelemetry 的上下文问题。

## Requirement:
* php >= 8.0
* swoole >= 4.0
* composer

## 安装

Install the package with composer:

```bash
composer require open-telemetry/context-swoole
```

请注意，该库需要与 OpenTelemetry 配合使用，例如 `open-telemetry/opentelemetry`.

## 使用

1. 服务启动阶段注册 `TracerProvider`:

```php
<?php

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Contrib\Context\Swoole\SwooleContextStorage;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

$transport = (new OtlpHttpTransportFactory())->create('http://collector:4318/v1/traces', 'application/json');
$exporter  = new SpanExporter($transport);

$spanProcessor = new SimpleSpanProcessor($exporter);
$tracerProvider = new TracerProvider($spanProcessor);

// 使用 Swoole 上下文存储器
Context::setStorage(new SwooleContextStorage(new ContextStorage()));

ShutdownHandler::register([$tracerProvider, 'shutdown']);

Globals::registerInitializer(
    fn (Configurator $configurator) => $configurator
        ->withTracerProvider($tracerProvider)
);
```

2. 在业务中(例如 onRequest 等事件回调中)执行链路生成

```php
<?php

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;

$tracer = Globals::tracerProvider()->getTracer('io.opentelemetry.contrib.swoole.php');

$root = $span = $tracer->spanBuilder('root')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
$scope = $span->activate();

for ($i = 0; $i < 3; $i++) {
    // start a span, register some events
    $span = $tracer->spanBuilder('loop-' . $i)->startSpan();

    $span->setAttribute('remote_ip', '1.2.3.4')
        ->setAttribute('country', 'USA');

    $span->addEvent('found_login' . $i, [
        'id' => $i,
        'username' => 'otuser' . $i,
    ]);
    $span->addEvent('generated_session', [
        'id' => md5((string) microtime(true)),
    ]);

    $span->end();
}
$root->end();
$scope->detach();
```
