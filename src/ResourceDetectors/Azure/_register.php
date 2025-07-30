<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Registry;

Registry::registerResourceDetector('azure_app_service', new OpenTelemetry\Contrib\Resource\Detector\Azure\AppService\Detector());
Registry::registerResourceDetector('azure_container_apps', new OpenTelemetry\Contrib\Resource\Detector\Azure\ContainerApps\Detector());
Registry::registerResourceDetector('azure_vm', new OpenTelemetry\Contrib\Resource\Detector\Azure\Vm\Detector());
