<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Exporter\Instana\SpanExporterFactory;
use OpenTelemetry\SDK\Registry;

Registry::registerSpanExporterFactory('instana', SpanExporterFactory::class);
