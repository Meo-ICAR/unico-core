<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Collegamento tra clienti e codici OAM. */
class ClienteOam extends Model
{
    use UsesCoreConnection;

    protected $table = 'clienti_oam';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'dal' => 'date',
            'al' => 'date',
        ];
    }

    public function clienti(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'clienti_id');
    }

    public function oamCode(): BelongsTo
    {
        return $this->belongsTo(OamCode::class, 'oam_code_id');
    }
}
