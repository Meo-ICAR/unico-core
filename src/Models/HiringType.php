<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Tipo di assunzione del cliente (intervista preliminare). */
class HiringType extends Model
{
    use UsesCoreConnection;

    protected $table = 'hiring_types';

    protected $guarded = ['id'];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'hiring_type_id');
    }
}
