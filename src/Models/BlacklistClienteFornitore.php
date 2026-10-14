<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesOwningAppConnection;

/** Divieti di contatto tra un cliente e un fornitore. */
class BlacklistClienteFornitore extends Model
{
    use UsesOwningAppConnection;

    protected $table = 'blacklist_clienti_fornitori';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data_inizio' => 'date',
            'data_fine' => 'date',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function fornitore(): BelongsTo
    {
        return $this->belongsTo(Fornitore::class, 'fornitore_id');
    }
}
