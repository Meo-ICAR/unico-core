<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Tipi di prodotto finanziario (mutuo, cessione del quinto, prestito, ...). */
class TipoProdotto extends Model
{
    use UsesCoreConnection;

    protected $table = 'tipoprodotto';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_external' => 'boolean',
            'is_active' => 'boolean',
            'is_oneclient' => 'boolean',
            'for_person' => 'boolean',
            'for_company' => 'boolean',
            'requires_mandate' => 'boolean',
            'is_third_party' => 'boolean',
        ];
    }

    public function documentRequirements(): HasMany
    {
        return $this->hasMany(DocumentRequirement::class, 'tipoprodotto_id');
    }

    public function pratiches(): HasMany
    {
        return $this->hasMany(Pratica::class, 'tipoprodotto_id');
    }

    public function provvigioniRules(): HasMany
    {
        return $this->hasMany(ProvvigioniRule::class, 'tipoprodotto_id');
    }

    public function tipoprodottoSub(): HasMany
    {
        return $this->hasMany(TipoProdottoSub::class, 'tipoprodotto_id');
    }

    public function tipoprodottoSubConstraints(): HasMany
    {
        return $this->hasMany(TipoProdottoSubConstraint::class, 'tipoprodotto_id');
    }
}
