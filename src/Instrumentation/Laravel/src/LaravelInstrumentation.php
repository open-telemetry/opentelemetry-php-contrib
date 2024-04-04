<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\ServeCommand;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\InstrumentationInterface;
use OpenTelemetry\API\Instrumentation\InstrumentationTrait;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\CacheWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ClientRequestWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\ExceptionWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\LogWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\QueryWatcher;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers\RequestWatcher;
use function OpenTelemetry\Instrumentation\hook;
use Throwable;

class LaravelInstrumentation implements InstrumentationInterface
{
    use InstrumentationTrait;

    public function getName(): string
    {
        return 'io.opentelemetry.contrib.php.laravel';
    }

    public function getVersion(): ?string
    {
        return InstalledVersions::getVersion('open-telemetry/opentelemetry-auto-laravel');
    }

    public function getSchemaUrl(): ?string
    {
        return null;
    }

    public function init(): bool
    {
        $this->setPropagator(Globals::propagator());
        $this->setTracerProvider(Globals::tracerProvider());
        $this->setMeterProvider(Globals::meterProvider());

        return true;
    }

    public function activate(): bool
    {
        if (!$this->init()) {
            return false;
        }

        try {
            hook(
                Application::class,
                '__construct',
                post: function (Application $application, array $params, mixed $returnValue, ?Throwable $exception) {
                    (new CacheWatcher())->register($application);
                    (new ClientRequestWatcher($this))->register($application);
                    (new ExceptionWatcher())->register($application);
                    (new LogWatcher())->register($application);
                    (new QueryWatcher($this))->register($application);
                    (new RequestWatcher())->register($application);

                    ConsoleInstrumentation::register($this);
                    HttpInstrumentation::register($this);

                    $this->developmentInstrumentation();
                },
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function developmentInstrumentation(): void
    {
        // Allow instrumentation when using the local PHP development server.
        if (class_exists(ServeCommand::class) && property_exists(ServeCommand::class, 'passthroughVariables')) {
            hook(
                ServeCommand::class,
                'handle',
                pre: static function (ServeCommand $serveCommand, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
                    foreach ($_ENV as $key => $value) {
                        if (str_starts_with($key, 'OTEL_') && !in_array($key, ServeCommand::$passthroughVariables)) {
                            ServeCommand::$passthroughVariables[] = $key;
                        }
                    }
                },
            );
        }
    }
}
