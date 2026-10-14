<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Permessi assegnati a ciascuna tipologia di dipendente sulle risorse applicative. */
class EmployeeTypePermission extends Model
{
    use UsesCoreConnection;

    protected $table = 'employee_type_permissions';

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
