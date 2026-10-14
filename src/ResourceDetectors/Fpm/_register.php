<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Registry;

Registry::registerResourceDetector('fpm', new OpenTelemetry\Contrib\Resource\Detector\Fpm\Fpm());
