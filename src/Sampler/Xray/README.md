# AWS X-Ray Sampler

Provides a sampler which can get sampling configurations from AWS X-Ray to make sampling decisions. See: [AWS X-Ray Sampling](https://docs.aws.amazon.com/xray/latest/devguide/xray-concepts.html#xray-concepts-sampling)

## Installation

```shell
composer require open-telemetry/sampler-aws-xray
```

## Configuration
You can configure the `AWSXRayRemoteSampler` as per the following example.
Note that you will need to configure your [OpenTelemetry Collector for
X-Ray remote sampling](https://aws-otel.github.io/docs/getting-started/remote-sampling).

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\Contrib\Sampler\Xray\AWSXRayRemoteSampler;

$resource = ResourceInfo::create(Attributes::create([
    'service.name'   => 'MyServiceName',
    'service.version'=> '1.0.0',
    'cloud.provider' => 'aws',
]));

$xraySampler = new AWSXRayRemoteSampler(
    $resource,
    'http://localhost:2000',
    2
);

$tracerProvider = TracerProvider::builder()
    ->setResource($resource)
    ->setSampler($xraySampler)
    ->addSpanProcessor(
        new SimpleSpanProcessor(
            (new ConsoleSpanExporterFactory())->create()
        )
    )
    ->build();
```
