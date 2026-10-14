<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Tabella dei ruoli e livelli della rete dei mediatori creditizi. */
class FornitoriRole extends Model
{
    use UsesCoreConnection;

    protected $table = 'fornitoriroles';

    protected $guarded = ['id'];

    public function fornitoris(): HasMany
    {
        return $this->hasMany(Fornitore::class, 'fornitorirole_id');
    }

    public function provvigioniRules(): HasMany
    {
        return $this->hasMany(ProvvigioniRule::class, 'kind_id');
    }

    public function tipoprodottoSubConstraints(): HasMany
    {
        return $this->hasMany(TipoProdottoSubConstraint::class, 'role_id');
    }
}
