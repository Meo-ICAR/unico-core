<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Registro della formazione di dipendenti e collaboratori, con esito e attestato. */
class TrainingRecord extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'training_records';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'training_date' => 'date',
            'expiry_date' => 'date',
            'hours' => 'decimal:1',
            'score' => 'decimal:2',
            'certificate_issued' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function trainable(): MorphTo
    {
        return $this->morphTo();
    }
}
