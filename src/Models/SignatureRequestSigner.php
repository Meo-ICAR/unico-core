<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Firmatari di una richiesta di firma. */
class SignatureRequestSigner extends Model
{
    use UsesCoreConnection;

    protected $table = 'signature_request_signers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }
}
