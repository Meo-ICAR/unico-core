<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Tipo di reddito del cliente, netto o lordo (intervista preliminare). */
class IncomeType extends Model
{
    use UsesCoreConnection;

    protected $table = 'income_types';

    protected $guarded = ['id'];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'income_type_id');
    }
}
