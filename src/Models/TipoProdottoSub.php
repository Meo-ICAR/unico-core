<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Sottotipi di prodotto finanziario, con i relativi vincoli. */
class TipoProdottoSub extends Model
{
    use UsesCoreConnection;

    protected $table = 'tipoprodotto_sub';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tipoprodotto(): BelongsTo
    {
        return $this->belongsTo(TipoProdotto::class, 'tipoprodotto_id');
    }

    public function documentRequirements(): HasMany
    {
        return $this->hasMany(DocumentRequirement::class, 'tipoprodotto_sub_id');
    }

    public function provvigioniRules(): HasMany
    {
        return $this->hasMany(ProvvigioniRule::class, 'tipoprodotto_sub_id');
    }

    public function tipoprodottoSubConstraints(): HasMany
    {
        return $this->hasMany(TipoProdottoSubConstraint::class, 'tipoprodotto_sub_id');
    }
}
