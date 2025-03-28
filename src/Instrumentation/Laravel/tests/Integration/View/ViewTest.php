<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\View;

use Exception;
use Illuminate\Routing\Router;
use Illuminate\View\ViewException;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

/** @psalm-suppress UnusedClass */
class ViewTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Configure Laravel to find views in our test resources directory
        $this->app['view']->addLocation(__DIR__ . '/../../resources/views');
    }

    public function test_it_records_view_rendering(): void
    {
        // Create a test view
        $view = view('test-view', ['text' => 'Hello World']);

        // Render the view
        $this->storage->exchangeArray([]);
        $content = $view->render();

        // Assert the view was rendered
        $this->assertEquals('Hello World', $content);

        // Assert trace structure
        $this->assertTraceStructure([
            [
                'name' => 'laravel.view.render',
                'attributes' => [
                    'code.function.name' => 'render',
                    'code.namespace' => 'Illuminate\View\View',
                    'view.name' => 'test-view',
                ],
                'kind' => SpanKind::KIND_INTERNAL,
            ],
        ]);
    }

    public function test_it_records_view_rendering_with_exception(): void
    {
        // Create a view that will throw an exception during rendering
        $view = view('test-view-exception');

        // Attempt to render the view
        $this->storage->exchangeArray([]);

        try {
            $view->render();
            $this->fail('Expected ViewException was not thrown');
        } catch (ViewException $e) {
            // Expected exception
            $this->assertEquals('View rendering failed (View: /usr/src/myapp/src/Instrumentation/Laravel/tests/resources/views/test-view-exception.blade.php)', $e->getMessage());
        }

        // Assert trace structure
        $this->assertTraceStructure([
            [
                'name' => 'laravel.view.render',
                'attributes' => [
                    'code.function.name' => 'render',
                    'code.namespace' => 'Illuminate\View\View',
                    'view.name' => 'test-view-exception',
                ],
                'kind' => SpanKind::KIND_INTERNAL,
                'status' => [
                    'code' => 'error',
                ],
                'events' => [
                    [
                        'name' => 'exception',
                        'attributes' => [
                            'exception.message' => 'View rendering failed (View: /usr/src/myapp/src/Instrumentation/Laravel/tests/resources/views/test-view-exception.blade.php)',
                            'exception.type' => ViewException::class,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_it_records_view_rendering_in_request_context(): void
    {
        // Define a route that renders a view
        $this->router()->get('/view-test', function () {
            return view('test-view', ['text' => 'Hello World']);
        });

        // Make a request to the route
        $this->storage->exchangeArray([]);
        $response = $this->call('GET', '/view-test');

        // Assert response
        $this->assertEquals(200, $response->status());
        $this->assertEquals('Hello World', $response->getContent());

        // Assert trace structure
        $this->assertTraceStructure([
            [
                'name' => 'GET /view-test',
                'attributes' => [
                    'code.function.name' => 'handle',
                    'code.namespace' => 'Illuminate\Foundation\Http\Kernel',
                    'url.full' => 'http://localhost/view-test',
                    'http.request.method' => 'GET',
                    'url.scheme' => 'http',
                    'network.protocol.version' => '1.1',
                    'network.peer.address' => '127.0.0.1',
                    'url.path' => 'view-test',
                    'server.address' => 'localhost',
                    'server.port' => 80,
                    'user_agent.original' => 'Symfony',
                    'http.route' => 'view-test',
                    'http.response.status_code' => 200,
                ],
                'kind' => SpanKind::KIND_SERVER,
                'children' => [
                    [
                        'name' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize::handle',
                        'attributes' => [
                            'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ValidatePostSize',
                            'http.response.status_code' => 200,
                        ],
                        'kind' => SpanKind::KIND_INTERNAL,
                        'children' => [
                            [
                                'name' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::handle',
                                'attributes' => [
                                    'laravel.middleware.class' => 'Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull',
                                    'http.response.status_code' => 200,
                                ],
                                'kind' => SpanKind::KIND_INTERNAL,
                                'children' => [
                                    [
                                        'name' => 'laravel.view.render',
                                        'attributes' => [
                                            'code.function.name' => 'render',
                                            'code.namespace' => 'Illuminate\View\View',
                                            'view.name' => 'test-view',
                                        ],
                                        'kind' => SpanKind::KIND_INTERNAL,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function router(): Router
    {
        /** @psalm-suppress PossiblyNullReference */
        return $this->app->make(Router::class);
    }
}
