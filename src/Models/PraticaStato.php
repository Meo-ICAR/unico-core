<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Unico\Core\Concerns\UsesCoreConnection;

/** Stati del workflow di una pratica di finanziamento. */
class PraticaStato extends Model
{
    use SoftDeletes, UsesCoreConnection;

    protected $table = 'pratica_stati';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_rejected' => 'boolean',
            'is_sended' => 'boolean',
            'is_approved' => 'boolean',
            'is_erogated' => 'boolean',
            'is_working' => 'boolean',
            'is_estingued' => 'boolean',
        ];
    }

    public function pratiches(): HasMany
    {
        return $this->hasMany(Pratica::class, 'pratica_stato_id');
    }
}
