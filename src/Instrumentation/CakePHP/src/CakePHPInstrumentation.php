<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\CakePHP;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Instrumentation\CakePHP\Hooks\Cake\Controller\Controller;
use OpenTelemetry\Contrib\Instrumentation\CakePHP\Hooks\Cake\Http\Server;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class CakePHPInstrumentation
{
    public const NAME = 'cakephp';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.cakephp');
        Server::hook($instrumentation);
        Controller::hook($instrumentation);
    }
}