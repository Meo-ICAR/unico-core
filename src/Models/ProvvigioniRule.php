<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Matrice delle regole provvigionali. Gestisce la gerarchia a cascata (Prodotto -> Sottoprodotto -> Banca). */
class ProvvigioniRule extends Model
{
    use UsesCoreConnection;

    protected $table = 'provvigioni_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'coordinamento' => 'boolean',
            'iscliente' => 'boolean',
            'value' => 'decimal:4',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function clienti(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'clienti_id');
    }

    public function fornitori(): BelongsTo
    {
        return $this->belongsTo(Fornitore::class, 'fornitori_id');
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(FornitoriRole::class, 'kind_id');
    }

    public function tipoprodotto(): BelongsTo
    {
        return $this->belongsTo(TipoProdotto::class, 'tipoprodotto_id');
    }

    public function tipoprodottoSub(): BelongsTo
    {
        return $this->belongsTo(TipoProdottoSub::class, 'tipoprodotto_sub_id');
    }
}
