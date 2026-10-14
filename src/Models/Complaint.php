<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Registro delle segnalazioni e reclami ricevuti. */
class Complaint extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'complaints';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'resolved_at' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function complaintable(): MorphTo
    {
        return $this->morphTo();
    }
}
