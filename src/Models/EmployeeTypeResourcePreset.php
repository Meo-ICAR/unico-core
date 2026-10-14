<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Preset di accesso totale (tutte le azioni CRUD) per ruolo/risorsa, affiancati alla matrice granulare employee_type_permissions. */
class EmployeeTypeResourcePreset extends Model
{
    use UsesCoreConnection;

    protected $table = 'employee_type_resource_presets';

    protected $guarded = ['id'];

    public function employeeType(): BelongsTo
    {
        return $this->belongsTo(EmployeeType::class, 'employee_type_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resource_id');
    }
}
