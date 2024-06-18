<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\CakePHP\Integration\App\src\Controller;

use Cake\Controller\Controller;
use Cake\View\JsonView;
use Psr\Http\Message\ResponseInterface;

/**
 * @psalm-suppress RedundantPropertyInitializationCheck
 */
class ArticleController extends Controller
{
    public function viewClasses(): array
    {
        return [JsonView::class];
    }

    public function index(): ResponseInterface
    {
        return $this->response;
    }
    public function update(): ResponseInterface
    {
        return $this->response;
    }

    public function exception()
    {
        throw new \Exception('kaboom');
    }

    public function view(): ResponseInterface
    {
        return $this->response;
    }
}