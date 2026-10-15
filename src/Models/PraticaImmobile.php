<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Unico\Core\Concerns\UsesCoreConnection;

/** Immobile oggetto o garanzia di un mutuo. L'indirizzo è un {@see Address} di tipo Località. */
class PraticaImmobile extends Model
{
    use UsesCoreConnection;

    protected $table = 'pratica_immobili';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valore_stimato' => 'decimal:2', 'prezzo_acquisto' => 'decimal:2'];
    }

    public function getMorphClass(): string
    {
        return 'pratica_immobile';
    }

    public function pratica(): BelongsTo
    {
        return $this->belongsTo(Pratica::class, 'pratica_id');
    }

    public function address(): MorphOne
    {
        return $this->morphOne(Address::class, 'addressable');
    }

    /** Finanziamento richiesto sul valore dell'immobile, in percentuale. */
    public function ltv(?float $importo): ?float
    {
        $valore = (float) $this->valore_stimato;

        return $importo && $valore > 0 ? round($importo / $valore * 100, 1) : null;
    }
}
