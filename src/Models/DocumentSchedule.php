<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Scadenziario dei documenti: una riga per documento in scadenza, ricalcolata dal sistema. */
class DocumentSchedule extends Model
{
    use UsesCoreConnection;

    protected $table = 'document_schedules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'last_sent_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
