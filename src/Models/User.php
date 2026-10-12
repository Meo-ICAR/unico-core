<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Unico\Core\Concerns\UsesCoreConnection;

/** Utente che accede alle app della suite. Una sola tabella `users` per tutte le app. */
class User extends Authenticatable
{
    use Notifiable, SoftDeletes, UsesCoreConnection;

    protected $table = 'users';

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'microsoft_token', 'microsoft_refresh_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Azienda (tenant) principale dell'utente; nulla per gli utenti di piattaforma. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** Profilo collegato: dipendente, cliente, fornitore... */
    public function profile(): MorphTo
    {
        return $this->morphTo();
    }

    public function socialiteUsers(): HasMany
    {
        return $this->hasMany(SocialiteUser::class, 'user_id');
    }

    public function employee(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    public function client(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Client::class, 'user_id');
    }

    public function fornitore(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Fornitore::class, 'user_id');
    }

    /** Modello di riferimento associato in base al ruolo (employee, client, fornitore). */
    public function referenceModel(): ?\Illuminate\Database\Eloquent\Model
    {
        return match ($this->role) {
            'employee' => $this->employee,
            'client' => $this->client,
            'fornitore' => $this->fornitore,
            default => null,
        };
    }
}
