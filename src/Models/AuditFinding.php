<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Registro dei rilievi (anomalie/non conformità) emersi durante gli audit. */
class AuditFinding extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'audit_findings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requires_investigation' => 'boolean',
            'investigation_deadline' => 'date',
            'requires_corrective_action' => 'boolean',
            'corrective_action_deadline' => 'date',
            'resolved_at' => 'date',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class, 'audit_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function remediation(): BelongsTo
    {
        return $this->belongsTo(Remediation::class, 'remediation_id');
    }
}
