<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Registry;

Registry::registerResourceDetector('apache', new OpenTelemetry\Contrib\Resource\Detector\Apache\Apache());
