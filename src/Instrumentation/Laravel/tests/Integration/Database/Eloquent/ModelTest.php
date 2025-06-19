<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\Database\Eloquent;

use Illuminate\Support\Facades\DB;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel;
use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;

/**
 * Integration test for Eloquent\Model hooks
 * @psalm-suppress UnusedClass
 */
class ModelTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    public function setUp(): void
    {
        parent::setUp();

        // Setup database table for fixture model
        DB::statement('CREATE TABLE IF NOT EXISTS test_models(
                id BIGINT,
                name VARCHAR(255),
                created_at DATETIME,
                updated_at DATETIME
            )
        ');
    }

    /**
     * @return \OpenTelemetry\SDK\Trace\ImmutableSpan[]
     */
    private function filterOnlyEloquentSpans(): array
    {
        // SQL spans be mixed up in storage because \Illuminate\Support\Facades\DB called.
        // So, filtering only spans has attribute named 'laravel.eloquent.operation'.
        return array_values(
            array_filter(
                iterator_to_array($this->storage),
                fn ($span) =>
                    $span instanceof \OpenTelemetry\SDK\Trace\ImmutableSpan &&
                    $span->getAttributes()->has('laravel.eloquent.operation')
            )
        );
    }

    public function test_create(): void
    {
        TestModel::create(['id' => 1, 'name' => 'test']);

        $spans = $this->filterOnlyEloquentSpans();

        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::create', $span->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $span->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $span->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('create', $span->getAttributes()->get('laravel.eloquent.operation'));
    }

    public function test_find(): void
    {
        TestModel::find(1);

        // spans contains 2 eloquent spans for 'find' and 'get', because it method internally calls 'getModels' method.
        // So, filtering span only find span.
        $spans = array_values(
            array_filter(
                $this->filterOnlyEloquentSpans(),
                fn ($span) => $span->getAttributes()->get('laravel.eloquent.operation') === 'find'
            )
        );

        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::find', $span->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $span->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $span->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('find', $span->getAttributes()->get('laravel.eloquent.operation'));

    }

    public function test_perform_insert(): void
    {
        // Illuminate\Database\Eloquent\Model::performInsert is called from Illuminate\Database\Eloquent\Model::save.
        // Mark as exists = false required, because performUpdate called if exists = true.
        $model = (new TestModel())->newInstance(['id' => 1, 'name' => 'test'], false);
        $model->save();
        
        $spans = $this->filterOnlyEloquentSpans();
        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::create', $span->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $span->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $span->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('create', $span->getAttributes()->get('laravel.eloquent.operation'));
    }

    public function test_perform_update(): void
    {
        // Illuminate\Database\Eloquent\Model::performInsert is called from Illuminate\Database\Eloquent\Model::save.
        // Mark as exists = true required, because performInsert called if exists = false.
        $model = (new TestModel())->newInstance(['id' => 1, 'name' => 'test'], true);
        $model->save();

        $spans = $this->filterOnlyEloquentSpans();
        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::update', $span->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $span->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $span->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('update', $span->getAttributes()->get('laravel.eloquent.operation'));
    }

    public function test_delete(): void
    {
        $model = new TestModel();
        $model->delete(); // no effect

        $spans = $this->filterOnlyEloquentSpans();
        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::delete', $span->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $span->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $span->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('delete', $span->getAttributes()->get('laravel.eloquent.operation'));
    }

    public function test_get_models(): void
    {
        TestModel::get();

        $spans = $this->filterOnlyEloquentSpans();
        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::get', $span->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $span->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $span->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('get', $span->getAttributes()->get('laravel.eloquent.operation'));
    }

    public function test_destory(): void
    {
        TestModel::destroy([1]);

        // spans contains 2 eloquent spans for 'destroy' and 'get', because it method internally calls 'getModels' method.
        // So, filtering span only 'destroy' span.
        $spans = array_values(
            array_filter(
                $this->filterOnlyEloquentSpans(),
                fn ($span) => $span->getAttributes()->get('laravel.eloquent.operation') === 'destroy'
            )
        );

        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::destroy', $span->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $span->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $span->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('destroy', $span->getAttributes()->get('laravel.eloquent.operation'));
    }

    public function test_refresh(): void
    {
        $model = new TestModel();
        $model->refresh(); // no effect

        $spans = $this->filterOnlyEloquentSpans();
        $this->assertCount(1, $spans);

        $span = $spans[0];
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel::refresh', $span->getName());
        $this->assertSame('OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models\TestModel', $span->getAttributes()->get('laravel.eloquent.model'));
        $this->assertSame('test_models', $span->getAttributes()->get('laravel.eloquent.table'));
        $this->assertSame('refresh', $span->getAttributes()->get('laravel.eloquent.operation'));
    }
}
