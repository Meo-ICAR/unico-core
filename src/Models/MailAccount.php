<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Casella email o PEC: IMAP in ingresso, SMTP in uscita, con Basic Auth o OAuth 2.0. */
class MailAccount extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'mail_accounts';

    protected $guarded = ['id'];

    protected $attributes = [
        'auth_type' => 'password',
        'protocol' => 'imap',
        'type' => 'generale',
        'is_active' => true,
    ];

    protected $hidden = ['imap_password', 'smtp_password', 'access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'imap_port' => 'integer',
            'imap_password' => 'encrypted',
            'smtp_port' => 'integer',
            'smtp_password' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'is_pec' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** Proprietario della casella: utente, dipendente o company. */
    public function mailable(): MorphTo
    {
        return $this->morphTo();
    }

    public function emails(): HasMany
    {
        return $this->hasMany(IncomingEmail::class, 'mail_account_id');
    }

    /** Solo le caselle di quel tipo (`MailType`: sollecito, processo, generale). */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Il token OAuth2 è scaduto o scade nei prossimi 5 minuti. */
    public function isTokenExpired(): bool
    {
        if ($this->auth_type !== 'oauth2' || ! $this->token_expires_at) {
            return false;
        }

        return $this->token_expires_at->copy()->subMinutes(5)->isPast();
    }

    /** La casella ha credenziali SMTP proprie per inviare come `email_address`. */
    public function hasSmtpConfigured(): bool
    {
        return $this->auth_type === 'password'
            && filled($this->smtp_host)
            && filled($this->smtp_password);
    }
}
