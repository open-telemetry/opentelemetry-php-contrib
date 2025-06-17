<?php

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel;

use OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Integration\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Integration test for Eloquent\Model hooks
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
        // Setup database table for fixture model
        DB::statement('CREATE TABLE IF NOT EXISTS test_models(
                id BIGINTEGER,
                name VARCHAR(255),
                created_at DATETIME,
                updated_at DATETIME
            )
        ');
    }

    public function tearDown(): void
    {
        // Reset table
        DB::statement('DROP IF EXISTS test_models');
    }
}