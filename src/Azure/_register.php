<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Registry;

$client = new \GuzzleHttp\Client();
$requestFactory = new \GuzzleHttp\Psr7\HttpFactory();

Registry::registerResourceDetector('azure_app_service', new OpenTelemetry\Azure\AppService\Detector());
Registry::registerResourceDetector('azure_container_apps', new OpenTelemetry\Azure\ContainerApps\Detector());
Registry::registerResourceDetector('azure_vm', new OpenTelemetry\Azure\Vm\Detector($client, $requestFactory));
