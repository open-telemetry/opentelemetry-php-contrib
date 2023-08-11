<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Foundation\Application;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;

class CommandWatcher extends Watcher
{
    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        $app['events']->listen(CommandFinished::class, [$this, 'recordCommandFinished']);
    }

    public function recordCommandFinished(CommandFinished $command): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $span = Span::fromContext($scope->context());
        $span->addEvent('command finished', [
            'command' => $command->command,
            'exit-code' => $command->exitCode,
        ]);
    }
}
