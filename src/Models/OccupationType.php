<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Tipo di occupazione del cliente (intervista preliminare). */
class OccupationType extends Model
{
    use UsesCoreConnection;

    protected $table = 'occupation_types';

    protected $guarded = ['id'];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'occupation_type_id');
    }
}
