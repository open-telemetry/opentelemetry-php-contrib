[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/context-swoole/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Context/Swoole)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/context-swoole)
[![Latest Version](http://poser.pugx.org/open-telemetry/context-swoole/v/unstable)](https://packagist.org/packages/open-telemetry/context-swoole/)
[![Stable](http://poser.pugx.org/open-telemetry/context-swoole/v/stable)](https://packagist.org/packages/open-telemetry/context-swoole/)

This is a read-only subtree split of <https://github.com/open-telemetry/opentelemetry-php-contrib>.

# OpenTelemetry Swoole context

[简体中文](README-zh_CN.md) | [ENGLISH](README.md)

不同于传统的 php-fpm 运行时单个进程同一时刻只处理一个请求，在 Swoole 中每个进程同时会处理多个 http 请求，这个库解决在 Swoole 中使用 Opentelemetry 的上下文切换问题。

## Requirement

* php >= 8.0
* swoole >= 4.5

## 安装

Install the package with composer:

```bash
composer require open-telemetry/context-swoole
```

请注意，该库需要与 OpenTelemetry 配合使用，例如 `open-telemetry/opentelemetry`.

## 使用

基于 docker 快速启动 jaeger

```bash
docker run -d --name jaeger \
  -p 16686:16686 \
  -p 4318:4318 \
  -p 4317:4317 \
  jaegertracing/all-in-one
```

注册 `TracerProvider` 并启动 Swoole http server:

```php
<?php

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Contrib\Context\Swoole\SwooleContextStorage;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Swoole\Http\Server;

require __DIR__ . '/vendor/autoload.php';

// Create a tracer provider with the exporter and processor
$transport = (new OtlpHttpTransportFactory())->create('http://127.0.0.1:4318/v1/traces', 'application/json');
$exporter = new SpanExporter($transport);
$spanProcessor = new SimpleSpanProcessor($exporter);
$tracerProvider = new TracerProvider($spanProcessor);

// Use Swoole context storage
Context::setStorage(new SwooleContextStorage(new ContextStorage()));

// Register the tracer provider
Globals::registerInitializer(fn(Configurator $configurator) => $configurator->withTracerProvider($tracerProvider));

// Create a Swoole HTTP server, which will start on local port 9501
$http = new Server('127.0.0.1', 9501);

// Http request callback
$http->on('request', function ($request, $response) {
    $tracer = Globals::tracerProvider()->getTracer('io.opentelemetry.contrib.swoole.php');

    try {
        $root = $tracer->spanBuilder($request->server['request_uri'])
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $scope = $root->activate();

        for ($i = 0; $i < 3; $i++) {
            // start a span, register some events
            $span = $tracer->spanBuilder('loop-' . $i)->startSpan();

            $span
                ->setAttribute('remote_ip', '1.2.3.4')
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
    } finally {
        $root->end();
        $scope->detach();
    }

    $response->header('Content-Type', 'text/plain');
    $response->end('Hello Swoole Context');
});

// Start server
$http->start();
```

使用以下命令进行访问测试:

```bash
curl -i 127.0.0.1:9501/swoole-context-demo
```

最后打开浏览器访问 jaeger UI: <http://127.0.0.1:16686/> 查询链路信息。
