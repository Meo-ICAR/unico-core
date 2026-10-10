<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Funzioni aziendali e Audit previsti nel periodo. */
class CompanyRole extends Model
{
    use UsesCoreConnection;

    protected $table = 'company_roles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_external' => 'boolean',
            'dal' => 'date',
            'al' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
