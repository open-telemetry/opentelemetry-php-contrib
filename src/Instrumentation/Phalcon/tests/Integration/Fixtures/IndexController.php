<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Phalcon\Integration\Fixtures;

use Phalcon\Mvc\Controller;

final class IndexController extends Controller
{
    public function indexAction(): string
    {
        return 'ok';
    }

    public function failAction(): string
    {
        throw new \RuntimeException('boom');
    }

    /** Forwards to indexAction, simulating an app that re-dispatches from within an action. */
    public function forwardAction(): void
    {
        $this->dispatcher->forward([
            'controller' => 'index',
            'action' => 'index',
        ]);
    }
}
