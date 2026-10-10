<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Registro ufficiale Reclami e Segnalazioni (OAM, IVASS, Privacy). */
class ComplaintRegistry extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'complaint_registry';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'financial_impact' => 'decimal:2',
            'deadline_at' => 'date',
            'is_extended' => 'boolean',
            'resolved_at' => 'datetime',
            'event_at' => 'datetime',
        ];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'bank_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Fornitore::class, 'agent_id');
    }

    public function complainant(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
