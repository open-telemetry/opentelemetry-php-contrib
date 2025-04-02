<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @psalm-suppress UnusedClass
 */
class TestModel extends Model
{
    protected $table = 'test_models';
    protected $fillable = ['name'];
}
