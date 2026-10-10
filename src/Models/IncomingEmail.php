<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Archivio delle email lette dalle caselle IMAP. */
class IncomingEmail extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'incoming_emails';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'received_at' => 'datetime',
            'is_read' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
