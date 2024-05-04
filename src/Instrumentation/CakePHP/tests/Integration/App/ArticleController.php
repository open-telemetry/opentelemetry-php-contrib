<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\CakePHP\Integration\App;

use Cake\Controller\Controller;

/**
 * @psalm-suppress RedundantPropertyInitializationCheck
 */
class ArticleController extends Controller
{
    public function index(): void
    {
    }
    public function update(): void
    {
    }
}
