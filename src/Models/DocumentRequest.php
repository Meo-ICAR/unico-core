<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Richieste di documenti su una pratica o un'anagrafica, con stato e documento che le evade. */
class DocumentRequest extends Model
{
    use UsesCoreConnection;

    protected $table = 'document_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'share_with_client' => 'boolean',
            'requested_at' => 'datetime',
            'due_date' => 'date',
            'agent_notified_at' => 'datetime',
            'client_notified_at' => 'datetime',
            'completed_at' => 'datetime',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'is_required' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function requestable(): MorphTo
    {
        return $this->morphTo();
    }
}
