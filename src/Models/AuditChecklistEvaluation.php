<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Valutazione delle voci della checklist durante un audit. */
class AuditChecklistEvaluation extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'audit_checklist_evaluations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_vendor_scope' => 'boolean',
            'is_optional' => 'boolean',
            'verified_at' => 'date',
            'next_review_at' => 'date',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class, 'audit_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
