<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Resource\Detector\DigitalOcean\DigitalOceanDetector;
use OpenTelemetry\SDK\Registry;

/** This should move to SPI loading instead of Composer files */
Registry::registerResourceDetector('digitalocean', new DigitalOceanDetector());
