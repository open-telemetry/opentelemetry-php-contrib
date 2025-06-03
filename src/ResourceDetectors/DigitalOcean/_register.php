<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Resource\Detector\DigitalOcean\DigitalOceanDetector;
use OpenTelemetry\SDK\Registry;

Registry::registerResourceDetector('digitalocean', new DigitalOceanDetector());
