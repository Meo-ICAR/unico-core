<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Matrice delle regole provvigionali. Gestisce la gerarchia a cascata (Prodotto -> Sottoprodotto -> Cliente UUID) con eliminazione logica. */
class TipoProdottoSubConstraint extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'tipoprodotto_sub_constraints';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'max_debt_to_income_ratio' => 'decimal:2',
            'max_ltv_percentage' => 'decimal:2',
            'allowed_employment_types' => 'array',
            'additional_rules_json' => 'array',
        ];
    }

    public function clienti(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'clienti_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(FornitoriRole::class, 'role_id');
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
