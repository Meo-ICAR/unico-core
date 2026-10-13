<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesOwningAppConnection;

/** Registro dettaglio delle segnalazioni di attività sospette. */
class SuspiciousActivityReport extends Model
{
    use SoftDeletes, UsesOwningAppConnection;

    protected $table = 'suspicious_activity_reports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'anomalies_codes' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }
}
