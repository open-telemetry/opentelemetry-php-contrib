<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Resource\Detector\Container\Container;
use OpenTelemetry\SDK\Registry;

Registry::registerResourceDetector('container', new Container());
