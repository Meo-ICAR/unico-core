<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Registro dei controlli (audit) eseguiti sui collaboratori/impiegati, richiesti da enti interni o esterni. */
class Audit extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'audits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'date',
            'executed_at' => 'date',
            'followup_date' => 'date',
            'followup_checked' => 'date',
            'rilievi_codes' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function qualityReview(): BelongsTo
    {
        return $this->belongsTo(QualityReview::class, 'quality_review_id');
    }

    public function auditChecklistEvaluations(): HasMany
    {
        return $this->hasMany(AuditChecklistEvaluation::class, 'audit_id');
    }

    public function auditFindings(): HasMany
    {
        return $this->hasMany(AuditFinding::class, 'audit_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
