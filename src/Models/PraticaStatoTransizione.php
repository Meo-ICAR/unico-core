<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Unico\Core\Concerns\UsesCoreConnection;

/** Transizioni di stato ammesse tra gli stati del workflow di una pratica. */
class PraticaStatoTransizione extends Model
{
    use UsesCoreConnection;

    protected $table = 'pratica_stati_transizioni';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function statoDa(): BelongsTo
    {
        return $this->belongsTo(PraticaStato::class, 'stato_da_id');
    }

    public function statoA(): BelongsTo
    {
        return $this->belongsTo(PraticaStato::class, 'stato_a_id');
    }
}
