<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Appartenenza di un dipendente o di un consulente esterno a una funzione aziendale, con il ruolo che ricopre. */
class BusinessFunctionMember extends MorphPivot
{
    use UsesCoreConnection;

    /** La tabella ha un id proprio: senza, gli aggiornamenti del pivot cercherebbero le chiavi esterne. */
    public $incrementing = true;

    protected $table = 'business_function_members';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_manager' => 'boolean'];
    }

    public function businessFunction(): BelongsTo
    {
        return $this->belongsTo(BusinessFunction::class, 'business_function_id');
    }

    public function member(): MorphTo
    {
        return $this->morphTo();
    }

    public function employeeType(): BelongsTo
    {
        return $this->belongsTo(EmployeeType::class, 'employee_type_id');
    }
}
