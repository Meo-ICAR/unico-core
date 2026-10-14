<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Account OAuth (Google, Microsoft) collegati agli utenti, con i token di accesso. */
class SocialiteUser extends Model
{
    use UsesCoreConnection;

    protected $table = 'socialite_users';

    protected $guarded = ['id'];

    protected $hidden = ['token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
