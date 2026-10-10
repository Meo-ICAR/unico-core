<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Tipi di dipendente e collaboratore, a cui si associano i permessi. */
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

    public function employeeTypePermissions(): HasMany
    {
        return $this->hasMany(EmployeeTypePermission::class, 'employee_type_id');
    }

    public function employeeTypeResourcePresets(): HasMany
    {
        return $this->hasMany(EmployeeTypeResourcePreset::class, 'employee_type_id');
    }
}
