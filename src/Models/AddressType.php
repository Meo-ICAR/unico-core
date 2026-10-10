<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Unico\Core\Concerns\UsesCoreConnection;

/** Tipologie di indirizzo (sede legale, residenza, domicilio, ...). */
class AddressType extends Model
{
    use UsesCoreConnection;

    protected $table = 'address_types';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'address_type_id');
    }
}
