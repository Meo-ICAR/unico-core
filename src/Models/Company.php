<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Il tenant: l'azienda che usa la suite. */
class Company extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'companies';

    protected $guarded = ['id'];

    protected $hidden = ['db_password'];

    protected function casts(): array
    {
        return [
            'db_password' => 'encrypted',
            'oam_at' => 'date',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
