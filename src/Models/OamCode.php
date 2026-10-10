<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Tabella di lookup globale (Senza Tenant): Ambiti operativi OAM. */
class OamCode extends Model
{
    use UsesCoreConnection;

    protected $table = 'oam_codes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_dummy' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function clientiOam(): HasMany
    {
        return $this->hasMany(ClienteOam::class, 'oam_code_id');
    }
}
