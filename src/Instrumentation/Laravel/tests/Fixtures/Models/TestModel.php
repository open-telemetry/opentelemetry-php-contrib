<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @psalm-suppress UnusedClass
 */
class TestModel extends Model
{
    protected $table = 'test_models';
    protected $fillable = ['name'];

    /**
     * @return HasMany<TestRelatedModel, $this>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function relatedModels(): HasMany
    {
        return $this->hasMany(TestRelatedModel::class);
    }

    /**
     * @return BelongsToMany<TestRelatedModel, $this, Pivot, 'pivot'>
     * @psalm-suppress PossiblyUnusedMethod, MissingTemplateParam
     */
    public function pivotRelatedModels(): BelongsToMany
    {
        return $this->belongsToMany(TestRelatedModel::class, 'test_model_test_related_model');
    }
}
