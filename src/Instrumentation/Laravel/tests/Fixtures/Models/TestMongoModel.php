<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * @psalm-suppress UnusedClass
 */
class TestMongoModel extends Model
{
    protected $connection = 'mongodb';
    protected $table = 'test_models_mongo';
    protected $fillable = ['name'];
}
