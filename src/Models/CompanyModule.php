<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Moduli acquistati da ciascuna company, con piano e dati di fatturazione. */
class CompanyModule extends Model
{
    use UsesCoreConnection;

    protected $table = 'company_modules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'date',
            'one_time_cost' => 'decimal:2',
            'monthly_cost' => 'decimal:2',
            'last_invoice_at' => 'date',
            'last_payment_at' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_id');
    }
}
