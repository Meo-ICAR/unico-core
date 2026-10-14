<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Risorse/funzionalità applicative disponibili, raggruppabili per app e piano. */
class Resource extends Model
{
    use UsesCoreConnection;

    protected $table = 'resources';

    protected $guarded = ['id'];

    /** Solo le risorse dell'app corrente (`app_name` = nome dell'app). */
    public function scopeForCurrentApp(Builder $query): Builder
    {
        return $query->where('app_name', config('app.name'));
    }

    public function employeeTypePermissions(): HasMany
    {
        return $this->hasMany(EmployeeTypePermission::class, 'resource_id');
    }

    public function employeeTypeResourcePresets(): HasMany
    {
        return $this->hasMany(EmployeeTypeResourcePreset::class, 'resource_id');
    }
}
