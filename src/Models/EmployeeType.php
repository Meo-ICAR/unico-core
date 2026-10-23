<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Unico\Core\Concerns\UsesCoreConnection;

/**
 * Ruoli di dipendenti e collaboratori, a cui si associano i permessi. Ogni ruolo è anche l'ufficio che risponde delle attività
 * (codice, email, responsabile): chi lo ricopre lo indica in `employee_roles` di dipendenti ed esterni.
 */
class EmployeeType extends Model
{
    use UsesCoreConnection;

    protected $table = 'employee_types';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_external' => 'boolean',
        ];
    }

    /** Responsabile dell'ufficio: un dipendente o, per i ruoli esternalizzabili, un esterno. */
    public function manager(): MorphTo
    {
        return $this->morphTo();
    }

    public function employeeTypePermissions(): HasMany
    {
        return $this->hasMany(EmployeeTypePermission::class, 'employee_type_id');
    }

    public function employeeTypeResourcePresets(): HasMany
    {
        return $this->hasMany(EmployeeTypeResourcePreset::class, 'employee_type_id');
    }
}
