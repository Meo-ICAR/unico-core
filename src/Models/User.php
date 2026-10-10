<?php

namespace Unico\Core\Models;

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

    /** Profilo collegato: dipendente, cliente, fornitore... */
    public function profile(): MorphTo
    {
        return $this->morphTo();
    }

    public function socialiteUsers(): HasMany
    {
        return $this->hasMany(SocialiteUser::class, 'user_id');
    }
}
