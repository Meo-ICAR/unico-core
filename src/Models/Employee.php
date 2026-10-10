<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Anagrafica dipendenti e collaboratori commerciali con ruoli privacy, abilitazioni OAM/IVASS e gerarchie. */
class Employee extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'employees';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'oam_at' => 'date',
            'oam_dismissed_at' => 'date',
            'hiring_date' => 'date',
            'termination_date' => 'date',
            'employee_roles' => 'array',
            'is_structure' => 'boolean',
            'is_external' => 'boolean',
            'is_ghost' => 'boolean',
        ];
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function coordinatedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'coordinated_by_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function blacklistClientiEmployees(): HasMany
    {
        return $this->hasMany(BlacklistClienteEmployee::class, 'employee_id');
    }
}
