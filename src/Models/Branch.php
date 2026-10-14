<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Filiali o sedi collegate alle aziende o polimorficamente ad altri modelli (Hotel, Call Center, ecc.). */
class Branch extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'branches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_main_office' => 'boolean',
            'is_active' => 'boolean',
            'founded_at' => 'date',
            'dismissed_at' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'branch_id');
    }

    public function fornitoris(): HasMany
    {
        return $this->hasMany(Fornitore::class, 'branch_id');
    }

    public function branchable(): MorphTo
    {
        return $this->morphTo();
    }
}
