<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Registry;

Registry::registerResourceDetector('azure_app_service', new OpenTelemetry\Azure\AppService\Detector());
Registry::registerResourceDetector('azure_container_apps', new OpenTelemetry\Azure\ContainerApps\Detector());
Registry::registerResourceDetector('azure_vm', new OpenTelemetry\Azure\Vm\Detector());
