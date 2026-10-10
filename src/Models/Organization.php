<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Organismi di vigilanza e controllo a cui è sottoposto il mediatore creditizio (es. OAM, Banca d'Italia). */
class Organization extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'organizations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class, 'organization_id');
    }
}
