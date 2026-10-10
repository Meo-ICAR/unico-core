<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Caselle email e PEC: IMAP in ingresso e SMTP in uscita, con Basic Auth o OAuth 2.0. */
class MailAccount extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'mail_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['imap_password', 'smtp_password', 'access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'is_pec' => 'boolean',
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'is_active' => 'boolean',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function incomingEmails(): HasMany
    {
        return $this->hasMany(IncomingEmail::class, 'mail_account_id');
    }

    public function mailable(): MorphTo
    {
        return $this->morphTo();
    }
}
