<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;
use Unico\Core\Enums\SignatureRequestStatus;

/** Richieste di firma di un Document presso un provider (busta di firma). */
class SignatureRequest extends Model
{
    use UsesCoreConnection;

    protected $table = 'signature_requests';

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'status' => SignatureRequestStatus::class,
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function signatureRequestSigners(): HasMany
    {
        return $this->hasMany(SignatureRequestSigner::class, 'signature_request_id')->orderBy('position');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [SignatureRequestStatus::Pending, SignatureRequestStatus::Sent]);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
