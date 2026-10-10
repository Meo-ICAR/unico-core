<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Mandati con cui una persona agisce per conto di un cliente. */
class ClientMandate extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'client_mandates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data_firma_mandato' => 'date',
            'data_scadenza_mandato' => 'date',
            'importo_richiesto_mandato' => 'decimal:2',
            'data_consegna_trasparenza' => 'date',
            'oam_delivered' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
